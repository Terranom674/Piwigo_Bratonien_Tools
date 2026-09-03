#!/usr/bin/env bash
set -Eeuo pipefail

STATE_DIR="${1:-}"
shift || true

[[ -n "$STATE_DIR" ]] || { echo "Connector-State-Verzeichnis fehlt." >&2; exit 2; }
[[ $# -gt 0 ]] || { echo "Warmup-Kommando fehlt." >&2; exit 2; }

mkdir -p -- "$STATE_DIR"
LOCK_FILE="$STATE_DIR/webdav-sync.lock"

# Der Connector-Sync nutzt denselben Lock exklusiv. Der Warmup hält ihn für
# seinen gesamten Lauf nur lesend/geteilt. Dadurch kann der Placeholder- und
# Shadow-Tree während Batch-Download, Piwigo-Aufruf und Restore nicht atomar
# unter dem Warmup ausgetauscht werden.
exec 9>"$LOCK_FILE"
flock -s 9
exec "$@"
