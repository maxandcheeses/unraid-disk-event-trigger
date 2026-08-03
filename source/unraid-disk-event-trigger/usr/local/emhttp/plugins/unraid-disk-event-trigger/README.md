#### Disk Event Trigger

Monitors array/cache disk temperatures, used space percentage, and parity
check/rebuild activity, firing a Tasmota HTTP/MQTT (TLS supported) switch or
a generic HTTP/HTTPS webhook when a condition is met. Each rule fires one
direction (on or off); pair an on-rule and an off-rule (Duplicate button
included) for hysteresis. Optional force-resend and device-state
verification. Settings under Settings → Disk Event Trigger.

**GitHub:** https://github.com/maxandcheeses/unraid-disk-event-trigger
