#!/usr/bin/env bash
set -Eeuo pipefail

CONFIG_DIR="/etc/bratonien-tools/nc-connector"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
shopt -s nullglob

if ! php "$SCRIPT_DIR/reconcile.php"; then
    echo "NC Connector: gespeicherte Verbindungen konnten nicht mit der Runtime abgeglichen werden." >&2
    exit 1
fi

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

    piwigo_root="$(sed -n 's/^PIWIGO_ROOT=//p' "$config" | tail -n 1)"
    piwigo_root="${piwigo_root%\"}"
    piwigo_root="${piwigo_root#\"}"
    piwigo_root="${piwigo_root%\'}"
    piwigo_root="${piwigo_root#\'}"
    [[ -n "$piwigo_root" ]] || piwigo_root="/var/www/piwigo"
    tombstone_dir="${piwigo_root%/}/_data/bratonien-tools/nc-connector-status"

    if [[ -n "$connection_id" && -f "$tombstone_dir/deleted-$connection_id" ]]; then
        echo "NC Connector: Verbindung $connection_id wurde geloescht; Laufzeitdateien werden entfernt."
        rm -f -- "$CONFIG_DIR/connection-$connection_id.conf" \
            "$CONFIG_DIR/connection-$connection_id.db-password" \
            "$CONFIG_DIR/connection-$connection_id.piwigo-password" \
            "$CONFIG_DIR/connection-$connection_id.storages.tsv" \
            "$CONFIG_DIR/connection-$connection_id.roots.tsv"
        rm -f -- "$tombstone_dir/deleted-$connection_id"
        continue
    fi

    echo "NC Connector: $name"
    if ! env PIWIGO_CONFIG="$config" bash "$SCRIPT_DIR/sync.sh"; then
        result=1
    fi
done

exit "$result"
