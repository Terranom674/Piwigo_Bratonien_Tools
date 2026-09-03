#!/usr/bin/env bash
set -Eeuo pipefail

STATE_DIR="${1:-}"
shift || true

[[ -n "$STATE_DIR" ]] || { echo "Connector-State-Verzeichnis fehlt." >&2; exit 2; }
[[ $# -gt 0 ]] || { echo "Warmup-Kommando fehlt." >&2; exit 2; }

mkdir -p -- "$STATE_DIR"
LOCK_FILE="$STATE_DIR/webdav-sync.lock"
PRIORITY_FILE="$STATE_DIR/webdav-cache-warmup-priority-sync"

# Der Connector-Sync nutzt denselben Lock exklusiv. Der Warmup hält ihn für
# seinen gesamten Lauf nur lesend/geteilt. Dadurch kann der Placeholder- und
# Shadow-Tree während Batch-Download, Piwigo-Aufruf und Restore nicht atomar
# unter dem Warmup ausgetauscht werden.
exec 9>"$LOCK_FILE"
flock -s 9

COMMAND=("$@")

run_command() {
    "${COMMAND[@]}"
}

result=0
if ! run_command; then
    result=$?
fi

# Ein Connector-Sync kann während eines bereits laufenden Warmups neue Alben
# melden. Der Dispatcher legt dafür nur eine Prioritätsmarke an; er startet
# keinen zweiten konkurrierenden Worker. Falls der aktive Lauf die Marke noch
# nicht selbst übernehmen konnte, wird sie hier nach seinem sauberen Ende
# garantiert nachgeholt. Dadurch geht ein Sofort-Warmup nie verloren.
if [[ -f "$PRIORITY_FILE" ]]; then
    rm -f -- "$PRIORITY_FILE"
    SYNC_COMMAND=()
    replaced=0
    for arg in "${COMMAND[@]}"; do
        if [[ "$arg" == --mode=* ]]; then
            SYNC_COMMAND+=("--mode=sync")
            replaced=1
        else
            SYNC_COMMAND+=("$arg")
        fi
    done
    if [[ "$replaced" -eq 0 ]]; then
        SYNC_COMMAND+=("--mode=sync")
    fi

    if ! "${SYNC_COMMAND[@]}"; then
        result=$?
    fi
fi

exit "$result"
