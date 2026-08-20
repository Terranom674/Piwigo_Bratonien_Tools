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
    local state="$1" message="$2" detail="${3:-}" auth_mode="${4:-webdav}" api_state="${5:-not_run}" api_message="${6:-}" fallback_state="${7:-not_run}" fallback_message="${8:-}"
    python3 - "$STATUS_FILE" "$PIWIGO_ROOT" "$CONNECTION_ID" "$state" "$message" "$detail" "$auth_mode" "$api_state" "$api_message" "$fallback_state" "$fallback_message" <<'PY'
import json, os, sys, tempfile, time
(status_file, piwigo_root, connection_id, state, message, detail, auth_mode,
 api_state, api_message, fallback_state, fallback_message) = sys.argv[1:]
payload = {
    "state": state,
    "message": message,
    "timestamp": int(time.time()),
    "auth_mode": auth_mode,
    "api": {"state": api_state, "message": api_message},
    "fallback": {"state": fallback_state, "message": fallback_message},
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
    tail -n 20 | tr '\n' ' ' | sed 's/[[:space:]]\+/ /g; s/^[[:space:]]//; s/[[:space:]]$//'
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

WEBDAV_CONNECT_IP="$(php "$SCRIPT_DIR/lib/resolve-nextcloud-target.php" "$WEBDAV_BASE_URL")"
[[ -n "$WEBDAV_CONNECT_IP" ]] || { write_status error "Nextcloud-Zieladresse konnte nicht ermittelt werden"; exit 1; }

PLACEHOLDER_OUTPUT=""
PLACEHOLDER_EXIT=0
if PLACEHOLDER_OUTPUT="$(python3 "$SCRIPT_DIR/lib/build_webdav_placeholder_source.py" \
    --base-url "$WEBDAV_BASE_URL" \
    --connect-ip "$WEBDAV_CONNECT_IP" \
    --user "$WEBDAV_USER" \
    --password-file "$WEBDAV_PASSWORD_FILE" \
    "${ROOT_ARGS[@]}" \
    --source-dir "$WEBDAV_SOURCE_DIR" \
    --manifest "$MANIFEST" \
    --mapping "$WEBDAV_MAPPING_FILE" 2>&1)"; then
    PLACEHOLDER_EXIT=0
else
    PLACEHOLDER_EXIT=$?
fi
[[ -z "$PLACEHOLDER_OUTPUT" ]] || printf '%s\n' "$PLACEHOLDER_OUTPUT"
if [[ "$PLACEHOLDER_EXIT" -ne 0 ]]; then
    DETAIL="Exit-Code: $PLACEHOLDER_EXIT"
    if [[ -n "$PLACEHOLDER_OUTPUT" ]]; then
        DETAIL+="; Ausgabe: $(printf '%s\n' "$PLACEHOLDER_OUTPUT" | compact_output)"
    fi
    trap - ERR
    write_status error "WebDAV-Shadow-Tree fehlgeschlagen" "$DETAIL"
    exit "$PLACEHOLDER_EXIT"
fi

SHADOW_OUTPUT=""
SHADOW_EXIT=0
if SHADOW_OUTPUT="$(python3 "$SCRIPT_DIR/lib/shadow_tree.py" \
    --manifest "$MANIFEST" \
    --destination "$GALLERY_ROOT" \
    --state "$SHADOW_MAP_FILE" 2>&1)"; then
    SHADOW_EXIT=0
else
    SHADOW_EXIT=$?
fi
[[ -z "$SHADOW_OUTPUT" ]] || printf '%s\n' "$SHADOW_OUTPUT"
if [[ "$SHADOW_EXIT" -ne 0 ]]; then
    DETAIL="Exit-Code: $SHADOW_EXIT"
    if [[ -n "$SHADOW_OUTPUT" ]]; then
        DETAIL+="; Ausgabe: $(printf '%s\n' "$SHADOW_OUTPUT" | compact_output)"
    fi
    trap - ERR
    write_status error "WebDAV-Shadow-Tree fehlgeschlagen" "$DETAIL"
    exit "$SHADOW_EXIT"
fi

trap - ERR
PREVIEW_CACHE="$PIWIGO_ROOT/_data/bratonien-tools/nc-webdav-preview/connection-$CONNECTION_ID"
PREVIEW_OUTPUT=""
PREVIEW_EXIT=0
if PREVIEW_OUTPUT="$(php "$SCRIPT_DIR/lib/precache-webdav-previews.php" \
    --mapping="$WEBDAV_MAPPING_FILE" \
    --base-url="$WEBDAV_BASE_URL" \
    --user="$WEBDAV_USER" \
    --password-file="$WEBDAV_PASSWORD_FILE" \
    --cache-dir="$PREVIEW_CACHE" 2>&1)"; then
    PREVIEW_EXIT=0
else
    PREVIEW_EXIT=$?
fi
[[ -z "$PREVIEW_OUTPUT" ]] || printf '%s\n' "$PREVIEW_OUTPUT"
if [[ "$PREVIEW_EXIT" -ne 0 ]]; then
    DETAIL="Exit-Code: $PREVIEW_EXIT"
    if [[ -n "$PREVIEW_OUTPUT" ]]; then
        DETAIL+="; Ausgabe: $(printf '%s\n' "$PREVIEW_OUTPUT" | compact_output)"
    fi
    write_status error "WebDAV-Vorschaubilder konnten beim Einlesen nicht erzeugt werden" "$DETAIL"
    exit "$PREVIEW_EXIT"
fi

if [[ "${PIWIGO_SYNC_ENABLED:-0}" == "1" ]]; then
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

        if grep -qi 'Invalid username/password' <<<"$PIWIGO_OUTPUT"; then
            write_status error \
                "Piwigo-Fallback fehlgeschlagen: Benutzername oder Passwort ist ungültig" \
                "$DETAIL" \
                "fallback" \
                "not_configured" \
                "Für diese Verbindung ist keine nutzbare API konfiguriert" \
                "error" \
                "Piwigo hat Benutzername oder Passwort des Fallback-Zugangs abgelehnt"
        elif grep -qi 'Kein gespeicherter Benutzername/Passwort-Fallback\|Kein verbindungseigener Piwigo-Zugang' <<<"$PIWIGO_OUTPUT"; then
            write_status error \
                "Piwigo-Zugang fehlt: API und Fallback sind nicht nutzbar" \
                "$DETAIL" \
                "failed" \
                "not_configured" \
                "Für diese Verbindung ist keine nutzbare API konfiguriert" \
                "not_configured" \
                "Kein vollständiger Fallback-Zugang gespeichert"
        else
            write_status error "Piwigo-Synchronisierung des WebDAV-Shadow-Trees fehlgeschlagen" "$DETAIL"
        fi
        exit "$PIWIGO_EXIT"
    fi

    DERIVATIVE_OUTPUT=""
    DERIVATIVE_EXIT=0
    if DERIVATIVE_OUTPUT="$(php "$SCRIPT_DIR/lib/build-webdav-derivatives.php" \
        --piwigo-root="$PIWIGO_ROOT" \
        --connection-id="$CONNECTION_ID" 2>&1)"; then
        DERIVATIVE_EXIT=0
    else
        DERIVATIVE_EXIT=$?
    fi
    [[ -z "$DERIVATIVE_OUTPUT" ]] || printf '%s\n' "$DERIVATIVE_OUTPUT"
    if [[ "$DERIVATIVE_EXIT" -ne 0 ]]; then
        DETAIL="Exit-Code: $DERIVATIVE_EXIT"
        if [[ -n "$DERIVATIVE_OUTPUT" ]]; then
            DETAIL+="; Ausgabe: $(printf '%s\n' "$DERIVATIVE_OUTPUT" | compact_output)"
        fi
        write_status error "Piwigo-Derivate für WebDAV-Bilder konnten nicht erzeugt werden" "$DETAIL"
        exit "$DERIVATIVE_EXIT"
    fi

    if grep -q 'Piwigo-Synchronisierung per API erfolgreich' <<<"$PIWIGO_OUTPUT"; then
        write_status ok \
            "WebDAV eingelesen, Piwigo synchronisiert und Derivate erzeugt" \
            "" \
            "api" \
            "ok" \
            "Piwigo-API erfolgreich" \
            "not_needed" \
            "Fallback wurde nicht benötigt"
    elif grep -q 'Piwigo-Datenbanksynchronisierung per Benutzername/Passwort-Fallback erfolgreich' <<<"$PIWIGO_OUTPUT"; then
        write_status ok \
            "WebDAV eingelesen, Piwigo über Fallback synchronisiert und Derivate erzeugt" \
            "" \
            "fallback" \
            "not_used" \
            "API war nicht nutzbar" \
            "ok" \
            "Benutzername/Passwort-Fallback erfolgreich"
    else
        write_status ok "WebDAV eingelesen, Piwigo synchronisiert und Derivate erzeugt"
    fi
else
    write_status ok "WebDAV eingelesen und Vorschaubilder erzeugt; Piwigo-Synchronisierung ist für diese Verbindung deaktiviert"
fi
