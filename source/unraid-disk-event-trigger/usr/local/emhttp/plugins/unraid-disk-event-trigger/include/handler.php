<?php
require_once '/usr/local/emhttp/plugins/unraid-disk-event-trigger/include/lib.php';
header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function respond($data) { echo json_encode($data); exit; }

switch ($action) {
    case 'get_config':
        respond(htt_load_config());

    case 'save_config':
        $raw = $_POST['config'] ?? '';
        $config = json_decode($raw, true);
        if (!is_array($config)) respond(['ok' => false, 'error' => 'invalid config']);
        foreach ($config['rules'] as &$rule) {
            if (empty($rule['id'])) $rule['id'] = bin2hex(random_bytes(6));
        }
        unset($rule);
        htt_save_config($config);
        shell_exec('/etc/rc.d/rc.unraid-disk-event-trigger restart > /dev/null 2>&1 &');
        respond(['ok' => true]);

    case 'get_status':
        $disks = htt_list_disks();
        foreach ($disks as &$d) { $d['live_temp'] = htt_disk_temp($d); }
        unset($d);
        $svc = trim(shell_exec('/etc/rc.d/rc.unraid-disk-event-trigger status 2>&1'));
        respond([
            'disks' => array_values($disks),
            'state' => htt_load_state(),
            'array' => htt_array_status(),
            'service_status' => $svc,
            'running' => (strpos($svc, 'is running') !== false),
        ]);

    case 'get_log':
        $lines = file_exists(HTT_LOG_FILE) ? array_slice(file(HTT_LOG_FILE), -200) : [];
        respond(['log' => implode('', array_reverse($lines))]);

    case 'clear_log':
        file_put_contents(HTT_LOG_FILE, '');
        htt_log('Log cleared by user');
        respond(['ok' => true]);

    case 'service_control':
        $cmd = $_POST['cmd'] ?? '';
        if (!in_array($cmd, ['start', 'stop', 'restart'])) respond(['ok' => false]);
        shell_exec('/etc/rc.d/rc.unraid-disk-event-trigger ' . escapeshellarg($cmd) . ' > /dev/null 2>&1 &');
        respond(['ok' => true]);

    case 'test_command':
        $rule = json_decode($_POST['rule'] ?? '', true);
        $on = ($_POST['on'] ?? '1') === '1';
        if (!is_array($rule)) respond(['ok' => false, 'error' => 'invalid rule']);
        $ok = htt_send_command($rule, $on);
        if ($ok && !empty($rule['id'])) {
            // Keep the automatic poll cycle's hysteresis state in sync with
            // manual test sends, otherwise a manual Test OFF leaves the
            // cycle thinking the relay is still on and it never re-fires.
            $state = htt_load_state();
            $state[$rule['id']]['relay'] = $on ? 'on' : 'off';
            htt_save_state($state);
        }
        respond(['ok' => $ok]);

    case 'test_mqtt':
        $rule = json_decode($_POST['rule'] ?? '', true);
        if (!is_array($rule)) respond(['ok' => false, 'error' => 'invalid rule']);
        $m = $rule['mqtt'] ?? [];
        $result = htt_mqtt_test_connection($m['host'] ?? '', intval($m['port'] ?? 1883), $m['username'] ?? '', $m['password'] ?? '');
        htt_log("MQTT connection test for rule '{$rule['name']}': " . ($result['ok'] ? 'OK' : $result['error']));
        respond($result);

    case 'get_device_state':
        $rule = json_decode($_POST['rule'] ?? '', true);
        if (!is_array($rule)) respond(['ok' => false, 'error' => 'invalid rule']);
        $result = (($rule['protocol'] ?? 'http') === 'mqtt') ? htt_query_mqtt_state($rule) : htt_query_http_state($rule);
        if ($result['ok']) {
            htt_log("Device state check for rule '{$rule['name']}': raw='{$result['raw']}' -> " . ($result['state'] ?? 'unknown'));
            // Self-heal: sync our tracked hysteresis state to what the device actually reports.
            if (!empty($result['state']) && !empty($rule['id'])) {
                $state = htt_load_state();
                $state[$rule['id']]['relay'] = $result['state'];
                htt_save_state($state);
            }
        } else {
            htt_log("Device state check for rule '{$rule['name']}' FAILED: {$result['error']}");
        }
        respond($result);

    default:
        respond(['ok' => false, 'error' => 'unknown action']);
}
