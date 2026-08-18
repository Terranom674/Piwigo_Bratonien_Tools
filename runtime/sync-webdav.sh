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
: "${WEBDAV_ROOTS_FILE:?WEBDAV_ROOTS_FILE fehlt}"
: "${WEBDAV_SOURCE_DIR:?WEBDAV_SOURCE_DIR fehlt}"
: "${WEBDAV_MAPPING_FILE:?WEBDAV_MAPPING_FILE fehlt}"
: "${MANIFEST:?MANIFEST fehlt}"
: "${SHADOW_MAP_FILE:?SHADOW_MAP_FILE fehlt}"
: "${GALLERY_ROOT:?GALLERY_ROOT fehlt}"
: "${STATE_DIR:?STATE_DIR fehlt}"
: "${STATUS_FILE:?STATUS_FILE fehlt}"

[[ "${SOURCE_MODE:-}" == "webdav-placeholder" ]] || { echo "Falscher SOURCE_MODE: ${SOURCE_MODE:-<leer>}" >&2; exit 1; }
[[ -r "$WEBDAV_PASSWORD_FILE" ]] || { echo "Nextcloud-Passwortdatei fehlt." >&2; exit 1; }
[[ -r "$WEBDAV_ROOTS_FILE" ]] || { echo "WebDAV-Wurzeln fehlen." >&2; exit 1; }

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
LOCK_FILE="$STATE_DIR/webdav-sync.lock"
mkdir -p -- "$STATE_DIR"
exec 9>"$LOCK_FILE"
flock -n 9 || exit 0

write_status() {
    local state="$1" message="$2" detail="${3:-}"
    python3 - "$STATUS_FILE" "$PIWIGO_ROOT" "$CONNECTION_ID" "$state" "$message" "$detail" <<'PY'
import json, os, sys, tempfile, time
status_file, piwigo_root, connection_id, state, message, detail = sys.argv[1:]
payload = {
    "state": state,
    "message": message,
    "timestamp": int(time.time()),
    "auth_mode": "webdav",
    "api": {"state": "not_run", "message": ""},
    "fallback": {"state": "not_run", "message": ""},
    "error_detail": detail if state == "error" else "",
}
public_file = os.path.join(piwigo_root.rstrip('/'), '_data', 'bratonien-tools', 'nc-connector-status', f'connection-{connection_id}.json')
for target in (status_file, public_file):
    directory = os.path.dirname(target)
    os.makedirs(directory, exist_ok=True)
    fd, temporary = tempfile.mkstemp(dir=directory)
    with os.fdopen(fd, 'w', encoding='utf-8') as handle:
        json.dump(payload, handle, ensure_ascii=False)
        handle.write('\n')
    os.chmod(temporary, 0o644)
    os.replace(temporary, target)
PY
}

compact_output() {
    tail -n 12 | tr '\n' ' ' | sed 's/[[:space:]]\+/ /g; s/^[[:space:]]//; s/[[:space:]]$//'
}

failure() {
    local code="$1" command="$2" line="$3"
    trap - ERR
    write_status error "WebDAV-Shadow-Tree fehlgeschlagen" "Exit-Code: $code; Zeile: $line; Befehl: $command"
    exit "$code"
}
trap 'failure $? "$BASH_COMMAND" "$LINENO"' ERR

ROOT_ARGS=()
while IFS=$'\t' read -r path display_name; do
    [[ -z "$path" || "$path" == \#* ]] && continue
    ROOT_ARGS+=(--root "$path")
done < "$WEBDAV_ROOTS_FILE"

[[ ${#ROOT_ARGS[@]} -gt 0 ]] || { write_status error "Keine WebDAV-Wurzeln konfiguriert"; exit 1; }

python3 "$SCRIPT_DIR/lib/build_webdav_placeholder_source.py" \
    --base-url "$WEBDAV_BASE_URL" \
    --user "$WEBDAV_USER" \
    --password-file "$WEBDAV_PASSWORD_FILE" \
    "${ROOT_ARGS[@]}" \
    --source-dir "$WEBDAV_SOURCE_DIR" \
    --manifest "$MANIFEST" \
    --mapping "$WEBDAV_MAPPING_FILE"

python3 "$SCRIPT_DIR/lib/shadow_tree.py" \
    --manifest "$MANIFEST" \
    --destination "$GALLERY_ROOT" \
    --state "$SHADOW_MAP_FILE"

if [[ "${PIWIGO_SYNC_ENABLED:-0}" == "1" ]]; then
    trap - ERR
    PIWIGO_OUTPUT=""
    PIWIGO_EXIT=0
    if PIWIGO_OUTPUT="$(php "$SCRIPT_DIR/lib/piwigo-sync.php" \
        --piwigo-root="$PIWIGO_ROOT" \
        --connection-id="$CONNECTION_ID" \
        --base-url="http://127.0.0.1" 2>&1)"; then
        PIWIGO_EXIT=0
    else
        PIWIGO_EXIT=$?
    fi
    [[ -z "$PIWIGO_OUTPUT" ]] || printf '%s\n' "$PIWIGO_OUTPUT"

    if [[ "$PIWIGO_EXIT" -ne 0 ]]; then
        DETAIL="Exit-Code: $PIWIGO_EXIT"
        if [[ -n "$PIWIGO_OUTPUT" ]]; then
            DETAIL+="; Ausgabe: $(printf '%s\n' "$PIWIGO_OUTPUT" | compact_output)"
        fi
        write_status error "Piwigo-Synchronisierung des WebDAV-Shadow-Trees fehlgeschlagen" "$DETAIL"
        exit "$PIWIGO_EXIT"
    fi

    write_status ok "WebDAV-Shadow-Tree und Piwigo-Synchronisierung erfolgreich"
else
    write_status ok "WebDAV-Shadow-Tree erfolgreich; Registrierung erfolgt im selben Minutenlauf über den bestehenden produktiven Piwigo-Sync"
fi
