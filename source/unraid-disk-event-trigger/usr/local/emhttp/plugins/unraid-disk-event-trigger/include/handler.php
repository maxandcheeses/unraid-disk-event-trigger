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
            'disks' => $disks,
            'state' => htt_load_state(),
            'array' => htt_array_status(),
            'service_status' => $svc,
            'running' => (strpos($svc, 'is running') !== false),
        ]);

    case 'get_log':
        $lines = file_exists(HTT_LOG_FILE) ? array_slice(file(HTT_LOG_FILE), -200) : [];
        respond(['log' => implode('', array_reverse($lines))]);

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
        respond(['ok' => $ok]);

    default:
        respond(['ok' => false, 'error' => 'unknown action']);
}
