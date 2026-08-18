#!/usr/bin/env bash
set -Eeuo pipefail

CONFIG_DIR="/etc/bratonien-tools/nc-connector"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PIWIGO_ROOT="${PIWIGO_ROOT:-/var/www/piwigo}"
TOMBSTONE_DIR="${PIWIGO_ROOT%/}/_data/bratonien-tools/nc-connector-status"
shopt -s nullglob
configs=("$CONFIG_DIR"/connection-*.conf)

if [[ ${#configs[@]} -eq 0 ]]; then
    echo "Keine aktiven NC-Connector-Verbindungen konfiguriert."
    exit 0
fi

result=0
for config in "${configs[@]}"; do
    name="$(basename "$config")"
    connection_id=""
    if [[ "$name" =~ ^connection-([0-9]+)\.conf$ ]]; then
        connection_id="${BASH_REMATCH[1]}"
    fi

    if [[ -n "$connection_id" && -f "$TOMBSTONE_DIR/deleted-$connection_id" ]]; then
        echo "NC Connector: Verbindung $connection_id wurde geloescht; Laufzeitdateien werden entfernt."
        rm -f -- "$CONFIG_DIR/connection-$connection_id.conf" \
            "$CONFIG_DIR/connection-$connection_id.db-password" \
            "$CONFIG_DIR/connection-$connection_id.piwigo-password" \
            "$CONFIG_DIR/connection-$connection_id.storages.tsv"
        continue
    fi

    echo "NC Connector: $name"
    if ! env PIWIGO_CONFIG="$config" bash "$SCRIPT_DIR/sync.sh"; then
        result=1
    fi
done

exit "$result"
