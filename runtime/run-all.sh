#!/usr/bin/env bash
set -Eeuo pipefail

CONFIG_DIR="/etc/bratonien-tools/nc-connector"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
shopt -s nullglob

if ! php "$SCRIPT_DIR/reconcile-webdav.php"; then
    echo "NC Connector: WebDAV-Verbindungen konnten nicht mit der Runtime abgeglichen werden." >&2
    exit 1
fi

if ! php "$SCRIPT_DIR/cleanup-webdav-piwigo.php"; then
    echo "NC Connector: Piwigo-Inhalte geloeschter WebDAV-Verbindungen konnten nicht bereinigt werden." >&2
    exit 1
fi

webdav_configs=("$CONFIG_DIR"/webdav-connection-*.conf)

if [[ ${#webdav_configs[@]} -eq 0 ]]; then
    echo "Keine WebDAV-Connector-Verbindungen konfiguriert."
    exit 0
fi

result=0
for config in "${webdav_configs[@]}"; do
    name="$(basename "$config")"
    echo "NC Connector WebDAV: $name"
    if ! env PIWIGO_CONFIG="$config" bash "$SCRIPT_DIR/sync-webdav.sh"; then
        result=1
    fi
done

exit "$result"
