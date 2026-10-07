#!/usr/bin/env bash
set -euo pipefail

# One-time sudo helper: point system queue workers at web-cesa-new and
# include all production queues (notifications, whatsapp, exports, AI, default).

UNIT_SRC="/var/www/web-cesa-new/deploy/systemd/web-cesa-queue@.service"
UNIT_DST="/etc/systemd/system/web-cesa-queue@.service"

if [[ ! -f "$UNIT_SRC" ]]; then
  echo "Missing unit source: $UNIT_SRC" >&2
  exit 1
fi

sudo cp "$UNIT_SRC" "$UNIT_DST"
sudo systemctl daemon-reload
sudo systemctl enable --now web-cesa-queue@1.service web-cesa-queue@2.service
sudo systemctl restart web-cesa-queue@1.service web-cesa-queue@2.service

# Disable obsolete split workers that still point at the legacy tree.
sudo systemctl disable --now web-cesa-notifications.service web-cesa-whatsapp.service 2>/dev/null || true
sudo systemctl disable --now 'web-cesa-default@1.service' 'web-cesa-default@2.service' 2>/dev/null || true

cd /var/www/web-cesa-new
php artisan queue:restart

echo "System queue workers retargeted to /var/www/web-cesa-new"
systemctl status 'web-cesa-queue@1' 'web-cesa-queue@2' --no-pager
