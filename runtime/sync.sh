#!/usr/bin/env bash
set -Eeuo pipefail

CONFIG_FILE="${PIWIGO_CONFIG:-/etc/bratonien-tools/nc-connector/connection-1.conf}"
[[ -r "$CONFIG_FILE" ]] || { echo "Konfiguration fehlt: $CONFIG_FILE" >&2; exit 1; }

PIWIGO_SYNC_OVERRIDE_VALUE="${PIWIGO_SYNC_OVERRIDE-}"

# shellcheck source=/dev/null
source "$CONFIG_FILE"

NC_ACTIVITY_VIEW="${NC_ACTIVITY_VIEW:-piwigo_showcase_activity}"
NC_DB_VIEW="${NC_DB_VIEW:-piwigo_showcase_sources}"

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
    if [[ "$state" != "error" ]]; then
        error_detail=""
    fi
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
    local stage="$1"
    local message="$2"
    shift 2
    local output=""
    local exit_code=0

    ERROR_STAGE="$stage"
    ERROR_MESSAGE="$message"
    set +e
    output="$("$@" 2>&1)"
    exit_code=$?
    set -e
    if [[ -n "$output" ]]; then
        printf '%s\n' "$output"
    fi
    if [[ "$exit_code" -ne 0 ]]; then
        ERROR_DETAIL="Schritt: $stage. Exit-Code: $exit_code."
        if [[ -n "$output" ]]; then
            ERROR_DETAIL+=" Ausgabe: $(printf '%s\n' "$output" | compact_output)"
        fi
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
    ERROR_STAGE="Nextcloud-Aktivität prüfen"
    ERROR_MESSAGE="Nextcloud-Aktivität konnte nicht geprüft werden"
    set +e
    GATE_OUTPUT="$(python3 "$SCRIPT_DIR/lib/activity_gate.py" check \
        --state "$ACTIVITY_STATE" --host "$NC_DB_HOST" --port "$NC_DB_PORT" \
        --database "$NC_DB_NAME" --user "$NC_DB_USER" --password-file "$NC_DB_PASSWORD_FILE" \
        --view "$NC_ACTIVITY_VIEW" --source-view "$NC_DB_VIEW" \
        --quiet "$QUIET_SECONDS" --max-wait "$MAX_WAIT_SECONDS" --full-after "$FULL_SYNC_SECONDS" 2>&1)"
    GATE_RESULT=$?
    set -e
    if [[ -n "$GATE_OUTPUT" ]]; then
        printf '%s\n' "$GATE_OUTPUT"
    fi
fi

if [[ "$GATE_RESULT" == "3" ]]; then
    trap - ERR
    write_status ok "Keine Änderungen gefunden"
    exit 0
fi
if [[ "$GATE_RESULT" != "0" ]]; then
    ERROR_DETAIL="Schritt: Nextcloud-Aktivität prüfen. Exit-Code: $GATE_RESULT."
    if [[ -n "${GATE_OUTPUT:-}" ]]; then
        ERROR_DETAIL+=" Ausgabe: $(printf '%s\n' "$GATE_OUTPUT" | compact_output)"
    fi
    trap - ERR
    write_status error "Nextcloud-Aktivität konnte nicht geprüft werden"
    exit "$GATE_RESULT"
fi

run_stage \
    "Nextcloud-Dateiliste lesen" \
    "Dateiliste aus Nextcloud konnte nicht erstellt werden" \
    python3 "$SCRIPT_DIR/lib/build_manifest.py" \
    --host "$NC_DB_HOST" --port "$NC_DB_PORT" --database "$NC_DB_NAME" --user "$NC_DB_USER" \
    --password-file "$NC_DB_PASSWORD_FILE" --view "$NC_DB_VIEW" \
    --storage-config "$STORAGE_CONFIG" --output "$MANIFEST"

run_stage \
    "Lokalen Galeriebaum aktualisieren" \
    "Lokaler Galeriebaum konnte nicht aktualisiert werden" \
    python3 "$SCRIPT_DIR/lib/shadow_tree.py" \
    --manifest "$MANIFEST" --destination "$GALLERY_ROOT" --state "$MAP_FILE"

if [[ "${PIWIGO_SYNC_ENABLED:-0}" == "1" ]]; then
    ERROR_STAGE="Piwigo synchronisieren"
    ERROR_MESSAGE="Piwigo-Synchronisierung fehlgeschlagen"
    set +e
    PIWIGO_OUTPUT="$(php "$SCRIPT_DIR/lib/piwigo-sync.php" \
        --piwigo-root="$PIWIGO_ROOT" \
        --connection-id="$CONNECTION_ID" \
        --base-url="http://127.0.0.1" 2>&1)"
    PIWIGO_EXIT=$?
    set -e
    printf '%s\n' "$PIWIGO_OUTPUT"

    if grep -q 'Piwigo-Synchronisierung per API erfolgreich' <<<"$PIWIGO_OUTPUT"; then
        AUTH_MODE="api"
        API_STATE="ok"
        API_MESSAGE="API-Synchronisierung erfolgreich"
        FALLBACK_STATE="not_needed"
        FALLBACK_MESSAGE="Fallback wurde nicht benötigt"
    else
        API_LINE="$(grep -m1 '^Piwigo-API nicht nutzbar:' <<<"$PIWIGO_OUTPUT" || true)"
        if [[ -n "$API_LINE" ]]; then
            API_STATE="error"
            API_MESSAGE="${API_LINE#Piwigo-API nicht nutzbar: }"
        else
            API_STATE="error"
            API_MESSAGE="API-Synchronisierung war nicht erfolgreich"
        fi

        if grep -q 'Piwigo-Datenbanksynchronisierung per Benutzername/Passwort-Fallback erfolgreich' <<<"$PIWIGO_OUTPUT"; then
            AUTH_MODE="fallback"
            FALLBACK_STATE="ok"
            FALLBACK_MESSAGE="Benutzername/Passwort-Fallback erfolgreich"
        elif [[ "$PIWIGO_EXIT" -ne 0 ]]; then
            AUTH_MODE="failed"
            FALLBACK_STATE="error"
            FALLBACK_MESSAGE="$(tail -n 1 <<<"$PIWIGO_OUTPUT")"
        fi
    fi

    if [[ "$PIWIGO_EXIT" -ne 0 ]]; then
        ERROR_DETAIL="Schritt: Piwigo synchronisieren. Exit-Code: $PIWIGO_EXIT. Ausgabe: $(printf '%s\n' "$PIWIGO_OUTPUT" | compact_output)"
        trap - ERR
        write_status error "Piwigo-Synchronisierung fehlgeschlagen"
        exit "$PIWIGO_EXIT"
    fi
fi

ERROR_STAGE="Aktivitätsstand speichern"
ERROR_MESSAGE="Aktivitätsstand konnte nicht gespeichert werden"
set +e
COMMIT_OUTPUT="$(python3 "$SCRIPT_DIR/lib/activity_gate.py" commit \
    --state "$ACTIVITY_STATE" --host "$NC_DB_HOST" --port "$NC_DB_PORT" \
    --database "$NC_DB_NAME" --user "$NC_DB_USER" --password-file "$NC_DB_PASSWORD_FILE" \
    --view "$NC_ACTIVITY_VIEW" --source-view "$NC_DB_VIEW" 2>&1)"
COMMIT_EXIT=$?
set -e
if [[ -n "$COMMIT_OUTPUT" ]]; then
    printf '%s\n' "$COMMIT_OUTPUT"
fi
if [[ "$COMMIT_EXIT" -ne 0 ]]; then
    ERROR_DETAIL="Schritt: Aktivitätsstand speichern. Exit-Code: $COMMIT_EXIT."
    if [[ -n "$COMMIT_OUTPUT" ]]; then
        ERROR_DETAIL+=" Ausgabe: $(printf '%s\n' "$COMMIT_OUTPUT" | compact_output)"
    fi
    trap - ERR
    write_status error "Aktivitätsstand konnte nicht gespeichert werden"
    exit "$COMMIT_EXIT"
fi

trap - ERR
if [[ "$AUTH_MODE" == "fallback" ]]; then
    write_status warning "Synchronisierung erfolgreich über Fallback; API war nicht nutzbar"
elif [[ "$AUTH_MODE" == "api" ]]; then
    write_status ok "Synchronisierung erfolgreich über API"
else
    write_status ok "Synchronisierung erfolgreich"
fi
