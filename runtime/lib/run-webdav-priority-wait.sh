#!/usr/bin/env bash
set -Eeuo pipefail

STATE_DIR="${1:-}"
shift || true

[[ -n "$STATE_DIR" ]] || { echo "Connector-State-Verzeichnis fehlt." >&2; exit 2; }
[[ $# -gt 0 ]] || { echo "Warmup-Kommando fehlt." >&2; exit 2; }

PRIORITY_FILE="$STATE_DIR/webdav-cache-warmup-priority-sync"
ATTEMPTS=0
MAX_ATTEMPTS=3600

# Der Dispatcher setzt die Prioritätsmarke vor dem Start. Falls bereits ein
# Warmup läuft, beendet der zusätzliche Worker sich wegen seines Prozess-Locks
# sofort und lässt die Marke unangetastet. Wir probieren deshalb erst dann
# erneut, wenn die Marke noch vorhanden ist. Sobald ein Sync-Worker den Lock
# tatsächlich übernimmt, entfernt er die Marke selbst und arbeitet den neuen
# Bestand ab. Dadurch geht die Sofort-Priorität nicht verloren und es gibt nie
# zwei gleichzeitig arbeitende Warmup-Worker derselben Verbindung.
while [[ -f "$PRIORITY_FILE" ]]; do
    ATTEMPTS=$((ATTEMPTS + 1))
    if [[ "$ATTEMPTS" -gt "$MAX_ATTEMPTS" ]]; then
        echo "Priorisierter WebDAV-Warmup wartet seit zu langer Zeit auf den aktiven Worker." >&2
        exit 3
    fi

    set +e
    "$@"
    result=$?
    set -e

    if [[ "$result" -ne 0 ]]; then
        exit "$result"
    fi

    [[ -f "$PRIORITY_FILE" ]] || exit 0
    sleep 1
done

exit 0
