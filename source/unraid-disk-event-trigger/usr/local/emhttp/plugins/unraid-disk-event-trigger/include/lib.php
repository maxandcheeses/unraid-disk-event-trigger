<?php
/**
 * unraid-disk-event-trigger shared library:
 * config I/O, disk temp reading, rule evaluation, Tasmota HTTP/MQTT dispatch.
 */

define('HTT_CFG_DIR', '/boot/config/plugins/unraid-disk-event-trigger');
define('HTT_RULES_FILE', HTT_CFG_DIR . '/rules.json');
define('HTT_STATE_FILE', '/var/local/emhttp/unraid-disk-event-trigger.state.json');
define('HTT_LOG_FILE', '/var/log/unraid-disk-event-trigger.log');

function htt_log($msg) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    @file_put_contents(HTT_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    // keep log bounded
    if (filesize(HTT_LOG_FILE) > 512 * 1024) {
        $lines = file(HTT_LOG_FILE);
        file_put_contents(HTT_LOG_FILE, implode('', array_slice($lines, -2000)));
    }
}

function htt_default_config() {
    return [
        'enabled' => true,
        'poll_interval' => 60,
        'connections' => [],
        'rules' => [],
    ];
}

/** Find a saved global connection by id and type ('http', 'mqtt', or 'webhook'). */
function htt_find_connection($config, $type, $id) {
    if (empty($id)) return null;
    foreach (($config['connections'] ?? []) as $c) {
        if (($c['id'] ?? '') === $id && ($c['type'] ?? '') === $type) return $c;
    }
    return null;
}

/**
 * Resolve a rule's protocol block ($rule['http']/['mqtt']/['webhook']),
 * filling in connection-level fields (host/port/auth/etc.) from a saved
 * global connection when the rule references one via connection_id.
 * Rule-level fields (which only exist for a "Custom" rule not tied to a
 * saved connection) always win if both are present.
 */
function htt_resolve_protocol($config, $rule, $type) {
    $fields = $rule[$type] ?? [];
    $conn = htt_find_connection($config, $type, $fields['connection_id'] ?? '');
    if (!$conn) return $fields;
    foreach ($conn as $k => $v) {
        if (in_array($k, ['id', 'name', 'type'], true)) continue;
        if (!array_key_exists($k, $fields) || $fields[$k] === '' || $fields[$k] === null) {
            $fields[$k] = $v;
        }
    }
    return $fields;
}

function htt_load_config() {
    if (!file_exists(HTT_RULES_FILE)) {
        return htt_default_config();
    }
    $json = @file_get_contents(HTT_RULES_FILE);
    $data = json_decode($json, true);
    if (!is_array($data)) return htt_default_config();
    $data += htt_default_config();
    return $data;
}

function htt_save_config($config) {
    if (!is_dir(HTT_CFG_DIR)) mkdir(HTT_CFG_DIR, 0755, true);
    file_put_contents(HTT_RULES_FILE, json_encode($config, JSON_PRETTY_PRINT));
}

/** Returns a list of human-readable error strings; empty means valid. */
function htt_validate_config($config) {
    $errors = [];
    if (!is_array($config)) return ['config must be a mapping with poll_interval/rules keys'];

    if (isset($config['enabled']) && !is_bool($config['enabled'])) {
        $errors[] = 'enabled must be true or false';
    }
    if (isset($config['poll_interval']) && !is_numeric($config['poll_interval'])) {
        $errors[] = 'poll_interval must be a number';
    }
    if (isset($config['rules']) && !is_array($config['rules'])) {
        $errors[] = 'rules must be a list';
        return $errors;
    }
    if (isset($config['connections']) && !is_array($config['connections'])) {
        $errors[] = 'connections must be a list';
        return $errors;
    }

    foreach (($config['connections'] ?? []) as $idx => $conn) {
        $label = "connection #" . ($idx + 1) . (is_array($conn) && !empty($conn['name']) ? " ('{$conn['name']}')" : '');
        if (!is_array($conn)) { $errors[] = "$label must be a mapping"; continue; }
        if (empty($conn['name'])) $errors[] = "$label: name is required";
        if (!in_array($conn['type'] ?? '', ['http', 'mqtt', 'webhook'], true)) {
            $errors[] = "$label: type must be 'http', 'mqtt', or 'webhook'";
        }
    }

    foreach (($config['rules'] ?? []) as $idx => $rule) {
        $label = "rule #" . ($idx + 1) . (is_array($rule) && !empty($rule['name']) ? " ('{$rule['name']}')" : '');
        if (!is_array($rule)) { $errors[] = "$label must be a mapping"; continue; }
        if (isset($rule['action_direction']) && !in_array($rule['action_direction'], ['on', 'off'], true)) {
            $errors[] = "$label: action_direction must be 'on' or 'off'";
        }
        if (isset($rule['conditions']) && !is_array($rule['conditions'])) {
            $errors[] = "$label: conditions must be a list";
        } else {
            foreach (($rule['conditions'] ?? []) as $cidx => $cond) {
                $clabel = "$label, condition #" . ($cidx + 1);
                if (!is_array($cond)) { $errors[] = "$clabel must be a mapping"; continue; }
                if (isset($cond['trigger_type']) && !in_array($cond['trigger_type'], ['temp', 'usage', 'parity_check', 'rebuild'], true)) {
                    $errors[] = "$clabel: trigger_type must be 'temp', 'usage', 'parity_check', or 'rebuild'";
                }
                if (isset($cond['direction']) && !in_array($cond['direction'], ['on', 'off'], true)) {
                    $errors[] = "$clabel: direction must be 'on' or 'off'";
                }
                if (isset($cond['join']) && !in_array($cond['join'], ['and', 'or'], true)) {
                    $errors[] = "$clabel: join must be 'and' or 'or'";
                }
                if (isset($cond['threshold']) && !is_numeric($cond['threshold'])) $errors[] = "$clabel: threshold must be a number";
                if (isset($cond['disks']) && !is_array($cond['disks'])) $errors[] = "$clabel: disks must be a list";
                if (isset($cond['aggregate']) && !in_array($cond['aggregate'], ['max', 'avg', 'min'], true)) {
                    $errors[] = "$clabel: aggregate must be 'max', 'avg', or 'min'";
                }
            }
        }
        if (isset($rule['delay_seconds']) && (!is_numeric($rule['delay_seconds']) || $rule['delay_seconds'] < 0)) $errors[] = "$label: delay_seconds must be a non-negative number";
        if (isset($rule['reset_rules']) && !is_array($rule['reset_rules'])) $errors[] = "$label: reset_rules must be a list";
        if (isset($rule['protocol']) && !in_array($rule['protocol'], ['http', 'mqtt', 'webhook'], true)) {
            $errors[] = "$label: protocol must be 'http', 'mqtt', or 'webhook'";
        }
        if (isset($rule['http']) && !is_array($rule['http'])) $errors[] = "$label: http must be a mapping";
        if (isset($rule['http']['state']) && !in_array($rule['http']['state'], ['on', 'off'], true)) {
            $errors[] = "$label: http.state must be 'on' or 'off'";
        }
        if (isset($rule['mqtt']) && !is_array($rule['mqtt'])) $errors[] = "$label: mqtt must be a mapping";
        if (isset($rule['mqtt']['tls']) && !is_bool($rule['mqtt']['tls'])) $errors[] = "$label: mqtt.tls must be true or false";
        if (isset($rule['mqtt']['insecure_tls']) && !is_bool($rule['mqtt']['insecure_tls'])) $errors[] = "$label: mqtt.insecure_tls must be true or false";
        if (isset($rule['webhook'])) {
            if (!is_array($rule['webhook'])) {
                $errors[] = "$label: webhook must be a mapping";
            } else {
                $w = $rule['webhook'];
                if (isset($w['method']) && !in_array(strtoupper($w['method']), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    $errors[] = "$label: webhook.method must be one of GET, POST, PUT, PATCH, DELETE";
                }
                if (!empty($w['url']) && !preg_match('#^https?://#i', $w['url'])) {
                    $errors[] = "$label: webhook.url must start with http:// or https://";
                }
            }
        }
    }
    return $errors;
}

/** Assigns rule IDs, persists, and (re)starts/stops the poller per config.enabled. Caller must validate first. */
function htt_apply_config($config) {
    $config['rules'] = $config['rules'] ?? [];
    foreach ($config['rules'] as &$rule) {
        if (empty($rule['id'])) $rule['id'] = bin2hex(random_bytes(6));
    }
    unset($rule);
    htt_save_config($config);
    $cmd = ($config['enabled'] ?? true) ? 'restart' : 'stop';
    shell_exec('/etc/rc.d/rc.unraid-disk-event-trigger ' . $cmd . ' > /dev/null 2>&1 &');
    return ['ok' => true];
}

/**
 * Minimal dependency-free array -> YAML dumper, sufficient for this
 * plugin's own config shape (nested assoc arrays, lists, scalars). Not a
 * general-purpose YAML emitter - just enough to let users back up/inspect
 * their rules.json in a friendlier format. Avoids requiring the PHP `yaml`
 * extension, which Unraid doesn't ship by default.
 */
function htt_to_yaml($data, $indent = 0) {
    $pad = str_repeat('  ', $indent);
    $out = '';
    $isList = is_array($data) && array_keys($data) === range(0, count($data) - 1);

    if ($isList) {
        if (empty($data)) return "{$pad}[]\n";
        foreach ($data as $v) {
            if (is_array($v)) {
                $out .= "{$pad}-\n" . htt_to_yaml($v, $indent + 1);
            } else {
                $out .= "{$pad}- " . htt_yaml_scalar($v) . "\n";
            }
        }
        return $out;
    }

    if (is_array($data)) {
        if (empty($data)) return "{$pad}{}\n";
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $sub = htt_to_yaml($v, $indent + 1);
                $empty = (is_array($v) && empty($v));
                $out .= "{$pad}{$k}:" . ($empty ? " " . trim($sub) . "\n" : "\n" . $sub);
            } else {
                $out .= "{$pad}{$k}: " . htt_yaml_scalar($v) . "\n";
            }
        }
        return $out;
    }

    return "{$pad}" . htt_yaml_scalar($data) . "\n";
}

function htt_yaml_scalar($v) {
    if ($v === null) return 'null';
    if (is_bool($v)) return $v ? 'true' : 'false';
    if (is_int($v) || is_float($v)) return (string)$v;
    $s = (string)$v;
    if ($s === '' || preg_match('/^[\s]|[\s]$|[:#\[\]{}&*!|>\'"%@`]|^(true|false|null|yes|no|on|off|~)$/i', $s) || is_numeric($s)) {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }
    return $s;
}

class HttYamlError extends Exception {}

/**
 * Minimal dependency-free YAML -> array parser, matching the restricted
 * subset htt_to_yaml() emits (2-space indent, block maps/sequences,
 * quoted/plain scalars) plus reasonable hand edits in the same style.
 * Not a general YAML parser - throws HttYamlError on anything it can't
 * confidently interpret, rather than silently guessing.
 */
function htt_from_yaml($text) {
    $rawLines = explode("\n", str_replace("\r\n", "\n", $text));
    $lines = []; // each: ['indent' => int, 'content' => string, 'no' => original line number]
    foreach ($rawLines as $no => $raw) {
        $trimmed = ltrim($raw, ' ');
        $indent = strlen($raw) - strlen($trimmed);
        $trimmed = rtrim($trimmed);
        if ($trimmed === '' || $trimmed[0] === '#') continue;
        $lines[] = ['indent' => $indent, 'content' => $trimmed, 'no' => $no + 1];
    }
    if (empty($lines)) return [];
    $i = 0;
    $result = htt_yaml_parse_block($lines, $i, $lines[0]['indent']);
    if ($i < count($lines)) {
        throw new HttYamlError("unexpected indentation at line {$lines[$i]['no']}: \"{$lines[$i]['content']}\"");
    }
    return $result;
}

function htt_yaml_parse_block(&$lines, &$i, $blockIndent) {
    if ($i >= count($lines) || $lines[$i]['indent'] !== $blockIndent) {
        throw new HttYamlError("expected content at indent $blockIndent");
    }
    if ($lines[$i]['content'] === '-' || strncmp($lines[$i]['content'], '- ', 2) === 0) {
        return htt_yaml_parse_sequence($lines, $i, $blockIndent);
    }
    if (preg_match('/^[A-Za-z0-9_]+:/', $lines[$i]['content'])) {
        return htt_yaml_parse_mapping($lines, $i, $blockIndent);
    }
    if ($lines[$i]['content'] === '[]' || $lines[$i]['content'] === '{}') {
        $i++;
        return [];
    }
    throw new HttYamlError("line {$lines[$i]['no']}: can't parse \"{$lines[$i]['content']}\"");
}

function htt_yaml_parse_sequence(&$lines, &$i, $blockIndent) {
    $result = [];
    while ($i < count($lines) && $lines[$i]['indent'] === $blockIndent
        && ($lines[$i]['content'] === '-' || strncmp($lines[$i]['content'], '- ', 2) === 0)) {
        $rest = trim(substr($lines[$i]['content'], 1));
        $dashLine = $lines[$i];
        $i++;
        if ($rest === '') {
            if ($i < count($lines) && $lines[$i]['indent'] > $blockIndent) {
                $result[] = htt_yaml_parse_block($lines, $i, $lines[$i]['indent']);
            } else {
                $result[] = null;
            }
        } elseif (preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', $rest, $m)) {
            // "- key: value" shorthand for a map whose first key is inline with the dash.
            $childIndent = $dashLine['indent'] + 2;
            $map = [];
            $map[$m[1]] = htt_yaml_parse_scalar_or_nested($lines, $i, $m[2], $childIndent);
            while ($i < count($lines) && $lines[$i]['indent'] === $childIndent
                && preg_match('/^[A-Za-z0-9_]+:/', $lines[$i]['content'])) {
                $more = htt_yaml_parse_mapping($lines, $i, $childIndent);
                $map = array_merge($map, $more);
            }
            $result[] = $map;
        } else {
            $result[] = htt_yaml_parse_scalar($rest);
        }
    }
    return $result;
}

function htt_yaml_parse_mapping(&$lines, &$i, $blockIndent) {
    $result = [];
    while ($i < count($lines) && $lines[$i]['indent'] === $blockIndent
        && preg_match('/^([A-Za-z0-9_]+):\s*(.*)$/', $lines[$i]['content'], $m)) {
        $i++;
        $result[$m[1]] = htt_yaml_parse_scalar_or_nested($lines, $i, $m[2], $blockIndent);
    }
    return $result;
}

/** Handle a "key: <rest>" line where <rest> may be empty (nested block follows), [], {}, or a scalar. */
function htt_yaml_parse_scalar_or_nested(&$lines, &$i, $rest, $parentIndent) {
    $rest = trim($rest);
    if ($rest === '') {
        if ($i < count($lines) && $lines[$i]['indent'] > $parentIndent) {
            return htt_yaml_parse_block($lines, $i, $lines[$i]['indent']);
        }
        return null;
    }
    if ($rest === '[]' || $rest === '{}') return [];
    return htt_yaml_parse_scalar($rest);
}

function htt_yaml_parse_scalar($s) {
    $s = trim($s);
    if (strlen($s) >= 2 && $s[0] === '"' && substr($s, -1) === '"') {
        $inner = substr($s, 1, -1);
        return str_replace(['\\"', '\\\\'], ['"', '\\'], $inner);
    }
    if (strcasecmp($s, 'null') === 0 || $s === '~') return null;
    if (strcasecmp($s, 'true') === 0) return true;
    if (strcasecmp($s, 'false') === 0) return false;
    if (is_numeric($s)) return strpos($s, '.') !== false ? (float)$s : (int)$s;
    return $s;
}

function htt_load_state() {
    if (!file_exists(HTT_STATE_FILE)) return [];
    $data = json_decode(@file_get_contents(HTT_STATE_FILE), true);
    return is_array($data) ? $data : [];
}

function htt_save_state($state) {
    $dir = dirname(HTT_STATE_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents(HTT_STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
}

/**
 * Enumerate array/cache disks known to Unraid via disks.ini, which already
 * tracks spin-down state. We avoid querying spun-down disks with smartctl
 * so we don't wake sleeping drives just to poll temperature.
 */
function htt_list_disks() {
    $ini = '/var/local/emhttp/disks.ini';
    $disks = [];
    if (file_exists($ini)) {
        $all = parse_ini_file($ini, true);
        foreach ($all as $name => $d) {
            if (empty($d['device'])) continue;
            if (!empty($d['type']) && !in_array($d['type'], ['Data', 'Parity', 'Cache'])) continue;
            $fsSize = isset($d['fsSize']) ? floatval($d['fsSize']) : null; // KB, from Unraid's own filesystem stat cache
            $fsFree = isset($d['fsFree']) ? floatval($d['fsFree']) : null;
            $disks[$name] = [
                'name' => $name,
                'device' => $d['device'],
                'spundown' => !empty($d['spundown']) && $d['spundown'] == '1',
                'temp' => isset($d['temp']) ? intval($d['temp']) : null,
                'fs_size' => $fsSize,
                'fs_free' => $fsFree,
            ];
        }
    }
    return $disks;
}

/**
 * Get current temp (Celsius) for a disk. Prefers Unraid's own cached temp
 * (already populated by emhttp and safe for spun-down disks). Falls back to
 * smartctl -A for a live reading when Unraid hasn't cached one and the disk
 * is confirmed spun up.
 */
function htt_disk_temp($disk) {
    if ($disk['temp'] !== null && $disk['temp'] !== '' && $disk['temp'] != '*') {
        return $disk['temp'];
    }
    if ($disk['spundown']) {
        return null; // never wake a sleeping disk just to poll temp
    }
    $dev = escapeshellarg('/dev/' . ltrim($disk['device'], '/'));
    $out = [];
    exec("smartctl -A -n standby $dev 2>/dev/null", $out, $rc);
    $text = implode("\n", $out);
    if (preg_match('/^194\s+Temperature_Celsius.*?\s(\d+)(\s|$)/m', $text, $m)) {
        return intval($m[1]);
    }
    if (preg_match('/^190\s+Airflow_Temperature_Cel.*?\s(\d+)(\s|$)/m', $text, $m)) {
        return intval($m[1]);
    }
    if (preg_match('/Temperature:\s+(\d+)\s+Celsius/', $text, $m)) {
        return intval($m[1]); // NVMe
    }
    if (preg_match('/Current Drive Temperature:\s+(\d+)\s+C/', $text, $m)) {
        return intval($m[1]); // SAS
    }
    return null;
}

/**
 * Get current used-space percentage for a disk, from Unraid's own cached
 * filesystem stats (disks.ini fsSize/fsFree) - this is static filesystem
 * metadata, not a live query, so unlike temp it's safe to read even for
 * spun-down disks without waking them.
 */
function htt_disk_usage_pct($disk) {
    $size = $disk['fs_size'] ?? null;
    $free = $disk['fs_free'] ?? null;
    if ($size === null || $free === null || $size <= 0) return null;
    return round((($size - $free) / $size) * 100, 1);
}

/** Aggregate a list of temps according to the rule's aggregate mode. */
function htt_aggregate($temps, $mode) {
    $temps = array_values(array_filter($temps, fn($t) => $t !== null));
    if (empty($temps)) return null;
    switch ($mode) {
        case 'min': return min($temps);
        case 'avg': return array_sum($temps) / count($temps);
        case 'max':
        default: return max($temps);
    }
}

/** Send an HTTP command to a Tasmota device: base_url like http://192.168.1.50 */
function htt_send_http($config, $rule) {
    $h = htt_resolve_protocol($config, $rule, 'http');
    $base = rtrim($h['base_url'] ?? '', '/');
    if ($base === '') return false;
    $idx = $h['device_index'] ?? '';
    $cmndPrefix = $idx !== '' ? "Power{$idx}" : 'Power';
    // 'state' is the Tasmota HTTP command itself (Power On/Off) - unlike
    // MQTT/webhook, Tasmota's fixed query-string API needs to know which
    // one to send, and falls back to the pre-v2026.08.03.22 rule-level
    // action_direction for rules saved before this field existed.
    $state = $h['state'] ?? ($rule['action_direction'] ?? 'on');
    $cmnd = ($state !== 'off') ? "{$cmndPrefix}%20On" : "{$cmndPrefix}%20Off";
    $url = "$base/cm?cmnd=$cmnd";
    if (!empty($h['username'])) {
        $url .= '&user=' . urlencode($h['username']) . '&password=' . urlencode($h['password'] ?? '');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $result = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($result === false) {
        htt_log("HTTP send failed for rule '{$rule['name']}': $err");
        return false;
    }
    htt_log("HTTP send OK for rule '{$rule['name']}': $url -> $result");
    return true;
}

/**
 * Minimal MQTT v3.1.1 client: connects, publishes one QoS0 message, disconnects.
 * Implemented raw (no external mosquitto_pub dependency) so it also works
 * against zigbee2mqtt topics/payloads.
 */
const HTT_MQTT_CONNACK_REASONS = [
    0 => 'connection accepted',
    1 => 'refused: unacceptable protocol version',
    2 => 'refused: identifier rejected',
    3 => 'refused: server unavailable',
    4 => 'refused: bad username or password',
    5 => 'refused: not authorized',
];

/**
 * Open a socket to the broker and complete the MQTT CONNECT/CONNACK
 * handshake. Returns ['ok'=>bool, 'sock'=>resource|null, 'error'=>string].
 * Caller owns the socket on success and must fclose() it when done.
 */
function htt_mqtt_connect($host, $port, $clientId, $username, $password, $timeout = 5, $tls = false, $insecureTls = false) {
    $errno = 0; $errstr = '';
    $scheme = $tls ? 'ssl' : 'tcp';
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => !$insecureTls,
            'verify_peer_name' => !$insecureTls,
        ],
    ]);
    $sock = @stream_socket_client("$scheme://$host:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
    if (!$sock) {
        return ['ok' => false, 'sock' => null, 'error' => "connect failed: $errstr"];
    }
    stream_set_timeout($sock, $timeout);

    $protoName = htt_mqtt_str('MQTT');
    $protoLevel = chr(4);
    $connectFlags = 0x02; // clean session
    $hasAuth = ($username !== '' && $username !== null);
    if ($hasAuth) $connectFlags |= 0x80 | (($password !== '' && $password !== null) ? 0x40 : 0);
    $keepAlive = pack('n', 30);

    $payloadStr = htt_mqtt_str($clientId);
    if ($hasAuth) {
        $payloadStr .= htt_mqtt_str($username);
        if ($password !== '' && $password !== null) $payloadStr .= htt_mqtt_str($password);
    }
    $varHeader = $protoName . $protoLevel . chr($connectFlags) . $keepAlive;
    $body = $varHeader . $payloadStr;
    $connectPkt = chr(0x10) . htt_mqtt_len(strlen($body)) . $body;
    fwrite($sock, $connectPkt);

    $connack = fread($sock, 4);
    if (strlen($connack) < 4 || ord($connack[0]) >> 4 !== 2) {
        fclose($sock);
        return ['ok' => false, 'sock' => null, 'error' => 'no/invalid CONNACK received (wrong port, not an MQTT broker, or connection dropped)'];
    }
    $code = ord($connack[3]);
    if ($code !== 0) {
        fclose($sock);
        $reason = HTT_MQTT_CONNACK_REASONS[$code] ?? "unknown reason code $code";
        return ['ok' => false, 'sock' => null, 'error' => $reason];
    }

    return ['ok' => true, 'sock' => $sock, 'error' => ''];
}

function htt_mqtt_publish($host, $port, $clientId, $username, $password, $topic, $payload, $timeout = 5, $tls = false, $insecureTls = false) {
    $conn = htt_mqtt_connect($host, $port, $clientId, $username, $password, $timeout, $tls, $insecureTls);
    if (!$conn['ok']) {
        htt_log("MQTT connect failed to $host:$port - {$conn['error']}");
        return false;
    }
    $sock = $conn['sock'];

    // --- PUBLISH packet (QoS 0) ---
    $body = htt_mqtt_str($topic) . $payload;
    $pubPkt = chr(0x30) . htt_mqtt_len(strlen($body)) . $body;
    fwrite($sock, $pubPkt);

    // --- DISCONNECT ---
    fwrite($sock, chr(0xE0) . chr(0x00));
    fclose($sock);
    return true;
}

/**
 * Test-only: verify we can reach the broker and authenticate, without
 * publishing anything. Returns ['ok'=>bool, 'error'=>string].
 */
function htt_mqtt_test_connection($host, $port, $username, $password, $timeout = 5, $tls = false, $insecureTls = false) {
    if ($host === '') return ['ok' => false, 'error' => 'no broker host configured'];
    $clientId = 'httrigger-test-' . substr(md5(microtime()), 0, 8);
    $conn = htt_mqtt_connect($host, $port, $clientId, $username, $password, $timeout, $tls, $insecureTls);
    if ($conn['ok']) {
        fwrite($conn['sock'], chr(0xE0) . chr(0x00)); // DISCONNECT
        fclose($conn['sock']);
    }
    return ['ok' => $conn['ok'], 'error' => $conn['error']];
}

/**
 * Test-only: verify a Tasmota HTTP connection is reachable and (if
 * configured) authenticated, without changing any relay state. Returns
 * ['ok'=>bool, 'error'=>string].
 */
function htt_http_test_connection($baseUrl, $username, $password) {
    $base = rtrim($baseUrl ?? '', '/');
    if ($base === '') return ['ok' => false, 'error' => 'no base URL configured'];
    $url = "$base/cm?cmnd=" . urlencode('Status');
    if (!empty($username)) {
        $url .= '&user=' . urlencode($username) . '&password=' . urlencode($password ?? '');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $result = curl_exec($ch);
    $err = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($result === false) return ['ok' => false, 'error' => $err];
    if ($status >= 400) return ['ok' => false, 'error' => "device responded with HTTP $status"];
    return ['ok' => true, 'error' => ''];
}

/**
 * Test a saved global connection (from the Connections section, not a
 * rule) for basic reachability/auth. Webhook connections have no fixed
 * endpoint of their own (the URL lives on each rule), so there's nothing
 * meaningful to test at the connection level.
 */
function htt_test_connection($conn) {
    $type = $conn['type'] ?? '';
    if ($type === 'mqtt') {
        return htt_mqtt_test_connection($conn['host'] ?? '', intval($conn['port'] ?? 1883), $conn['username'] ?? '', $conn['password'] ?? '', 5, !empty($conn['tls']), !empty($conn['insecure_tls']));
    }
    if ($type === 'http') {
        return htt_http_test_connection($conn['base_url'] ?? '', $conn['username'] ?? '', $conn['password'] ?? '');
    }
    if ($type === 'webhook') {
        return ['ok' => false, 'error' => "webhook connections don't have a fixed endpoint to test - use a rule's own \"Check Device State\" or Test button instead"];
    }
    return ['ok' => false, 'error' => 'unknown connection type'];
}

/**
 * Query a Tasmota device's actual current relay state via its HTTP status
 * API (does not change anything). Returns
 * ['ok'=>bool, 'state'=>'on'|'off'|null, 'raw'=>string, 'error'=>string].
 */
function htt_query_http_state($config, $rule) {
    $h = htt_resolve_protocol($config, $rule, 'http');
    $base = rtrim($h['base_url'] ?? '', '/');
    if ($base === '') return ['ok' => false, 'state' => null, 'raw' => '', 'error' => 'no base URL configured'];
    $idx = $h['device_index'] ?? '';
    $cmndPrefix = $idx !== '' ? "Power{$idx}" : 'Power';
    $url = "$base/cm?cmnd=" . urlencode($cmndPrefix);
    if (!empty($h['username'])) {
        $url .= '&user=' . urlencode($h['username']) . '&password=' . urlencode($h['password'] ?? '');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $result = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($result === false) {
        return ['ok' => false, 'state' => null, 'raw' => '', 'error' => "request failed: $err"];
    }
    $state = null;
    $data = json_decode($result, true);
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            if (stripos($k, 'POWER') === 0 && is_string($v)) {
                $state = strcasecmp($v, 'ON') === 0 ? 'on' : (strcasecmp($v, 'OFF') === 0 ? 'off' : null);
                break;
            }
        }
    }
    return ['ok' => true, 'state' => $state, 'raw' => $result, 'error' => ''];
}

/**
 * Subscribe (QoS0) and wait for a single message on an MQTT state topic,
 * without publishing anything. Returns
 * ['ok'=>bool, 'payload'=>string|null, 'topic'=>string|null, 'error'=>string].
 */
function htt_mqtt_read_one($host, $port, $username, $password, $topic, $timeout = 4, $triggerTopic = null, $triggerPayload = null, $tls = false, $insecureTls = false) {
    if ($host === '') return ['ok' => false, 'payload' => null, 'topic' => null, 'error' => 'no broker host configured'];
    if ($topic === '') return ['ok' => false, 'payload' => null, 'topic' => null, 'error' => 'no state topic configured'];

    $clientId = 'httrigger-state-' . substr(md5(microtime()), 0, 8);
    $conn = htt_mqtt_connect($host, $port, $clientId, $username, $password, $timeout, $tls, $insecureTls);
    if (!$conn['ok']) return ['ok' => false, 'payload' => null, 'topic' => null, 'error' => $conn['error']];
    $sock = $conn['sock'];
    stream_set_timeout($sock, $timeout);

    $body = pack('n', 1) . htt_mqtt_str($topic) . chr(0); // SUBSCRIBE, packet id 1, QoS 0
    fwrite($sock, chr(0x82) . htt_mqtt_len(strlen($body)) . $body);

    if ($triggerTopic !== null && $triggerTopic !== '') {
        // Many devices (e.g. zigbee2mqtt) don't retain state and only
        // report on change; publishing to their .../get topic asks them
        // to report right now instead of waiting on luck.
        $pubBody = htt_mqtt_str($triggerTopic) . ($triggerPayload ?? '');
        fwrite($sock, chr(0x30) . htt_mqtt_len(strlen($pubBody)) . $pubBody);
    }

    $payload = null;
    $recvTopic = null;
    $deadline = microtime(true) + $timeout;

    while (microtime(true) < $deadline) {
        $header = fread($sock, 1);
        if ($header === false || $header === '') { usleep(50000); continue; }
        $type = ord($header) >> 4;

        $multiplier = 1; $value = 0; $byte = 0;
        do {
            $b = fread($sock, 1);
            if ($b === false || $b === '') break 2;
            $byte = ord($b);
            $value += ($byte & 127) * $multiplier;
            $multiplier *= 128;
        } while ($byte & 128);
        $remaining = $value;

        $data = '';
        while (strlen($data) < $remaining && microtime(true) < $deadline) {
            $chunk = fread($sock, $remaining - strlen($data));
            if ($chunk === false || $chunk === '') { usleep(20000); continue; }
            $data .= $chunk;
        }

        if ($type === 3 && strlen($data) >= 2) { // PUBLISH
            $topicLen = unpack('n', substr($data, 0, 2))[1];
            $recvTopic = substr($data, 2, $topicLen);
            $payload = substr($data, 2 + $topicLen);
            break;
        }
        // else SUBACK or other control packet - keep waiting for PUBLISH
    }

    fwrite($sock, chr(0xE0) . chr(0x00)); // DISCONNECT
    fclose($sock);

    if ($payload === null) {
        return ['ok' => false, 'payload' => null, 'topic' => null, 'error' => 'no message received on that topic within ' . $timeout . 's (device offline, wrong topic, or no retained value)'];
    }
    return ['ok' => true, 'payload' => $payload, 'topic' => $recvTopic, 'error' => ''];
}

/**
 * Query a rule's MQTT state topic and interpret on/off. The payload may be
 * a bare string ("ON"/"OFF") or JSON (e.g. zigbee2mqtt's {"state":"ON"});
 * state_json_key names which JSON field to read (default "state").
 */
function htt_query_mqtt_state($config, $rule) {
    $m = htt_resolve_protocol($config, $rule, 'mqtt');
    $topic = $m['state_topic'] ?? '';
    // zigbee2mqtt (and similar bridges) often don't retain state and only
    // report on change; asking on its .../get topic prompts an immediate
    // report instead of waiting on a retained message that may not exist.
    $getTopic = substr($topic, -4) === '/get' ? null : $topic . '/get';
    $result = htt_mqtt_read_one($m['host'] ?? '', intval($m['port'] ?? 1883), $m['username'] ?? '', $m['password'] ?? '', $topic, 5, $getTopic, '{"state":""}', !empty($m['tls']), !empty($m['insecure_tls']));
    if (!$result['ok']) return ['ok' => false, 'state' => null, 'raw' => '', 'error' => $result['error']];

    $payload = $result['payload'];
    $value = trim($payload);
    if (!empty($m['state_is_json'])) {
        $decoded = json_decode($payload, true);
        if (is_array($decoded)) {
            $key = $m['state_json_key'] ?? 'state';
            if (array_key_exists($key, $decoded)) $value = $decoded[$key];
        }
    }

    $onPayload = trim($m['state_on_value'] ?? 'ON');
    $offPayload = trim($m['state_off_value'] ?? 'OFF');
    $state = null;
    if (strcasecmp((string)$value, $onPayload) === 0 || strcasecmp((string)$value, 'ON') === 0 || strcasecmp((string)$value, 'true') === 0) {
        $state = 'on';
    } elseif (strcasecmp((string)$value, $offPayload) === 0 || strcasecmp((string)$value, 'OFF') === 0 || strcasecmp((string)$value, 'false') === 0) {
        $state = 'off';
    }

    return ['ok' => true, 'state' => $state, 'raw' => $payload, 'error' => ''];
}

function htt_mqtt_str($s) { return pack('n', strlen($s)) . $s; }

function htt_mqtt_len($len) {
    $out = '';
    do {
        $byte = $len % 128;
        $len = intdiv($len, 128);
        if ($len > 0) $byte |= 0x80;
        $out .= chr($byte);
    } while ($len > 0);
    return $out;
}

function htt_send_mqtt($config, $rule) {
    $m = htt_resolve_protocol($config, $rule, 'mqtt');
    $host = $m['host'] ?? '';
    if ($host === '') return false;
    $port = intval($m['port'] ?? 1883);
    $topic = $m['topic'] ?? '';
    $payload = $m['payload'] ?? '';
    if ($payload === '') $payload = 'ON';
    if ($topic === '') return false;
    $clientId = 'httrigger-' . substr(md5($rule['id'] . microtime()), 0, 8);
    $ok = htt_mqtt_publish($host, $port, $clientId, $m['username'] ?? '', $m['password'] ?? '', $topic, $payload, 5, !empty($m['tls']), !empty($m['insecure_tls']));
    if ($ok) {
        htt_log("MQTT publish OK for rule '{$rule['name']}': $topic = $payload");
    } else {
        htt_log("MQTT publish FAILED for rule '{$rule['name']}': $topic");
    }
    return $ok;
}

/**
 * Fire a generic HTTP/HTTPS webhook: arbitrary URL/method/headers/body per
 * on/off state, unlike htt_send_http() which is hardcoded to Tasmota's
 * cmnd=Power query-string API. Lets rules target any HTTP-controllable
 * device or automation endpoint (Home Assistant, Node-RED, etc.).
 */
/**
 * Shared curl runner for htt_send_webhook() and htt_query_webhook_state().
 * Returns ['ok'=>bool (transport-level only, doesn't consider HTTP status),
 * 'status'=>int, 'body'=>string, 'error'=>string].
 */
function htt_webhook_request($url, $method, $body, $headersRaw, $username, $password, $insecureTls) {
    if (!preg_match('#^https?://#i', $url)) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'URL must start with http:// or https://'];
    }
    $method = strtoupper($method ?: 'GET');

    $headers = [];
    foreach (preg_split('/\r?\n/', $headersRaw ?? '') as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, ':') === false) continue;
        $headers[] = $line;
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && $body !== '') {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    if (!empty($username)) {
        $opts[CURLOPT_USERPWD] = $username . ':' . ($password ?? '');
    }
    if (!empty($insecureTls)) {
        // User opted in to trusting self-signed/expired certs on this endpoint
        // (common for LAN devices/home automation hubs with no real CA cert).
        $opts[CURLOPT_SSL_VERIFYPEER] = false;
        $opts[CURLOPT_SSL_VERIFYHOST] = 0;
    }
    curl_setopt_array($ch, $opts);
    $result = curl_exec($ch);
    $err = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => $err];
    }
    return ['ok' => true, 'status' => $status, 'body' => $result, 'error' => ''];
}

function htt_send_webhook($config, $rule) {
    $w = htt_resolve_protocol($config, $rule, 'webhook');
    $url = trim($w['url'] ?? '');
    if ($url === '') return false;
    $method = $w['method'] ?? 'GET';
    $body = $w['body'] ?? '';

    $r = htt_webhook_request($url, $method, $body, $w['headers'] ?? '', $w['username'] ?? '', $w['password'] ?? '', $w['insecure_tls'] ?? false);
    if (!$r['ok']) {
        htt_log("Webhook send failed for rule '{$rule['name']}': {$r['error']}");
        return false;
    }
    if ($r['status'] >= 400) {
        htt_log("Webhook send for rule '{$rule['name']}' got HTTP {$r['status']}: $method $url");
        return false;
    }
    htt_log("Webhook send OK for rule '{$rule['name']}': $method $url -> HTTP {$r['status']}");
    return true;
}

/**
 * Debug-only: fire the configured state-check request and hand back the raw
 * response verbatim, with no attempt to interpret it as on/off - webhooks are
 * arbitrary endpoints, so unlike htt_query_http_state()/htt_query_mqtt_state()
 * there's no reliable convention to parse. Lets users confirm the URL,
 * auth, and headers are wired up correctly.
 */
function htt_query_webhook_state($config, $rule) {
    $w = htt_resolve_protocol($config, $rule, 'webhook');
    $url = trim($w['state_url'] ?? '');
    if ($url === '') return ['ok' => false, 'state' => null, 'raw' => '', 'error' => 'no state URL configured'];
    $method = $w['state_method'] ?? 'GET';

    $r = htt_webhook_request($url, $method, '', $w['headers'] ?? '', $w['username'] ?? '', $w['password'] ?? '', $w['insecure_tls'] ?? false);
    if (!$r['ok']) {
        return ['ok' => false, 'state' => null, 'raw' => '', 'error' => $r['error']];
    }
    return ['ok' => true, 'state' => null, 'raw' => "HTTP {$r['status']}\n{$r['body']}", 'error' => ''];
}

/**
 * Fire a rule's configured action. Each protocol's own config fully
 * describes what gets sent (HTTP's Power On/Off command, MQTT's topic +
 * payload, webhook's fixed URL/method/body) - there's no separate rule-level
 * on/off to resolve here.
 */
function htt_send_command($config, $rule) {
    $protocol = $rule['protocol'] ?? 'http';
    if ($protocol === 'mqtt') return htt_send_mqtt($config, $rule);
    if ($protocol === 'webhook') return htt_send_webhook($config, $rule);
    return htt_send_http($config, $rule);
}

/**
 * Detect an in-progress parity check or array rebuild/data-rebuild from
 * Unraid's own array state file. Rules can use trigger_type
 * 'parity_check'/'rebuild' to fire their own action directly off this,
 * independent of any temperature/usage rules.
 */
function htt_array_status() {
    $ini = '/var/local/emhttp/var.ini';
    $status = ['active' => false, 'is_parity_check' => false, 'is_rebuild' => false, 'action' => ''];
    if (!file_exists($ini)) return $status;
    $var = parse_ini_file($ini);
    if (!is_array($var)) return $status;

    $resync = intval($var['mdResync'] ?? 0);
    $action = trim($var['mdResyncAction'] ?? '');
    $status['action'] = $action;
    $status['active'] = $resync !== 0;

    if ($status['active']) {
        if (stripos($action, 'check') !== false) {
            $status['is_parity_check'] = true;
        } elseif (stripos($action, 'recon') !== false || stripos($action, 'rebuild') !== false || stripos($action, 'clear') !== false) {
            $status['is_rebuild'] = true;
        }
    }
    return $status;
}

/**
 * Normalize a rule's condition(s) to the current list shape. Older saved
 * rules stored exactly one condition inline (trigger_type/disks/aggregate/
 * direction/threshold at the rule's top level, doubling as both the
 * condition and the action polarity) - wrap those into a one-element
 * conditions list on read so evaluation only has one shape to deal with.
 */
function htt_rule_conditions($rule) {
    if (isset($rule['conditions']) && is_array($rule['conditions']) && !empty($rule['conditions'])) {
        return $rule['conditions'];
    }
    return [[
        'trigger_type' => $rule['trigger_type'] ?? 'temp',
        'disks' => $rule['disks'] ?? ['all'],
        'aggregate' => $rule['aggregate'] ?? 'max',
        'direction' => $rule['direction'] ?? 'on',
        'threshold' => $rule['threshold'] ?? 0,
    ]];
}

/**
 * Evaluate a single condition (one entry from a rule's conditions list)
 * against current disk/array state. Returns
 * ['met'=>bool|null (null = data unavailable), 'reason'=>string].
 */
function htt_eval_condition($cond, $disks, $array) {
    $triggerType = $cond['trigger_type'] ?? 'temp';
    $direction = ($cond['direction'] ?? 'on') === 'off' ? 'off' : 'on';

    if ($triggerType === 'parity_check' || $triggerType === 'rebuild') {
        $what = $triggerType === 'parity_check' ? 'parity check' : 'array/data rebuild';
        $active = $array['active'] && ($triggerType === 'parity_check' ? $array['is_parity_check'] : $array['is_rebuild']);
        $met = $direction === 'on' ? $active : !$active;
        return ['met' => $met, 'reason' => "$what " . ($active ? 'active' : 'inactive')];
    }

    $metricFn = $triggerType === 'usage' ? 'htt_disk_usage_pct' : 'htt_disk_temp';
    $selected = $cond['disks'] ?? ['all'];
    $values = [];
    if (in_array('all', $selected)) {
        foreach ($disks as $d) $values[] = $metricFn($d);
    } else {
        foreach ($selected as $name) {
            if (isset($disks[$name])) $values[] = $metricFn($disks[$name]);
        }
    }
    $agg = htt_aggregate($values, $cond['aggregate'] ?? 'max');
    if ($agg === null) return ['met' => null, 'reason' => ''];
    $threshold = floatval($cond['threshold'] ?? 0);
    $met = $direction === 'on' ? $agg >= $threshold : $agg <= $threshold;
    $reason = ($triggerType === 'usage' ? "usage={$agg}%" : "temp={$agg}C");
    return ['met' => $met, 'reason' => $reason];
}

/**
 * Debug-only: evaluate a rule's conditions right now (against live
 * disk/array state, no side effects) and report each one's individual
 * result plus the running AND/OR result after it, so the webGUI can show
 * exactly which condition(s) are making the rule true or false.
 */
function htt_test_conditions($rule) {
    $disks = htt_list_disks();
    $array = htt_array_status();
    $steps = [];
    $running = null;
    foreach (htt_rule_conditions($rule) as $idx => $cond) {
        $result = htt_eval_condition($cond, $disks, $array);
        $join = ($cond['join'] ?? 'and') === 'or' ? 'or' : 'and';
        if ($result['met'] === null) {
            $steps[] = ['join' => $idx > 0 ? $join : null, 'trigger_type' => $cond['trigger_type'] ?? 'temp', 'reason' => 'no data available (disk spun down, or usage unavailable)', 'met' => null, 'running' => null];
            $running = null;
            continue;
        }
        $running = ($idx === 0) ? $result['met'] : ($running === null ? $result['met'] : ($join === 'or' ? ($running || $result['met']) : ($running && $result['met'])));
        $steps[] = ['join' => $idx > 0 ? $join : null, 'trigger_type' => $cond['trigger_type'] ?? 'temp', 'reason' => $result['reason'], 'met' => $result['met'], 'running' => $running];
    }
    return ['ok' => true, 'overall' => $running === true, 'steps' => $steps];
}

function htt_run_cycle() {
    $config = htt_load_config();
    if (!($config['enabled'] ?? true)) return; // plugin disabled at the top level - skip the whole cycle

    $disks = htt_list_disks();
    $state = htt_load_state();
    $array = htt_array_status();
    // Recorded so the webGUI can show a countdown to the next poll cycle;
    // '_' prefix keeps it out of the way of rule ids (hex strings from
    // bin2hex, never underscore-prefixed).
    $state['_last_cycle_at'] = time();
    $ruleNames = [];
    foreach ($config['rules'] as $r) $ruleNames[$r['id'] ?? ''] = $r['name'] ?? '?';

    foreach ($config['rules'] as $rule) {
        if (empty($rule['enabled'])) continue;
        $id = $rule['id'];
        $prevFired = !empty($state[$id]['fired']);

        // A rule fires once its conditions (evaluated left-to-right, each
        // joined to the running result by its own AND/OR) come out true -
        // e.g. "disk temp <= 35C" AND "no parity check/rebuild active", so
        // a cooling-triggered fan-off doesn't fight a separate "keep the fan
        // on during parity check" rule over the same relay.
        $conditionMet = null;
        $reasons = [];
        $unavailable = false;
        $lastValue = null;
        foreach (htt_rule_conditions($rule) as $idx => $cond) {
            $result = htt_eval_condition($cond, $disks, $array);
            if ($result['met'] === null) { $unavailable = true; break; }
            if (($cond['trigger_type'] ?? 'temp') !== 'parity_check' && ($cond['trigger_type'] ?? 'temp') !== 'rebuild') {
                $lastValue = $result['reason']; // last metric reading, for the webGUI badge/debug
            }
            $join = ($cond['join'] ?? 'and') === 'or' ? 'or' : 'and';
            $reasons[] = ($idx > 0 ? strtoupper($join) . ' ' : '') . $result['reason'];
            $conditionMet = ($idx === 0) ? $result['met'] : ($join === 'or' ? ($conditionMet || $result['met']) : ($conditionMet && $result['met']));
        }
        if ($conditionMet === null) $conditionMet = true; // no conditions configured - shouldn't happen, but don't block on it
        $reason = implode(' ', $reasons);
        if ($lastValue !== null) $state[$id]['last_value'] = $lastValue;

        $state[$id]['last_check'] = time();

        if ($unavailable) {
            htt_log("Rule '{$rule['name']}': a condition's data is unavailable (all spun down, or usage data unavailable?), skipping");
            continue;
        }

        $delaySec = floatval($rule['delay_seconds'] ?? 0);

        if (!$conditionMet) {
            unset($state[$id]['pending_since']);
            if ($prevFired) {
                $state[$id]['fired'] = false;
                htt_log("Rule '{$rule['name']}': $reason, condition cleared, reset");
            }
            continue;
        }

        if ($prevFired) {
            // Already fired and condition still holds - nothing to do unless
            // force_resend wants us to keep re-asserting every cycle (guards
            // against drift if the device was toggled manually, or a previous
            // send was silently dropped).
            if (!empty($rule['force_resend'])) {
                $ok = htt_send_command($config, $rule);
                if ($ok) {
                    htt_log("Rule '{$rule['name']}': $reason, re-asserted action (force resend)");
                } else {
                    htt_log("Rule '{$rule['name']}': $reason, force-resend FAILED, will retry next cycle");
                }
            }
            continue;
        }

        // Not yet fired - condition just became true (or is still pending
        // its configured delay). A pending fire must keep holding true
        // across cycles for the full delay; if the condition reverts before
        // then, it's cleared above rather than firing stale.
        if (!isset($state[$id]['pending_since'])) {
            $state[$id]['pending_since'] = time();
            if ($delaySec > 0) {
                htt_log("Rule '{$rule['name']}': $reason, pending (delay {$delaySec}s)");
            }
        }
        $delayElapsed = $delaySec <= 0 || (time() - $state[$id]['pending_since']) >= $delaySec;
        if (!$delayElapsed) continue;

        $ok = htt_send_command($config, $rule);
        if ($ok) {
            $state[$id]['fired'] = true;
            unset($state[$id]['pending_since']);
            htt_log("Rule '{$rule['name']}': $reason, fired");
            // Reset any rules this one is configured to reset - e.g. an ON
            // rule resetting its paired OFF rule's fired flag, so hysteresis
            // pairs don't get stuck believing they already fired. Only on
            // this not-fired -> fired transition, not on every force-resend
            // re-assertion, which would otherwise reset the same rules every
            // single poll cycle.
            foreach (($rule['reset_rules'] ?? []) as $resetId) {
                if ($resetId === $id) continue;
                if (!empty($state[$resetId]['fired'])) {
                    $state[$resetId]['fired'] = false;
                    unset($state[$resetId]['pending_since']);
                    $resetName = $ruleNames[$resetId] ?? $resetId;
                    htt_log("Rule '{$rule['name']}': reset '{$resetName}''s fired flag");
                }
            }
        } else {
            htt_log("Rule '{$rule['name']}': $reason, send FAILED, will retry next cycle");
        }
    }

    htt_save_state($state);
}
