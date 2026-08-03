# Disk Event Trigger

Monitors array/cache disk temperatures and fires a Tasmota or MQTT/zigbee2mqtt
switch when configurable thresholds are crossed - useful for triggering an
external fan, blower, or cooling relay based on drive temperature, a parity
check, or an array rebuild.

## What it does

- Reads disk temperatures from Unraid's own SMART cache (falling back to
  `smartctl` only for spun-up disks that haven't been polled yet); spun-down
  disks are never woken just to check temperature.
- Runs a background poller (`rc.unraid-disk-event-trigger`) on a configurable
  interval, independent of Unraid's cron granularity.
- Supports any number of rules, each covering a subset of disks (or "all"),
  aggregated by max/avg/min, with separate on/off thresholds (hysteresis) so
  a switch doesn't chatter near the boundary.
- Triggers via Tasmota's HTTP API or raw MQTT (works with Tasmota's MQTT mode
  and zigbee2mqtt-style JSON payloads), including username/password auth for
  both.
- Rules can also force the relay ON for the duration of an active parity
  check or array/data rebuild, since drives run hotter during those.
- A "force resend" option re-sends the command every poll cycle even when
  unchanged, guarding against the tracked state drifting from the real
  device (missed message, manual override elsewhere, power loss).
- "Check Device State" reads the device's actual current state (Tasmota
  HTTP status query, or an MQTT state topic) without changing anything, and
  syncs the plugin's tracked state to match if they've drifted apart.
- Settings page under **Settings > Utilities > Disk Event Trigger**: live
  disk temps, a per-rule tracked-state badge, rule editor with connection
  test buttons, service start/stop/restart, and a log viewer.

## Where things live

- Config: `/boot/config/plugins/unraid-disk-event-trigger/rules.json`
- Hysteresis/tracked state (not persisted across reboot):
  `/var/local/emhttp/unraid-disk-event-trigger.state.json`
- Log: `/var/log/unraid-disk-event-trigger.log`

Full source, build instructions, and issue tracker:
https://github.com/maxandcheeses/unraid-disk-event-trigger
