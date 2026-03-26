#!/usr/bin/env bash
#
# FarmPulse HiveOS sidecar — bootstrap (run as root).
#
#   wget -qO- https://YOUR_DOMAIN/client/install.sh | bash -s -- https://YOUR_DOMAIN
#
# Does not change Hive OS cloud URL or hive-agent. Installs a separate timer that
# talks only to FarmPulse /api/worker/api.php.
#
set -euo pipefail

BASE="${1:-${FARMPULSE_BASE_URL:-}}"
if [ -z "$BASE" ]; then
  echo "Usage: bash install.sh https://YOUR_DOMAIN" >&2
  echo "  or:  FARMPULSE_BASE_URL=https://YOUR_DOMAIN bash install.sh" >&2
  exit 1
fi
BASE="${BASE%/}"

if [ "${EUID:-$(id -u)}" -ne 0 ]; then
  echo "Run as root (e.g. sudo bash)." >&2
  exit 1
fi

BIN=/opt/farmpulse/bin
STATE=/var/lib/farmpulse
SYSTEMD=/etc/systemd/system
CURL=(curl -fsSL --connect-timeout 15 --retry 2)

mkdir -p "$BIN" "$STATE" "$SYSTEMD"

download() {
  local name="$1"
  local out="$2"
  echo "Downloading ${BASE}/client/${name} ..."
  "${CURL[@]}" "${BASE}/client/${name}" -o "$out"
  chmod 755 "$out" 2>/dev/null || chmod +x "$out"
}

download sidecar.sh "$BIN/sidecar.sh"
download firstrun_farmpulse.sh "$BIN/firstrun_farmpulse.sh"
download uninstall.sh "$BIN/uninstall.sh"
download systemd/farmpulse-sidecar.service "$SYSTEMD/farmpulse-sidecar.service"
download systemd/farmpulse-sidecar.timer "$SYSTEMD/farmpulse-sidecar.timer"

chmod 644 "$SYSTEMD/farmpulse-sidecar.service" "$SYSTEMD/farmpulse-sidecar.timer"

ln -sf "$BIN/firstrun_farmpulse.sh" /usr/local/bin/firstrun_farmpulse

systemctl daemon-reload

echo ""
echo "FarmPulse client files installed under /opt/farmpulse/bin"
echo "Next: run  firstrun_farmpulse  (or $BIN/firstrun_farmpulse.sh)"
echo "      Enter FarmPulse URL, rig id and password from the web panel."
echo ""
