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
function htt_mqtt_connect($host, $port, $clientId, $username, $password, $timeout = 5) {
    $errno = 0; $errstr = '';
    $sock = @stream_socket_client("tcp://$host:$port", $errno, $errstr, $timeout);
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

function htt_mqtt_publish($host, $port, $clientId, $username, $password, $topic, $payload, $timeout = 5) {
    $conn = htt_mqtt_connect($host, $port, $clientId, $username, $password, $timeout);
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
function htt_mqtt_test_connection($host, $port, $username, $password, $timeout = 5) {
    if ($host === '') return ['ok' => false, 'error' => 'no broker host configured'];
    $clientId = 'httrigger-test-' . substr(md5(microtime()), 0, 8);
    $conn = htt_mqtt_connect($host, $port, $clientId, $username, $password, $timeout);
    if ($conn['ok']) {
        fwrite($conn['sock'], chr(0xE0) . chr(0x00)); // DISCONNECT
        fclose($conn['sock']);
    }
    return ['ok' => $conn['ok'], 'error' => $conn['error']];
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
    $ok = htt_mqtt_publish($host, $port, $clientId, $m['username'] ?? '', $m['password'] ?? '', $topic, $payload);
    if ($ok) {
        htt_log("MQTT publish OK for rule '{$rule['name']}': $topic = $payload");
    } else {
        htt_log("MQTT publish FAILED for rule '{$rule['name']}': $topic");
    }
    return $ok;
}

function htt_send_command($rule, $on) {
    if (($rule['protocol'] ?? 'http') === 'mqtt') {
        return htt_send_mqtt($rule, $on);
    }
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
        $newRelay = $prev;
        $reason = "temp={$agg}C";

        if ($forceOn && $prev !== 'on') {
            $newRelay = 'on';
            $reason = "array op '{$array['action']}' active";
        } elseif (!$forceOn && $agg !== null) {
            if ($agg >= $onTemp && $prev !== 'on') {
                $newRelay = 'on';
            } elseif ($agg <= $offTemp && $prev !== 'off') {
                $newRelay = 'off';
            }
        }

        $state[$id]['last_temp'] = $agg;
        $state[$id]['last_check'] = time();
        $state[$id]['forced_by_array_op'] = $forceOn;

        if ($newRelay !== $prev) {
            $ok = htt_send_command($rule, $newRelay === 'on');
            if ($ok) {
                $state[$id]['relay'] = $newRelay;
                htt_log("Rule '{$rule['name']}': $reason, transitioned {$prev} -> {$newRelay}");
            } else {
                htt_log("Rule '{$rule['name']}': $reason, transition {$prev} -> {$newRelay} FAILED, will retry next cycle");
            }
        }
    }

    htt_save_state($state);
}
