#!/usr/bin/env bash
set -Eeuo pipefail

CONFIG_DIR="/etc/bratonien-tools/nc-connector"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
shopt -s nullglob

if ! php "$SCRIPT_DIR/reconcile-webdav.php"; then
    echo "NC Connector: WebDAV-Verbindungen konnten nicht mit der Runtime abgeglichen werden." >&2
    exit 1
fi

if ! php "$SCRIPT_DIR/repair-webdav-orphans.php"; then
    echo "NC Connector: verwaiste WebDAV-Bilddatensaetze konnten nicht repariert werden." >&2
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
    state_dir="$(PIWIGO_CONFIG="$config" bash -c 'source "$PIWIGO_CONFIG"; printf "%s" "${STATE_DIR:-}"')"
    change_file="${state_dir%/}/webdav-sync-content-changed"

    if env PIWIGO_CONFIG="$config" bash "$SCRIPT_DIR/sync-webdav.sh"; then
        # Nur ein tatsaechlich geaenderter Connector-Bestand darf einen
        # laufenden Cache-Rebuild priorisiert unterbrechen. Ein erfolgreicher
        # Sync ohne Aenderung ist kein neuer Inhalt.
        if [[ -f "$change_file" && -f "$SCRIPT_DIR/webdav-warmup-dispatch.php" && "$name" =~ ^webdav-connection-([0-9]+)\.conf$ ]]; then
            connection_id="${BASH_REMATCH[1]}"
            php "$SCRIPT_DIR/webdav-warmup-dispatch.php" --mode=sync --connection-id="$connection_id" || result=1
            rm -f -- "$change_file"
        else
            echo "NC Connector WebDAV: keine Inhaltsaenderung, kein priorisierter Warmup erforderlich."
        fi
    else
        result=1
    fi
done

# Einzelne neue/geänderte Bilder in bereits bekannten Alben werden weiterhin
# über die periodische Eingangsprüfung behandelt. Der Dispatcher startet die
# eigentliche Prüfung erst, wenn das konfigurierte Intervall fällig ist.
if [[ "$result" -eq 0 && -f "$SCRIPT_DIR/webdav-warmup-dispatch.php" ]]; then
    php "$SCRIPT_DIR/webdav-warmup-dispatch.php" --mode=periodic || result=1
fi

exit "$result"
