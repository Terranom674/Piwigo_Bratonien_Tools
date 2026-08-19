#!/usr/bin/env bash
set -Eeuo pipefail

[[ $EUID -eq 0 ]] || { echo "Bitte als root ausfuehren." >&2; exit 1; }

cat >/etc/sudoers.d/bratonien-nc-connector-web <<'EOF'
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl start --no-block bratonien-nc-connector.service
EOF
chmod 0440 /etc/sudoers.d/bratonien-nc-connector-web
visudo -cf /etc/sudoers.d/bratonien-nc-connector-web
