# unraid-disk-event-trigger

Unraid plugin: monitors HDD/SSD temperatures (via Unraid's own SMART cache,
falling back to `smartctl` for disks that are spun up but not yet cached —
spun-down disks are never woken just to poll temp) and/or used disk space
percentage (from Unraid's own cached filesystem stats, safe to read even for
spun-down disks), and fires Tasmota switches or webhooks when configurable
thresholds are crossed, with hysteresis (separate on/off thresholds) so a
switch doesn't chatter near the threshold.

- Multiple independent rules, each covering any subset of array/cache disks
  (or "all"), aggregated by max/avg/min.
- Per-rule trigger metric: disk **temperature** or disk **used space %** —
  e.g. spin up a backup drive when the array crosses a capacity threshold,
  independent of any temperature-based rules.
- Three trigger protocols per rule:
  - **HTTP**: Tasmota's native `http://<ip>/cm?cmnd=Power On|Off` API.
  - **MQTT**: a minimal built-in MQTT v3.1.1 QoS0 publisher (no external
    `mosquitto_pub` dependency) — works with Tasmota's MQTT mode or
    zigbee2mqtt (e.g. topic `zigbee2mqtt/<device>/set`, payload
    `{"state":"ON"}`). Optional TLS (mqtts) with an "ignore invalid/
    self-signed broker certificates" toggle for LAN brokers without a real
    CA cert.
  - **Generic HTTP/HTTPS webhook**: independent method/URL/body/headers for
    ON and OFF, for anything Tasmota's fixed API doesn't cover (Home
    Assistant, Node-RED, other automation hubs). Supports optional basic
    auth and an "ignore invalid/self-signed HTTPS certificates" toggle for
    LAN endpoints with self-signed certs. An optional separate state
    URL/method backs its own "Check Device State" button, which shows the
    raw HTTP status/body verbatim for debugging (unlike HTTP/MQTT, it
    can't infer on/off from an arbitrary endpoint's response).
- Background poller (`/etc/rc.d/rc.unraid-disk-event-trigger`) runs continuously
  at a configurable interval (default 60s), independent of Unraid's cron
  granularity.
- Per-rule "also force ON during parity check / array rebuild" options —
  disks run much hotter during these operations, so a rule can opt to
  bypass the temperature threshold and switch on immediately for the
  duration (detected from Unraid's own `mdResync`/`mdResyncAction` state),
  then fall back to normal temp-based hysteresis once it finishes.
- Settings page under **Settings > Utilities > Disk Event Trigger**: live
  disk temps, rule editor, per-rule "Test ON/OFF" buttons, service
  start/stop/restart, and a log viewer.

## Layout

```
source/unraid-disk-event-trigger/
    files as they land on the Unraid filesystem

  usr/local/emhttp/plugins/unraid-disk-event-trigger/
    unraid-disk-event-trigger.page
        webGUI settings page
    include/lib.php
        config I/O, disk temps/usage, rule engine, HTTP/MQTT/webhook senders
    include/handler.php
        AJAX endpoint used by the page
    scripts/poll_daemon.php
        the long-running poller

  etc/rc.d/rc.unraid-disk-event-trigger
    init script (start/stop/restart/status)

  boot/config/plugins/unraid-disk-event-trigger/
    persistent config (rules.json)

unraid-disk-event-trigger.plg
    Unraid plugin installer manifest

build.sh
    packages source/ into unraid-disk-event-trigger.txz and updates the MD5 in the .plg
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
      "name": "Array HDDs",
      "enabled": true,
      "disks": ["all"],
      "trigger_type": "temp",
      "aggregate": "max",
      "on_temp": 40,
      "off_temp": 35,
      "protocol": "mqtt",
      "mqtt": {
        "host": "192.168.1.10",
        "port": 1883,
        "tls": false,
        "insecure_tls": false,
        "username": "",
        "password": "",
        "on_topic": "zigbee2mqtt/disk-fan/set",
        "on_payload": "{\"state\":\"ON\"}",
        "off_topic": "zigbee2mqtt/disk-fan/set",
        "off_payload": "{\"state\":\"OFF\"}"
      }
    }
  ]
}
```

`trigger_type` is `"temp"` (default) or `"usage"` (used disk space
percentage). `on_temp`/`off_temp` are reused as the generic threshold values
for whichever metric is selected (kept as-named for config backward
compatibility) — e.g. with `trigger_type: "usage"`, `on_temp: 85` means
"turn ON once usage reaches 85%".

Relay on/off state per rule (for hysteresis) is cached at
`/var/local/emhttp/unraid-disk-event-trigger.state.json` (cleared on reboot —
the first poll after boot re-evaluates and re-sends the command).
