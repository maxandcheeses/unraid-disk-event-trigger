# unraid-disk-event-trigger

Unraid plugin: monitors HDD/SSD temperatures (via Unraid's own SMART cache,
falling back to `smartctl` for disks that are spun up but not yet cached —
spun-down disks are never woken just to poll temp), used disk space
percentage, and parity-check/rebuild activity, firing Tasmota switches or
webhooks when a condition is met.

- Each rule is single-direction: it fires exactly one action (turn something
  ON, or turn it OFF) once its one condition is met. Hysteresis (e.g. "turn a
  fan on at 40°C, off at 35°C") is achieved by creating a *pair* of rules —
  an ON rule and an OFF rule — rather than one rule juggling both directions
  internally. The **Duplicate** button on a rule makes it quick to create the
  matching opposite-direction rule.
- Four trigger types per rule:
  - **Disk temperature** / **Disk usage %** — a threshold over any subset of
    array/cache disks (or "all"), aggregated by max/avg/min.
  - **Parity check** / **Array/data rebuild** — fires directly off Unraid's
    own `mdResync`/`mdResyncAction` state, evaluated fresh every poll cycle
    (an ON rule fires while one is active, an OFF rule while none is - so it
    fires correctly even if the operation was already running before the
    poller noticed, not just at the exact moment it starts/ends) —
    independent of any temperature/usage rules.
- Three action protocols per rule:
  - **HTTP**: Tasmota's native `http://<ip>/cm?cmnd=Power On|Off` API.
  - **MQTT**: a minimal built-in MQTT v3.1.1 QoS0 publisher (no external
    `mosquitto_pub` dependency) — works with Tasmota's MQTT mode or
    zigbee2mqtt. Optional TLS (mqtts) with an "ignore invalid/self-signed
    broker certificates" toggle for LAN brokers without a real CA cert.
  - **Generic HTTP/HTTPS webhook**: method/URL/body/headers, for anything
    Tasmota's fixed API doesn't cover (Home Assistant, Node-RED, other
    automation hubs). Supports optional basic auth and an "ignore invalid/
    self-signed HTTPS certificates" toggle. An optional separate state
    URL/method backs its own "Check Device State" button, which shows the
    raw HTTP status/body verbatim for debugging (unlike HTTP/MQTT, it can't
    infer on/off from an arbitrary endpoint's response).
- Background poller (`/etc/rc.d/rc.unraid-disk-event-trigger`) runs continuously
  at a configurable interval (default 60s), independent of Unraid's cron
  granularity.
- Settings page under **Settings > Utilities > Disk Event Trigger**: live
  disk temps/usage, rule editor with Duplicate/Test buttons, service
  start/stop/restart, and a log viewer.

## Layout

```
source/unraid-disk-event-trigger/          # files as they land on the Unraid filesystem
  usr/local/emhttp/plugins/unraid-disk-event-trigger/
    unraid-disk-event-trigger.page         # webGUI settings page
    include/lib.php                        # config I/O, disk temps/usage, rule engine, HTTP/MQTT/webhook senders
    include/handler.php                    # AJAX endpoint used by the page
    scripts/poll_daemon.php                # the long-running poller
  etc/rc.d/rc.unraid-disk-event-trigger    # init script (start/stop/restart/status)
  boot/config/plugins/unraid-disk-event-trigger/  # persistent config (rules.json)

unraid-disk-event-trigger.plg   # Unraid plugin installer manifest
build.sh                        # packages source/ into unraid-disk-event-trigger.txz and updates the MD5 in the .plg
```

## Building & installing

1. `./build.sh` — packages `source/unraid-disk-event-trigger` into
   `unraid-disk-event-trigger.txz` and patches the MD5 into
   `unraid-disk-event-trigger.plg`. Run this on Unraid (or any Slackware box with
   `makepkg`) for a proper package; elsewhere it falls back to a plain
   tar.xz, which is fine for local inspection but not for real
   installation.
2. Edit `unraid-disk-event-trigger.plg` and set `pluginURL`/`srcURL` to wherever
   you're hosting the files (e.g. a GitHub release).
3. Host `unraid-disk-event-trigger.txz` and `unraid-disk-event-trigger.plg` there.
4. In Unraid: **Plugins > Install Plugin**, paste the `.plg` URL.

## Config format (`/boot/config/plugins/unraid-disk-event-trigger/rules.json`)

```json
{
  "poll_interval": 60,
  "rules": [
    {
      "id": "abc123",
      "name": "Array HDDs hot -> fan ON",
      "enabled": true,
      "disks": ["all"],
      "trigger_type": "temp",
      "aggregate": "max",
      "direction": "on",
      "threshold": 40,
      "delay_seconds": 0,
      "force_resend": false,
      "protocol": "mqtt",
      "mqtt": {
        "host": "192.168.1.10",
        "port": 1883,
        "tls": false,
        "insecure_tls": false,
        "username": "",
        "password": "",
        "topic": "zigbee2mqtt/disk-fan/set",
        "payload": "{\"state\":\"ON\"}"
      }
    },
    {
      "id": "def456",
      "name": "Array HDDs cool -> fan OFF",
      "enabled": true,
      "disks": ["all"],
      "trigger_type": "temp",
      "aggregate": "max",
      "direction": "off",
      "threshold": 35,
      "delay_seconds": 0,
      "protocol": "mqtt",
      "mqtt": {
        "host": "192.168.1.10",
        "port": 1883,
        "topic": "zigbee2mqtt/disk-fan/set",
        "payload": "{\"state\":\"OFF\"}"
      }
    }
  ]
}
```

`trigger_type` is `"temp"`, `"usage"` (used disk space percentage),
`"parity_check"`, or `"rebuild"`. `direction` is `"on"` or `"off"` - the
single action this rule fires. For `temp`/`usage` rules, `threshold` is
compared against the aggregated value (`>=` for an `"on"` rule, `<=` for an
`"off"` rule); `threshold` is unused for `parity_check`/`rebuild` rules,
whose condition is instead just whether the array operation is currently
active (`"on"`) or not (`"off"`), re-checked every poll cycle - so it fires
correctly even if the operation was already underway before the poller
noticed, not just at the instant it starts/ends. `delay_seconds` requires
the condition to hold continuously before firing (0 = immediately).

Per-rule fired/idle state (so a rule doesn't refire every poll cycle once
its condition has fired) is cached at
`/var/local/emhttp/unraid-disk-event-trigger.state.json` (cleared on reboot —
the first poll after boot re-evaluates and re-sends the action if the
condition still holds).
