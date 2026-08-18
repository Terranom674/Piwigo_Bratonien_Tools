#!/usr/bin/env bash
set -Eeuo pipefail

CONFIG_FILE="${PIWIGO_CONFIG:-/etc/bratonien-tools/nc-connector/connection-1.conf}"
[[ -r "$CONFIG_FILE" ]] || { echo "Konfiguration fehlt: $CONFIG_FILE" >&2; exit 1; }

PIWIGO_SYNC_OVERRIDE_VALUE="${PIWIGO_SYNC_OVERRIDE-}"
# shellcheck source=/dev/null
source "$CONFIG_FILE"

: "${NC_ACTIVITY_VIEW:?NC_ACTIVITY_VIEW fehlt}"
: "${NC_DB_VIEW:?NC_DB_VIEW fehlt}"
SOURCE_MODE="${SOURCE_MODE:-legacy-view}"
ACCESS_USER="${ACCESS_USER:-}"
ROOTS_CONFIG="${ROOTS_CONFIG:-}"

case "$SOURCE_MODE" in
    legacy-view|user-shares|selected-fileids) ;;
    *) echo "Unbekannter SOURCE_MODE: $SOURCE_MODE" >&2; exit 1 ;;
esac
if [[ "$SOURCE_MODE" != "legacy-view" && -z "$ACCESS_USER" ]]; then
    echo "ACCESS_USER fehlt fuer die verbindungsbezogene Datenquelle." >&2
    exit 1
fi
if [[ "$SOURCE_MODE" == "selected-fileids" && ( -z "$ROOTS_CONFIG" || ! -r "$ROOTS_CONFIG" ) ]]; then
    echo "ROOTS_CONFIG fehlt fuer die ausgewaehlten Nextcloud-Quellen." >&2
    exit 1
fi

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

CONNECTION_ID="${CONNECTION_ID:-}"
if [[ -z "$CONNECTION_ID" ]]; then
    CONFIG_BASENAME="$(basename -- "$CONFIG_FILE")"
    if [[ "$CONFIG_BASENAME" =~ ^connection-([0-9]+)\.conf$ ]]; then
        CONNECTION_ID="${BASH_REMATCH[1]}"
    else
        echo "Connector-ID konnte nicht aus $CONFIG_FILE ermittelt werden." >&2
        exit 1
    fi
fi

PUBLIC_STATUS_DIR="${PIWIGO_ROOT%/}/_data/bratonien-tools/nc-connector-status"
PUBLIC_STATUS_FILE="$PUBLIC_STATUS_DIR/connection-$CONNECTION_ID.json"
install -d -m 0755 "${PIWIGO_ROOT%/}/_data/bratonien-tools" "$PUBLIC_STATUS_DIR"

exec 9>"$LOCK_FILE"
flock -n 9 || exit 0

AUTH_MODE=""
API_STATE="not_run"
API_MESSAGE=""
FALLBACK_STATE="not_run"
FALLBACK_MESSAGE=""
ERROR_DETAIL=""
ERROR_STAGE="Vorbereitung"
ERROR_MESSAGE="Synchronisierung fehlgeschlagen"

compact_output() {
    tail -n 8 | tr '\n' ' ' | sed 's/[[:space:]]\+/ /g; s/^[[:space:]]//; s/[[:space:]]$//'
}

write_status() {
    local state="$1" message="$2"
    local error_detail="$ERROR_DETAIL"
    [[ "$state" == "error" ]] || error_detail=""
    python3 - "$STATUS_FILE" "$PUBLIC_STATUS_FILE" "$state" "$message" "$AUTH_MODE" "$API_STATE" "$API_MESSAGE" "$FALLBACK_STATE" "$FALLBACK_MESSAGE" "$error_detail" <<'PY'
import json, os, sys, tempfile, time
(path, public_path, state, message, auth_mode, api_state, api_message,
 fallback_state, fallback_message, error_detail) = sys.argv[1:]
payload = {
    "state": state,
    "message": message,
    "timestamp": int(time.time()),
    "auth_mode": auth_mode,
    "api": {"state": api_state, "message": api_message},
    "fallback": {"state": fallback_state, "message": fallback_message},
    "error_detail": error_detail,
}

def write_json(target):
    os.makedirs(os.path.dirname(target), exist_ok=True)
    fd, temporary = tempfile.mkstemp(dir=os.path.dirname(target))
    with os.fdopen(fd, "w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False)
        handle.write("\n")
    os.chmod(temporary, 0o644)
    os.replace(temporary, target)

write_json(path)
write_json(public_path)
PY
}

failure() {
    local exit_code="${1:-1}"
    local failed_command="${2:-unbekannt}"
    local failed_line="${3:-?}"
    trap - ERR
    if [[ -z "$ERROR_DETAIL" ]]; then
        ERROR_DETAIL="Schritt: $ERROR_STAGE. Exit-Code: $exit_code. Zeile: $failed_line. Fehlgeschlagener Befehl: $failed_command"
    fi
    write_status error "$ERROR_MESSAGE"
    exit "$exit_code"
}
trap 'failure $? "$BASH_COMMAND" "$LINENO"' ERR

run_stage() {
    local stage="$1" message="$2"
    shift 2
    local output="" exit_code=0
    ERROR_STAGE="$stage"
    ERROR_MESSAGE="$message"
    if output="$("$@" 2>&1)"; then exit_code=0; else exit_code=$?; fi
    [[ -z "$output" ]] || printf '%s\n' "$output"
    if [[ "$exit_code" -ne 0 ]]; then
        ERROR_DETAIL="Schritt: $stage. Exit-Code: $exit_code."
        [[ -z "$output" ]] || ERROR_DETAIL+=" Ausgabe: $(printf '%s\n' "$output" | compact_output)"
        trap - ERR
        write_status error "$message"
        exit "$exit_code"
    fi
}

ERROR_STAGE="Lokalen Zustand prüfen"
ERROR_MESSAGE="Lokaler Connector-Zustand konnte nicht geprüft werden"
NEEDS_LOCAL_REPAIR=0
if [[ ! -s "$MAP_FILE" ]]; then
    NEEDS_LOCAL_REPAIR=1
else
    set +e
    python3 - "$MAP_FILE" "$GALLERY_ROOT" <<'PY'
import json, sys
from pathlib import Path
mapping = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
gallery = Path(sys.argv[2])
roots = [target for source, target in mapping.items() if source.startswith("share:") and "/" not in source]
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

GATE_ARGS=(
    --state "$ACTIVITY_STATE" --host "$NC_DB_HOST" --port "$NC_DB_PORT"
    --database "$NC_DB_NAME" --user "$NC_DB_USER" --password-file "$NC_DB_PASSWORD_FILE"
    --view "$NC_ACTIVITY_VIEW" --source-view "$NC_DB_VIEW"
    --quiet "$QUIET_SECONDS" --max-wait "$MAX_WAIT_SECONDS" --full-after "$FULL_SYNC_SECONDS"
)
if [[ "$SOURCE_MODE" != "legacy-view" ]]; then GATE_ARGS+=(--access-user "$ACCESS_USER"); fi
if [[ "$SOURCE_MODE" == "selected-fileids" ]]; then GATE_ARGS+=(--roots-config "$ROOTS_CONFIG"); fi

if [[ "$NEEDS_LOCAL_REPAIR" == "1" ]]; then
    GATE_RESULT=0
else
    ERROR_STAGE="Nextcloud-Aktivitaet pruefen"
    ERROR_MESSAGE="Nextcloud-Aktivitaet konnte nicht geprueft werden"
    if GATE_OUTPUT="$(python3 "$SCRIPT_DIR/lib/activity_gate.py" check "${GATE_ARGS[@]}" 2>&1)"; then GATE_RESULT=0; else GATE_RESULT=$?; fi
    [[ -z "$GATE_OUTPUT" ]] || printf '%s\n' "$GATE_OUTPUT"
fi

if [[ "$GATE_RESULT" == "3" ]]; then
    trap - ERR
    write_status ok "Keine Aenderungen gefunden"
    exit 0
fi
if [[ "$GATE_RESULT" != "0" ]]; then
    ERROR_DETAIL="Schritt: Nextcloud-Aktivitaet pruefen. Exit-Code: $GATE_RESULT."
    [[ -z "${GATE_OUTPUT:-}" ]] || ERROR_DETAIL+=" Ausgabe: $(printf '%s\n' "$GATE_OUTPUT" | compact_output)"
    trap - ERR
    write_status error "Nextcloud-Aktivitaet konnte nicht geprueft werden"
    exit "$GATE_RESULT"
fi

if [[ "$SOURCE_MODE" == "selected-fileids" ]]; then
    run_stage "Ausgewaehlte Nextcloud-Quellen aufloesen" "Ausgewaehlte Nextcloud-Quellen konnten nicht aufgeloest werden" \
        python3 "$SCRIPT_DIR/lib/build_selected_manifest.py" \
        --host "$NC_DB_HOST" --port "$NC_DB_PORT" --database "$NC_DB_NAME" --user "$NC_DB_USER" \
        --password-file "$NC_DB_PASSWORD_FILE" --view "$NC_DB_VIEW" \
        --storage-config "$STORAGE_CONFIG" --roots-config "$ROOTS_CONFIG" --output "$MANIFEST"
else
    MANIFEST_ARGS=(
        --host "$NC_DB_HOST" --port "$NC_DB_PORT" --database "$NC_DB_NAME" --user "$NC_DB_USER"
        --password-file "$NC_DB_PASSWORD_FILE" --view "$NC_DB_VIEW"
        --storage-config "$STORAGE_CONFIG" --output "$MANIFEST"
    )
    if [[ "$SOURCE_MODE" == "user-shares" ]]; then MANIFEST_ARGS+=(--access-user "$ACCESS_USER"); fi
    run_stage "Nextcloud-Dateiliste lesen" "Dateiliste aus Nextcloud konnte nicht erstellt werden" \
        python3 "$SCRIPT_DIR/lib/build_manifest.py" "${MANIFEST_ARGS[@]}"
fi

run_stage "Lokalen Galeriebaum aktualisieren" "Lokaler Galeriebaum konnte nicht aktualisiert werden" \
    python3 "$SCRIPT_DIR/lib/shadow_tree.py" --manifest "$MANIFEST" --destination "$GALLERY_ROOT" --state "$MAP_FILE"

if [[ "${PIWIGO_SYNC_ENABLED:-0}" == "1" ]]; then
    ERROR_STAGE="Piwigo synchronisieren"
    ERROR_MESSAGE="Piwigo-Synchronisierung fehlgeschlagen"
    if PIWIGO_OUTPUT="$(php "$SCRIPT_DIR/lib/piwigo-sync.php" --piwigo-root="$PIWIGO_ROOT" --connection-id="$CONNECTION_ID" --base-url="http://127.0.0.1" 2>&1)"; then PIWIGO_EXIT=0; else PIWIGO_EXIT=$?; fi
    printf '%s\n' "$PIWIGO_OUTPUT"

    if grep -q 'Piwigo-Synchronisierung per API erfolgreich' <<<"$PIWIGO_OUTPUT"; then
        AUTH_MODE="api"; API_STATE="ok"; API_MESSAGE="API-Synchronisierung erfolgreich"
        FALLBACK_STATE="not_needed"; FALLBACK_MESSAGE="Fallback wurde nicht benoetigt"
    else
        API_LINE="$(grep -m1 '^Piwigo-API nicht nutzbar:' <<<"$PIWIGO_OUTPUT" || true)"
        API_STATE="error"
        if [[ -n "$API_LINE" ]]; then API_MESSAGE="${API_LINE#Piwigo-API nicht nutzbar: }"; else API_MESSAGE="API-Synchronisierung war nicht erfolgreich"; fi
        if grep -q 'Piwigo-Datenbanksynchronisierung per Benutzername/Passwort-Fallback erfolgreich' <<<"$PIWIGO_OUTPUT"; then
            AUTH_MODE="fallback"; FALLBACK_STATE="ok"; FALLBACK_MESSAGE="Benutzername/Passwort-Fallback erfolgreich"
        elif [[ "$PIWIGO_EXIT" -ne 0 ]]; then
            AUTH_MODE="failed"; FALLBACK_STATE="error"; FALLBACK_MESSAGE="$(tail -n 1 <<<"$PIWIGO_OUTPUT")"
        fi
    fi
    if [[ "$PIWIGO_EXIT" -ne 0 ]]; then
        ERROR_DETAIL="Schritt: Piwigo synchronisieren. Exit-Code: $PIWIGO_EXIT. Ausgabe: $(printf '%s\n' "$PIWIGO_OUTPUT" | compact_output)"
        trap - ERR
        write_status error "Piwigo-Synchronisierung fehlgeschlagen"
        exit "$PIWIGO_EXIT"
    fi
fi

COMMIT_ARGS=(
    --state "$ACTIVITY_STATE" --host "$NC_DB_HOST" --port "$NC_DB_PORT"
    --database "$NC_DB_NAME" --user "$NC_DB_USER" --password-file "$NC_DB_PASSWORD_FILE"
    --view "$NC_ACTIVITY_VIEW" --source-view "$NC_DB_VIEW"
)
if [[ "$SOURCE_MODE" != "legacy-view" ]]; then COMMIT_ARGS+=(--access-user "$ACCESS_USER"); fi
if [[ "$SOURCE_MODE" == "selected-fileids" ]]; then COMMIT_ARGS+=(--roots-config "$ROOTS_CONFIG"); fi

ERROR_STAGE="Aktivitaetsstand speichern"
ERROR_MESSAGE="Aktivitaetsstand konnte nicht gespeichert werden"
if COMMIT_OUTPUT="$(python3 "$SCRIPT_DIR/lib/activity_gate.py" commit "${COMMIT_ARGS[@]}" 2>&1)"; then COMMIT_EXIT=0; else COMMIT_EXIT=$?; fi
[[ -z "$COMMIT_OUTPUT" ]] || printf '%s\n' "$COMMIT_OUTPUT"
if [[ "$COMMIT_EXIT" -ne 0 ]]; then
    ERROR_DETAIL="Schritt: Aktivitaetsstand speichern. Exit-Code: $COMMIT_EXIT."
    [[ -z "$COMMIT_OUTPUT" ]] || ERROR_DETAIL+=" Ausgabe: $(printf '%s\n' "$COMMIT_OUTPUT" | compact_output)"
    trap - ERR
    write_status error "Aktivitaetsstand konnte nicht gespeichert werden"
    exit "$COMMIT_EXIT"
fi

trap - ERR
if [[ "$AUTH_MODE" == "fallback" ]]; then
    write_status warning "Synchronisierung erfolgreich ueber Fallback; API war nicht nutzbar"
elif [[ "$AUTH_MODE" == "api" ]]; then
    write_status ok "Synchronisierung erfolgreich ueber API"
else
    write_status ok "Synchronisierung erfolgreich"
fi
