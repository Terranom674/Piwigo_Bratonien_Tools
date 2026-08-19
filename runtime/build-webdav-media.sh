#!/usr/bin/env bash
set -Eeuo pipefail
umask 0002

CONFIG_FILE="${PIWIGO_CONFIG:-}"
[[ -n "$CONFIG_FILE" && -r "$CONFIG_FILE" ]] || { echo "WebDAV-Konfiguration fehlt: ${CONFIG_FILE:-<leer>}" >&2; exit 1; }

# shellcheck source=/dev/null
source "$CONFIG_FILE"

: "${PIWIGO_ROOT:?PIWIGO_ROOT fehlt}"
: "${CONNECTION_ID:?CONNECTION_ID fehlt}"
: "${WEBDAV_BASE_URL:?WEBDAV_BASE_URL fehlt}"
: "${WEBDAV_USER:?WEBDAV_USER fehlt}"
: "${WEBDAV_PASSWORD_FILE:?WEBDAV_PASSWORD_FILE fehlt}"
: "${WEBDAV_MAPPING_FILE:?WEBDAV_MAPPING_FILE fehlt}"
: "${STATE_DIR:?STATE_DIR fehlt}"

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
LOCK_FILE="$STATE_DIR/webdav-media.lock"
mkdir -p -- "$STATE_DIR"
exec 9>"$LOCK_FILE"
flock -n 9 || exit 0

PREVIEW_CACHE="$PIWIGO_ROOT/_data/bratonien-tools/nc-webdav-preview/connection-$CONNECTION_ID"
SOURCE_CACHE="$PIWIGO_ROOT/_data/bratonien-tools/nc-webdav-gallery/connection-$CONNECTION_ID"
DERIVATIVE_CACHE="$PIWIGO_ROOT/_data/i/_data/bratonien-tools/nc-webdav-gallery/connection-$CONNECTION_ID"
PIWIGO_DATA="$PIWIGO_ROOT/_data"

normalize_connector_cache_permissions() {
    [[ -d "$PIWIGO_DATA" ]] || return 0

    local data_uid data_gid current_uid path
    data_uid="$(stat -c '%u' "$PIWIGO_DATA")"
    data_gid="$(stat -c '%g' "$PIWIGO_DATA")"
    current_uid="$(id -u)"

    for path in "$SOURCE_CACHE" "$PREVIEW_CACHE" "$DERIVATIVE_CACHE"; do
        [[ -e "$path" ]] || continue

        if [[ "$current_uid" -eq 0 ]]; then
            chown -R "$data_uid:$data_gid" -- "$path"
        fi

        if ! find "$path" -type d -exec chmod 2775 {} + 2>/dev/null; then
            echo "Hinweis: Verzeichnisrechte konnten ohne Root-Rechte nicht vollständig repariert werden: $path" >&2
        fi
        if ! find "$path" -type f -exec chmod 0664 {} + 2>/dev/null; then
            echo "Hinweis: Dateirechte konnten ohne Root-Rechte nicht vollständig repariert werden: $path" >&2
        fi
    done
}

# Altbestände aus früheren root/systemd-Läufen werden repariert, sobald dieser
# Lauf mit ausreichenden Rechten ausgeführt wird. Bei normalen Webserver-Läufen
# werden zumindest alle eigenen Dateien gruppenschreibbar gehalten.
normalize_connector_cache_permissions

# Dieser Medienlauf wird erst nach einem erfolgreichen WebDAV-Aufbau und einer
# erfolgreichen Piwigo-Synchronisierung gestartet. Deshalb darf erst hier aus
# "lokale Connector-Datei fehlt" auf eine in Nextcloud entfernte Freigabe/Datei
# geschlossen werden. Bei einer nicht erreichbaren Verbindung wird dieser Block
# niemals ausgeführt und der vorhandene Piwigo-Bestand bleibt unverändert.
php "$SCRIPT_DIR/cleanup-missing-webdav-images.php" \
    --piwigo-root="$PIWIGO_ROOT" \
    --connection-id="$CONNECTION_ID"

php "$SCRIPT_DIR/lib/precache-webdav-previews.php" \
    --mapping="$WEBDAV_MAPPING_FILE" \
    --base-url="$WEBDAV_BASE_URL" \
    --user="$WEBDAV_USER" \
    --password-file="$WEBDAV_PASSWORD_FILE" \
    --cache-dir="$PREVIEW_CACHE"

php "$SCRIPT_DIR/lib/build-webdav-derivatives.php" \
    --piwigo-root="$PIWIGO_ROOT" \
    --connection-id="$CONNECTION_ID"

normalize_connector_cache_permissions
