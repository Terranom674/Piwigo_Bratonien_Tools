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
    echo "NC Connector: WebDAV-Verbindungen konnten nicht mit der Runtime abgeglichen werden." >&2
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

# WebDAV ist ab 0.9.6.2 der primaere Produktionsweg.
# Die alte lokale Verbindung bleibt unveraendert vorhanden, wird aber nur noch
# ausgefuehrt, wenn kein WebDAV-Lauf erfolgreich abgeschlossen wurde. Dadurch
# gibt es keinen doppelten regulaeren Import mehr, waehrend der alte Weg als
# echter Rueckfall erhalten bleibt.
webdav_success=0
webdav_failed=0

for config in "${webdav_configs[@]}"; do
    name="$(basename "$config")"
    echo "NC Connector WebDAV primaer: $name"
    if env PIWIGO_CONFIG="$config" bash "$SCRIPT_DIR/sync-webdav.sh"; then
        webdav_success=1
    else
        webdav_failed=1
        echo "NC Connector: WebDAV-Lauf fehlgeschlagen; Legacy-Fallback bleibt verfuegbar." >&2
    fi
done

if [[ "$webdav_success" -eq 1 ]]; then
    echo "NC Connector: WebDAV erfolgreich; Legacy-Verbindung wird in diesem Lauf nicht ausgefuehrt."
    exit 0
fi

if [[ ${#configs[@]} -eq 0 ]]; then
    echo "NC Connector: WebDAV ist fehlgeschlagen und es ist keine Legacy-Fallback-Verbindung vorhanden." >&2
    exit 1
fi

echo "NC Connector: kein erfolgreicher WebDAV-Lauf; Legacy-Fallback wird ausgefuehrt."
legacy_result=0

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

    echo "NC Connector Legacy-Fallback: $name"
    if ! env PIWIGO_CONFIG="$config" bash "$SCRIPT_DIR/sync.sh"; then
        legacy_result=1
    fi
done

if [[ "$legacy_result" -eq 0 ]]; then
    echo "NC Connector: Legacy-Fallback erfolgreich."
    exit 0
fi

if [[ "$webdav_failed" -eq 1 ]]; then
    echo "NC Connector: WebDAV und Legacy-Fallback sind fehlgeschlagen." >&2
fi
exit 1
