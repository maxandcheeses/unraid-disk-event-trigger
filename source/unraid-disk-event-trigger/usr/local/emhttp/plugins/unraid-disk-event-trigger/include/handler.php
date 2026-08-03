<?php
require_once '/usr/local/emhttp/plugins/unraid-disk-event-trigger/include/lib.php';
header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function respond($data) { echo json_encode($data); exit; }

switch ($action) {
    case 'get_config':
        respond(htt_load_config());

    case 'get_config_yaml':
        header('Content-Type: text/plain; charset=utf-8');
        echo htt_to_yaml(htt_load_config());
        exit;

    case 'save_config':
        $raw = $_POST['config'] ?? '';
        $config = json_decode($raw, true);
        if (!is_array($config)) respond(['ok' => false, 'error' => 'invalid config']);
        $errors = htt_validate_config($config);
        if ($errors) respond(['ok' => false, 'error' => implode('; ', $errors)]);
        respond(htt_apply_config($config));

    case 'save_global':
        $config = htt_load_config();
        $config['enabled'] = !empty($_POST['enabled']) && $_POST['enabled'] !== 'false';
        $config['poll_interval'] = intval($_POST['poll_interval'] ?? 60);
        $errors = htt_validate_config($config);
        if ($errors) respond(['ok' => false, 'error' => implode('; ', $errors)]);
        respond(htt_apply_config($config));

    case 'save_connections':
        $connections = json_decode($_POST['connections'] ?? '', true);
        if (!is_array($connections)) respond(['ok' => false, 'error' => 'invalid connections']);
        $config = htt_load_config();
        $config['connections'] = $connections;
        $errors = htt_validate_config($config);
        if ($errors) respond(['ok' => false, 'error' => implode('; ', $errors)]);
        respond(htt_apply_config($config));

    case 'save_rule':
        $rule = json_decode($_POST['rule'] ?? '', true);
        if (!is_array($rule)) respond(['ok' => false, 'error' => 'invalid rule']);
        $config = htt_load_config();
        $found = false;
        foreach ($config['rules'] as &$r) {
            if (!empty($rule['id']) && ($r['id'] ?? null) === $rule['id']) { $r = $rule; $found = true; break; }
        }
        unset($r);
        if (!$found) $config['rules'][] = $rule;
        $errors = htt_validate_config($config);
        if ($errors) respond(['ok' => false, 'error' => implode('; ', $errors)]);
        respond(htt_apply_config($config));

    case 'save_rules':
        // Persists the whole rule list/order as-is - used for structural
        // changes (add/remove/move/duplicate) rather than editing one rule's
        // fields, which go through save_rule instead.
        $rules = json_decode($_POST['rules'] ?? '', true);
        if (!is_array($rules)) respond(['ok' => false, 'error' => 'invalid rules']);
        $config = htt_load_config();
        $config['rules'] = $rules;
        $errors = htt_validate_config($config);
        if ($errors) respond(['ok' => false, 'error' => implode('; ', $errors)]);
        respond(htt_apply_config($config));

    case 'save_config_yaml':
        $yaml = $_POST['yaml'] ?? '';
        try {
            $config = htt_from_yaml($yaml);
        } catch (HttYamlError $e) {
            respond(['ok' => false, 'error' => 'YAML parse error: ' . $e->getMessage()]);
        }
        $errors = htt_validate_config($config);
        if ($errors) respond(['ok' => false, 'error' => implode('; ', $errors)]);
        respond(htt_apply_config($config));

    case 'clear_fired':
        $id = $_POST['id'] ?? '';
        if ($id === '') respond(['ok' => false, 'error' => 'missing rule id']);
        $state = htt_load_state();
        unset($state[$id]['fired'], $state[$id]['pending_since']);
        htt_save_state($state);
        htt_log("Fired state manually cleared for rule id '$id'");
        respond(['ok' => true]);

    case 'get_status':
        $disks = htt_list_disks();
        foreach ($disks as &$d) {
            $d['live_temp'] = htt_disk_temp($d);
            $d['usage_pct'] = htt_disk_usage_pct($d);
        }
        unset($d);
        $svc = trim(shell_exec('/etc/rc.d/rc.unraid-disk-event-trigger status 2>&1'));
        $cfg = htt_load_config();
        respond([
            'disks' => array_values($disks),
            'state' => htt_load_state(),
            'array' => htt_array_status(),
            'service_status' => $svc,
            'running' => (strpos($svc, 'is running') !== false),
            'poll_interval' => max(10, intval($cfg['poll_interval'] ?? 60)),
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
        if (!is_array($rule)) respond(['ok' => false, 'error' => 'invalid rule']);
        $ok = htt_send_command(htt_load_config(), $rule);
        if ($ok && !empty($rule['id'])) {
            // Keep the poll cycle's "already fired" tracking in sync with a
            // manual test send, so it doesn't immediately re-fire next cycle.
            $state = htt_load_state();
            $state[$rule['id']]['fired'] = true;
            htt_save_state($state);
        }
        respond(['ok' => $ok]);

    case 'test_conditions':
        $rule = json_decode($_POST['rule'] ?? '', true);
        if (!is_array($rule)) respond(['ok' => false, 'error' => 'invalid rule']);
        respond(htt_test_conditions($rule));

    case 'test_connection':
        $conn = json_decode($_POST['connection'] ?? '', true);
        if (!is_array($conn)) respond(['ok' => false, 'error' => 'invalid connection']);
        $result = htt_test_connection($conn);
        htt_log("Connection test for '{$conn['name']}': " . ($result['ok'] ? 'OK' : $result['error']));
        respond($result);

    case 'get_device_state':
        $rule = json_decode($_POST['rule'] ?? '', true);
        if (!is_array($rule)) respond(['ok' => false, 'error' => 'invalid rule']);
        $config = htt_load_config();
        $protocol = $rule['protocol'] ?? 'http';
        $result = $protocol === 'mqtt' ? htt_query_mqtt_state($config, $rule)
            : ($protocol === 'webhook' ? htt_query_webhook_state($config, $rule) : htt_query_http_state($config, $rule));
        if ($result['ok']) {
            htt_log("Device state check for rule '{$rule['name']}': raw='{$result['raw']}' -> " . ($result['state'] ?? 'unknown'));
            // Self-heal: sync this rule's "already fired" tracking to whether
            // the device's actual reported state matches what this HTTP rule's
            // command would set it to. Only HTTP rules have a rule-level
            // on/off polarity to compare against - MQTT/webhook rules send
            // whatever topic+payload or URL+body is configured, with no
            // single on/off to check the report against.
            if ($protocol === 'http' && !empty($result['state']) && !empty($rule['id'])) {
                $h = htt_resolve_protocol($config, $rule, 'http');
                $wantState = $h['state'] ?? ($rule['action_direction'] ?? 'on');
                $state = htt_load_state();
                $state[$rule['id']]['fired'] = ($result['state'] === $wantState);
                htt_save_state($state);
            }
        } else {
            htt_log("Device state check for rule '{$rule['name']}' FAILED: {$result['error']}");
        }
        respond($result);

    default:
        respond(['ok' => false, 'error' => 'unknown action']);
}
