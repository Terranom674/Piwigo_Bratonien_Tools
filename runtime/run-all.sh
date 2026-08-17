#!/usr/bin/env bash
set -Eeuo pipefail

CONFIG_DIR="/etc/bratonien-tools/nc-connector"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
shopt -s nullglob
configs=("$CONFIG_DIR"/connection-*.conf)

if [[ ${#configs[@]} -eq 0 ]]; then
    echo "Keine aktiven NC-Connector-Verbindungen konfiguriert."
    exit 0
fi

result=0
for config in "${configs[@]}"; do
    echo "NC Connector: $(basename "$config")"
    if ! env PIWIGO_CONFIG="$config" bash "$SCRIPT_DIR/sync.sh"; then
        result=1
    fi
done

exit "$result"
