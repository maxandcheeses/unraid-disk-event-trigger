#!/usr/bin/php
<?php
/**
 * Background poller for unraid-disk-event-trigger. Runs in a loop for the
 * lifetime of the process; started/stopped by /etc/rc.d/rc.unraid-disk-event-trigger.
 */

require_once '/usr/local/emhttp/plugins/unraid-disk-event-trigger/include/lib.php';

declare(ticks = 1);
$running = true;
pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
pcntl_signal(SIGINT, function () use (&$running) { $running = false; });

htt_log('Poll daemon started (pid ' . getmypid() . ')');

while ($running) {
    $config = htt_load_config();
    $interval = max(10, intval($config['poll_interval'] ?? 60));

    try {
        htt_run_cycle();
    } catch (Throwable $e) {
        htt_log('Poll cycle error: ' . $e->getMessage());
    }

    for ($i = 0; $i < $interval && $running; $i++) {
        sleep(1);
    }
}

htt_log('Poll daemon stopped (pid ' . getmypid() . ')');
