#!/usr/bin/env bash
set -Eeuo pipefail

CONFIG_DIR="/etc/bratonien-tools/nc-connector"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
shopt -s nullglob

if ! php "$SCRIPT_DIR/reconcile.php"; then
    echo "NC Connector: gespeicherte lokale Verbindungen konnten nicht mit der Runtime abgeglichen werden." >&2
    exit 1
fi

if ! php "$SCRIPT_DIR/reconcile-webdav.php"; then
    echo "NC Connector: WebDAV-Testverbindungen konnten nicht mit der parallelen Runtime abgeglichen werden." >&2
    exit 1
fi

# Nach dem Reconcile kennt Piwigo alle noch aktiven WebDAV-Verbindungen. Jetzt
# werden Sites, Alben, Bilddatensaetze und Derivate geloeschter Verbindungen
# entfernt. Das erfasst auch Verbindungen, die bereits vor diesem Update
# geloescht wurden.
if ! php "$SCRIPT_DIR/cleanup-webdav-piwigo.php"; then
    echo "NC Connector: Piwigo-Inhalte geloeschter WebDAV-Verbindungen konnten nicht bereinigt werden." >&2
    exit 1
fi

if ! php "$SCRIPT_DIR/cleanup-stale.php"; then
    echo "NC Connector: verwaiste Laufzeitdateien konnten nicht bereinigt werden." >&2
    exit 1
fi

configs=("$CONFIG_DIR"/connection-*.conf)
webdav_configs=("$CONFIG_DIR"/webdav-connection-*.conf)

if [[ ${#configs[@]} -eq 0 && ${#webdav_configs[@]} -eq 0 ]]; then
    echo "Keine NC-Connector-Verbindungen konfiguriert."
    exit 0
fi

result=0

# WebDAV zuerst: Der parallele Shadow Tree muss bereits vollständig stehen,
# bevor der weiterhin produktive lokale Weg seine normale Piwigo-
# Dateisynchronisierung ausführt. So wird der neue Baum im selben Minutenlauf
# sichtbar, ohne dass der WebDAV-Zweig selbst eine zweite globale Piwigo-
# Synchronisierung startet.
for config in "${webdav_configs[@]}"; do
    name="$(basename "$config")"
    echo "NC Connector WebDAV parallel: $name"
    if ! env PIWIGO_CONFIG="$config" bash "$SCRIPT_DIR/sync-webdav.sh"; then
        result=1
    fi
done

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

    echo "NC Connector lokal: $name"
    if ! env PIWIGO_CONFIG="$config" bash "$SCRIPT_DIR/sync.sh"; then
        result=1
    fi
done

exit "$result"
