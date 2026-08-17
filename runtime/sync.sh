#!/usr/bin/env bash
set -Eeuo pipefail

CONFIG_FILE="${PIWIGO_CONFIG:-/etc/bratonien-tools/nc-connector/connection-1.conf}"
[[ -r "$CONFIG_FILE" ]] || { echo "Konfiguration fehlt: $CONFIG_FILE" >&2; exit 1; }

# Preserve an explicit per-run override before the connection config is sourced.
# This allows controlled shadow-tree updates without running the productive
# Piwigo database synchronization, while leaving the stored config untouched.
PIWIGO_SYNC_OVERRIDE_VALUE="${PIWIGO_SYNC_OVERRIDE-}"

# shellcheck source=/dev/null
source "$CONFIG_FILE"

if [[ -n "$PIWIGO_SYNC_OVERRIDE_VALUE" ]]; then
    case "$PIWIGO_SYNC_OVERRIDE_VALUE" in
        0|1) PIWIGO_SYNC_ENABLED="$PIWIGO_SYNC_OVERRIDE_VALUE" ;;
        *) echo "PIWIGO_SYNC_OVERRIDE muss 0 oder 1 sein." >&2; exit 1 ;;
    esac
fi

install -d -m 0750 "$STATE_DIR"
MANIFEST="$STATE_DIR/manifest.tsv"
MAP_FILE="$STATE_DIR/name-map.json"
LOCK_FILE="$STATE_DIR/sync.lock"
ACTIVITY_STATE="$STATE_DIR/activity.json"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

exec 9>"$LOCK_FILE"
flock -n 9 || exit 0

write_status() {
    local state="$1" message="$2"
    python3 - "$STATUS_FILE" "$state" "$message" <<'PY'
import json, os, sys, tempfile, time
path, state, message = sys.argv[1:]
os.makedirs(os.path.dirname(path), exist_ok=True)
payload = {"state": state, "message": message, "timestamp": int(time.time())}
fd, temporary = tempfile.mkstemp(dir=os.path.dirname(path))
with os.fdopen(fd, "w", encoding="utf-8") as handle:
    json.dump(payload, handle, ensure_ascii=False)
    handle.write("\n")
os.chmod(temporary, 0o644)
os.replace(temporary, path)
PY
}

failure() {
    write_status error "Synchronisierung fehlgeschlagen; bestehende Galerie blieb unverändert"
}
trap failure ERR

NEEDS_LOCAL_REPAIR=0
if [[ ! -s "$MAP_FILE" ]]; then
    NEEDS_LOCAL_REPAIR=1
else
    set +e
    python3 - "$MAP_FILE" "$GALLERY_ROOT" <<'PY'
import json
import sys
from pathlib import Path

mapping = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
gallery = Path(sys.argv[2])
roots = [
    target for source, target in mapping.items()
    if source.startswith("share:") and "/" not in source
]
raise SystemExit(0 if roots and all((gallery / target).is_dir() for target in roots) else 1)
PY
    ROOTS_INTACT=$?
    set -e
    [[ "$ROOTS_INTACT" == "0" ]] || NEEDS_LOCAL_REPAIR=1
fi

if [[ "$NEEDS_LOCAL_REPAIR" == "0" && "${PIWIGO_SYNC_ENABLED:-0}" == "1" ]]; then
    set +e
    php "$SCRIPT_DIR/lib/piwigo-db-check.php" "$MAP_FILE" "$PIWIGO_ROOT"
    PIWIGO_ALBUMS_INTACT=$?
    set -e
    [[ "$PIWIGO_ALBUMS_INTACT" == "0" ]] || NEEDS_LOCAL_REPAIR=1
fi

if [[ "$NEEDS_LOCAL_REPAIR" == "1" ]]; then
    GATE_RESULT=0
else
    if python3 "$SCRIPT_DIR/lib/activity_gate.py" check \
        --state "$ACTIVITY_STATE" --host "$NC_DB_HOST" --port "$NC_DB_PORT" \
        --database "$NC_DB_NAME" --user "$NC_DB_USER" --password-file "$NC_DB_PASSWORD_FILE" \
        --quiet "$QUIET_SECONDS" --max-wait "$MAX_WAIT_SECONDS" --full-after "$FULL_SYNC_SECONDS"; then
        GATE_RESULT=0
    else
        GATE_RESULT=$?
    fi
fi

if [[ "$GATE_RESULT" == "3" ]]; then
    trap - ERR
    write_status ok "Keine Änderungen gefunden"
    exit 0
fi
[[ "$GATE_RESULT" == "0" ]] || exit "$GATE_RESULT"

python3 "$SCRIPT_DIR/lib/build_manifest.py" \
    --host "$NC_DB_HOST" --port "$NC_DB_PORT" --database "$NC_DB_NAME" --user "$NC_DB_USER" \
    --password-file "$NC_DB_PASSWORD_FILE" --view "$NC_DB_VIEW" \
    --storage-config "$STORAGE_CONFIG" --output "$MANIFEST"

python3 "$SCRIPT_DIR/lib/shadow_tree.py" \
    --manifest "$MANIFEST" --destination "$GALLERY_ROOT" --state "$MAP_FILE"

if [[ "${PIWIGO_SYNC_ENABLED:-0}" == "1" ]]; then
    [[ -r "${PIWIGO_SYNC_PASSWORD_FILE:?}" ]] || {
        echo "Piwigo-Passwortdatei fehlt oder ist nicht lesbar." >&2
        exit 1
    }
    perl "$SCRIPT_DIR/lib/piwigo-db-sync.pl" \
        --base-url="http://127.0.0.1" \
        --username="${PIWIGO_SYNC_USER:?}" \
        --password-file="$PIWIGO_SYNC_PASSWORD_FILE"
fi

python3 "$SCRIPT_DIR/lib/activity_gate.py" commit \
    --state "$ACTIVITY_STATE" --host "$NC_DB_HOST" --port "$NC_DB_PORT" \
    --database "$NC_DB_NAME" --user "$NC_DB_USER" --password-file "$NC_DB_PASSWORD_FILE"

trap - ERR
write_status ok "Synchronisierung erfolgreich"
