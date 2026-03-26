#!/usr/bin/env bash
#
# Remove FarmPulse sidecar (Hive OS cloud settings are not touched).
#
set -euo pipefail

if [ "${EUID:-$(id -u)}" -ne 0 ]; then
  echo "Run as root." >&2
  exit 1
fi

systemctl disable --now farmpulse-sidecar.timer 2>/dev/null || true
rm -f /etc/systemd/system/farmpulse-sidecar.timer
rm -f /etc/systemd/system/farmpulse-sidecar.service
rm -rf /etc/systemd/system/farmpulse-sidecar.timer.d
systemctl daemon-reload

rm -f /usr/local/bin/firstrun_farmpulse
rm -rf /opt/farmpulse
rm -f /etc/farmpulse.env
rm -rf /var/lib/farmpulse

echo "FarmPulse sidecar removed."
