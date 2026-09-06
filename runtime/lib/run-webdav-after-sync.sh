#!/usr/bin/env bash
set -Eeuo pipefail

STATE_DIR="${1:-}"
shift || true

[[ -n "$STATE_DIR" ]] || { echo "Connector-State-Verzeichnis fehlt." >&2; exit 2; }
[[ $# -gt 0 ]] || { echo "Warmup-Kommando fehlt." >&2; exit 2; }

SYNC_LOCK="$STATE_DIR/webdav-sync.lock"
WAITER_LOCK="$STATE_DIR/webdav-cache-warmup-after-sync.lock"

# Pro Verbindung darf genau ein wartender Cache-Start existieren. Dadurch
# erzeugen wiederholte Klicks im Adminbereich keine Warteschlange aus Workern.
exec 8>"$WAITER_LOCK"
flock -n 8 || exit 0

# Der Connector hält diesen Lock exklusiv. Wir warten blockierend, ohne dabei
# Nextcloud-Dateien oder den Worker-Index anzufassen. Sobald der Connector den
# Lock freigibt, geben wir unseren kurzen Leselock sofort wieder frei und
# starten den normalen Worker. Dessen eigene Guards bleiben damit maßgeblich.
exec 9<>"$SYNC_LOCK"
flock -s 9
flock -u 9

exec "$@"
