#!/bin/bash
# Build the unraid-disk-event-trigger.txz package from source/ and patch the .plg
# with its MD5. Run on Unraid (has makepkg) or any Linux/macOS box (falls
# back to plain tar).
set -euo pipefail

NAME="unraid-disk-event-trigger"
ROOT="$(cd "$(dirname "$0")" && pwd)"
SRC="$ROOT/source/$NAME"
OUT="$ROOT/$NAME.txz"

chmod +x "$SRC/etc/rc.d/rc.$NAME"
chmod +x "$SRC/usr/local/emhttp/plugins/$NAME/scripts/poll_daemon.php"

cd "$SRC"
if command -v makepkg >/dev/null 2>&1; then
    makepkg -l y -c n "$OUT"
else
    echo "makepkg not found, falling back to plain tar+xz (fine for local testing," \
         "but prefer running this on Unraid/Slackware for a proper package)."
    find . -mindepth 1 | sed 's|^\./||' | tar -cJf "$OUT" -T -
fi
cd "$ROOT"

MD5=$(md5sum "$OUT" 2>/dev/null | awk '{print $1}' || md5 -q "$OUT")
echo "Built $OUT"
echo "MD5: $MD5"

sed -i.bak "s/REPLACE_WITH_MD5\|srcMD5     \"[a-f0-9]*\"/srcMD5     \"$MD5\"/" "$ROOT/$NAME.plg" 2>/dev/null || true
sed -i '' "s#<!ENTITY srcMD5     \".*\">#<!ENTITY srcMD5     \"$MD5\">#" "$ROOT/$NAME.plg" 2>/dev/null || \
sed -i "s#<!ENTITY srcMD5     \".*\">#<!ENTITY srcMD5     \"$MD5\">#" "$ROOT/$NAME.plg"
rm -f "$ROOT/$NAME.plg.bak"

echo "Updated $NAME.plg with new MD5."
echo "Next: host $NAME.txz and $NAME.plg (e.g. a GitHub release), update srcURL/pluginURL in the .plg, then install via Unraid Plugins > Install Plugin using the .plg URL."
