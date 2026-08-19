#!/usr/bin/env bash
set -Eeuo pipefail

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

php "$SCRIPT_DIR/lib/precache-webdav-previews.php" \
    --mapping="$WEBDAV_MAPPING_FILE" \
    --base-url="$WEBDAV_BASE_URL" \
    --user="$WEBDAV_USER" \
    --password-file="$WEBDAV_PASSWORD_FILE" \
    --cache-dir="$PREVIEW_CACHE"

php "$SCRIPT_DIR/lib/build-webdav-derivatives.php" \
    --piwigo-root="$PIWIGO_ROOT" \
    --connection-id="$CONNECTION_ID"
