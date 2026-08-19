#!/usr/bin/env bash
set -Eeuo pipefail

PIWIGO_ROOT="${1:-/var/www/piwigo}"
PIWIGO_ROOT="${PIWIGO_ROOT%/}"
DATA_DIR="$PIWIGO_ROOT/_data"

[[ -d "$DATA_DIR" ]] || { echo "Piwigo-_data wurde nicht gefunden: $DATA_DIR" >&2; exit 1; }
[[ "$(id -u)" -eq 0 ]] || { echo "Die Reparatur muss als root ausgeführt werden." >&2; exit 1; }

DATA_UID="$(stat -c '%u' "$DATA_DIR")"
DATA_GID="$(stat -c '%g' "$DATA_DIR")"

PATHS=(
  "$DATA_DIR/bratonien-tools/nc-webdav-gallery"
  "$DATA_DIR/bratonien-tools/nc-webdav-preview"
  "$DATA_DIR/i/_data/bratonien-tools/nc-webdav-gallery"
  "$DATA_DIR/i/bratonien-watermark"
)

found=0
for path in "${PATHS[@]}"; do
  [[ -e "$path" ]] || continue
  found=1
  echo "Repariere: $path"
  chown -R "$DATA_UID:$DATA_GID" -- "$path"
  find "$path" -type d -exec chmod 2775 {} +
  find "$path" -type f -exec chmod 0664 {} +
done

if [[ "$found" -eq 0 ]]; then
  echo "Keine Bratonien-Cache-Verzeichnisse gefunden."
  exit 0
fi

echo "Bratonien-Cache-Rechte wurden an $DATA_DIR angeglichen (UID $DATA_UID, GID $DATA_GID)."
