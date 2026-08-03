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
        'rules' => [],
    ];
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

    foreach (($config['rules'] ?? []) as $idx => $rule) {
        $label = "rule #" . ($idx + 1) . (is_array($rule) && !empty($rule['name']) ? " ('{$rule['name']}')" : '');
        if (!is_array($rule)) { $errors[] = "$label must be a mapping"; continue; }
        if (isset($rule['on_temp']) && !is_numeric($rule['on_temp'])) $errors[] = "$label: on_temp must be a number";
        if (isset($rule['off_temp']) && !is_numeric($rule['off_temp'])) $errors[] = "$label: off_temp must be a number";
        if (isset($rule['on_delay_seconds']) && (!is_numeric($rule['on_delay_seconds']) || $rule['on_delay_seconds'] < 0)) $errors[] = "$label: on_delay_seconds must be a non-negative number";
        if (isset($rule['off_delay_seconds']) && (!is_numeric($rule['off_delay_seconds']) || $rule['off_delay_seconds'] < 0)) $errors[] = "$label: off_delay_seconds must be a non-negative number";
        if (isset($rule['protocol']) && !in_array($rule['protocol'], ['http', 'mqtt', 'webhook'], true)) {
            $errors[] = "$label: protocol must be 'http', 'mqtt', or 'webhook'";
        }
        if (isset($rule['disks']) && !is_array($rule['disks'])) $errors[] = "$label: disks must be a list";
        if (isset($rule['aggregate']) && !in_array($rule['aggregate'], ['max', 'avg', 'min'], true)) {
            $errors[] = "$label: aggregate must be 'max', 'avg', or 'min'";
        }
        if (isset($rule['http']) && !is_array($rule['http'])) $errors[] = "$label: http must be a mapping";
        if (isset($rule['mqtt']) && !is_array($rule['mqtt'])) $errors[] = "$label: mqtt must be a mapping";
        if (isset($rule['mqtt']['tls']) && !is_bool($rule['mqtt']['tls'])) $errors[] = "$label: mqtt.tls must be true or false";
        if (isset($rule['mqtt']['insecure_tls']) && !is_bool($rule['mqtt']['insecure_tls'])) $errors[] = "$label: mqtt.insecure_tls must be true or false";
        if (isset($rule['webhook'])) {
            if (!is_array($rule['webhook'])) {
                $errors[] = "$label: webhook must be a mapping";
            } else {
                $w = $rule['webhook'];
                foreach (['on_method', 'off_method'] as $mk) {
                    if (isset($w[$mk]) && !in_array(strtoupper($w[$mk]), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                        $errors[] = "$label: webhook.$mk must be one of GET, POST, PUT, PATCH, DELETE";
                    }
                }
                foreach (['on_url', 'off_url'] as $uk) {
                    if (!empty($w[$uk]) && !preg_match('#^https?://#i', $w[$uk])) {
                        $errors[] = "$label: webhook.$uk must start with http:// or https://";
                    }
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
            $disks[$name] = [
                'name' => $name,
                'device' => $d['device'],
                'spundown' => !empty($d['spundown']) && $d['spundown'] == '1',
                'temp' => isset($d['temp']) ? intval($d['temp']) : null,
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
function htt_send_http($rule, $on) {
    $base = rtrim($rule['http']['base_url'] ?? '', '/');
    if ($base === '') return false;
    $idx = $rule['http']['device_index'] ?? '';
    $cmndPrefix = $idx !== '' ? "Power{$idx}" : 'Power';
    $cmnd = $on ? "{$cmndPrefix}%20On" : "{$cmndPrefix}%20Off";
    $url = "$base/cm?cmnd=$cmnd";
    if (!empty($rule['http']['username'])) {
        $url .= '&user=' . urlencode($rule['http']['username']) . '&password=' . urlencode($rule['http']['password'] ?? '');
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
 * Query a Tasmota device's actual current relay state via its HTTP status
 * API (does not change anything). Returns
 * ['ok'=>bool, 'state'=>'on'|'off'|null, 'raw'=>string, 'error'=>string].
 */
function htt_query_http_state($rule) {
    $base = rtrim($rule['http']['base_url'] ?? '', '/');
    if ($base === '') return ['ok' => false, 'state' => null, 'raw' => '', 'error' => 'no base URL configured'];
    $idx = $rule['http']['device_index'] ?? '';
    $cmndPrefix = $idx !== '' ? "Power{$idx}" : 'Power';
    $url = "$base/cm?cmnd=" . urlencode($cmndPrefix);
    if (!empty($rule['http']['username'])) {
        $url .= '&user=' . urlencode($rule['http']['username']) . '&password=' . urlencode($rule['http']['password'] ?? '');
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
function htt_query_mqtt_state($rule) {
    $m = $rule['mqtt'] ?? [];
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

    $onPayload = trim($m['on_payload'] ?? 'ON');
    $offPayload = trim($m['off_payload'] ?? 'OFF');
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

function htt_send_mqtt($rule, $on) {
    $m = $rule['mqtt'] ?? [];
    $host = $m['host'] ?? '';
    if ($host === '') return false;
    $port = intval($m['port'] ?? 1883);
    $topic = $on ? ($m['on_topic'] ?? $m['topic'] ?? '') : ($m['off_topic'] ?? $m['topic'] ?? '');
    $payload = $on ? ($m['on_payload'] ?? 'ON') : ($m['off_payload'] ?? 'OFF');
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

function htt_send_webhook($rule, $on) {
    $w = $rule['webhook'] ?? [];
    $url = trim($on ? ($w['on_url'] ?? '') : ($w['off_url'] ?? ''));
    if ($url === '') return false;
    $method = $on ? ($w['on_method'] ?? 'GET') : ($w['off_method'] ?? 'GET');
    $body = $on ? ($w['on_body'] ?? '') : ($w['off_body'] ?? '');

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
function htt_query_webhook_state($rule) {
    $w = $rule['webhook'] ?? [];
    $url = trim($w['state_url'] ?? '');
    if ($url === '') return ['ok' => false, 'state' => null, 'raw' => '', 'error' => 'no state URL configured'];
    $method = $w['state_method'] ?? 'GET';

    $r = htt_webhook_request($url, $method, '', $w['headers'] ?? '', $w['username'] ?? '', $w['password'] ?? '', $w['insecure_tls'] ?? false);
    if (!$r['ok']) {
        return ['ok' => false, 'state' => null, 'raw' => '', 'error' => $r['error']];
    }
    return ['ok' => true, 'state' => null, 'raw' => "HTTP {$r['status']}\n{$r['body']}", 'error' => ''];
}

function htt_send_command($rule, $on) {
    $protocol = $rule['protocol'] ?? 'http';
    if ($protocol === 'mqtt') return htt_send_mqtt($rule, $on);
    if ($protocol === 'webhook') return htt_send_webhook($rule, $on);
    return htt_send_http($rule, $on);
}

/**
 * Detect an in-progress parity check or array rebuild/data-rebuild from
 * Unraid's own array state file. Disks run considerably hotter during
 * these operations, so rules can opt in to forcing the relay ON for the
 * duration regardless of the temperature threshold.
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
 * Evaluate all enabled rules against current disk temps and fire Tasmota
 * commands on state transitions (hysteresis between on_temp/off_temp).
 */
function htt_run_cycle() {
    $config = htt_load_config();
    if (!($config['enabled'] ?? true)) return; // plugin disabled at the top level - skip the whole cycle

    $disks = htt_list_disks();
    $state = htt_load_state();
    $array = htt_array_status();

    foreach ($config['rules'] as $rule) {
        if (empty($rule['enabled'])) continue;
        $id = $rule['id'];

        $selected = $rule['disks'] ?? ['all'];
        $temps = [];
        if (in_array('all', $selected)) {
            foreach ($disks as $d) $temps[] = htt_disk_temp($d);
        } else {
            foreach ($selected as $name) {
                if (isset($disks[$name])) $temps[] = htt_disk_temp($disks[$name]);
            }
        }

        $agg = htt_aggregate($temps, $rule['aggregate'] ?? 'max');
        $prev = $state[$id]['relay'] ?? 'unknown';

        $forceOn = $array['active'] && (
            (!empty($rule['trigger_on_parity_check']) && $array['is_parity_check']) ||
            (!empty($rule['trigger_on_rebuild']) && $array['is_rebuild'])
        );

        if ($agg === null && !$forceOn) {
            htt_log("Rule '{$rule['name']}': no readable disk temps (all spun down?), skipping");
            continue;
        }

        $onTemp = floatval($rule['on_temp']);
        $offTemp = floatval($rule['off_temp']);
        // Desired relay state per current thresholds; within the hysteresis
        // band (between off_temp and on_temp) carry forward the last decision.
        $desiredRelay = $prev;
        $reason = "temp={$agg}C";

        if ($forceOn) {
            $desiredRelay = 'on';
            $reason = "array op '{$array['action']}' active";
        } elseif ($agg !== null) {
            if ($agg >= $onTemp) {
                $desiredRelay = 'on';
            } elseif ($agg <= $offTemp) {
                $desiredRelay = 'off';
            }
        }

        $state[$id]['last_temp'] = $agg;
        $state[$id]['last_check'] = time();
        $state[$id]['forced_by_array_op'] = $forceOn;

        $isTransition = in_array($desiredRelay, ['on', 'off'], true) && $desiredRelay !== $prev;
        $delaySec = $forceOn ? 0 : floatval(($desiredRelay === 'on' ? $rule['on_delay_seconds'] : $rule['off_delay_seconds']) ?? 0);

        // A pending transition must keep wanting the same new state across
        // cycles for the full delay before it's actually sent - if the
        // condition reverts (or flips to a different desired state) in the
        // meantime, the delay resets/clears rather than firing stale.
        if (!$isTransition || $delaySec <= 0) {
            unset($state[$id]['pending_relay']);
            unset($state[$id]['pending_since']);
        } else {
            if (($state[$id]['pending_relay'] ?? null) !== $desiredRelay) {
                $state[$id]['pending_relay'] = $desiredRelay;
                $state[$id]['pending_since'] = time();
                htt_log("Rule '{$rule['name']}': $reason, {$prev} -> {$desiredRelay} pending (delay {$delaySec}s)");
            }
        }

        $delayElapsed = $delaySec <= 0 || (time() - ($state[$id]['pending_since'] ?? time())) >= $delaySec;

        // Normally only send on an actual transition (once any configured
        // delay has elapsed with the condition still holding). With
        // force_resend, keep re-asserting the desired state every cycle even
        // if unchanged - guards against the tracked relay state drifting
        // from the real device (e.g. someone toggled it manually, or a
        // previous send was silently dropped by the broker/device).
        $shouldSend = in_array($desiredRelay, ['on', 'off'], true)
            && (($desiredRelay !== $prev && $delayElapsed) || (!$isTransition && !empty($rule['force_resend'])));

        if ($shouldSend) {
            $ok = htt_send_command($rule, $desiredRelay === 'on');
            if ($ok) {
                $state[$id]['relay'] = $desiredRelay;
                unset($state[$id]['pending_relay']);
                unset($state[$id]['pending_since']);
                $verb = $desiredRelay !== $prev ? "transitioned {$prev} -> {$desiredRelay}" : "re-asserted {$desiredRelay} (force resend)";
                htt_log("Rule '{$rule['name']}': $reason, $verb");
            } else {
                htt_log("Rule '{$rule['name']}': $reason, send to {$desiredRelay} FAILED, will retry next cycle");
            }
        }
    }

    htt_save_state($state);
}
