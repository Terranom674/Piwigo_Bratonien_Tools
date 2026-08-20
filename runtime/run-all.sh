#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PIWIGO_ROOT_DEFAULT="${BRATONIEN_NC_PIWIGO_ROOT:-$(cd -- "$SCRIPT_DIR/../../.." && pwd)}"
CONFIG_DIR="${BRATONIEN_NC_CONFIG_DIR:-/etc/bratonien-tools/nc-connector}"
TARGET_CONNECTION_ID="${BRATONIEN_NC_CONNECTION_ID:-0}"
GLOBAL_LOCK_DIR="${PIWIGO_ROOT_DEFAULT%/}/_data/bratonien-tools/nc-connector-scheduler"
GLOBAL_LOCK_FILE="$GLOBAL_LOCK_DIR/worker.lock"
mkdir -p -- "$GLOBAL_LOCK_DIR"
exec 8>"$GLOBAL_LOCK_FILE"
if ! flock -n 8; then
    echo "NC Connector: ein Lauf ist bereits aktiv."
    exit 0
fi
shopt -s nullglob

if [[ ! "$TARGET_CONNECTION_ID" =~ ^[0-9]+$ ]]; then
    echo "NC Connector: ungültige Ziel-Verbindungs-ID: $TARGET_CONNECTION_ID" >&2
    exit 1
fi

read_config_value() {
    local key="$1"
    local file="$2"
    local value
    value="$(sed -n "s/^${key}=//p" "$file" | tail -n 1)"
    value="${value%\"}"
    value="${value#\"}"
    value="${value%\'}"
    value="${value#\'}"
    printf '%s' "$value"
}

write_route_status() {
    local route="$1"
    local label="$2"
    local detail="$3"
    local success="$4"
    [[ -n "${ROUTE_STATUS_FILE:-}" ]] || return 0
    mkdir -p -- "$(dirname -- "$ROUTE_STATUS_FILE")"
    php -r '
      $payload = array(
        "timestamp" => time(),
        "route" => (string)$argv[2],
        "label" => (string)$argv[3],
        "detail" => (string)$argv[4],
        "fallback_used" => false,
        "success" => $argv[5] === "1"
      );
      $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
      if (!is_string($json) || file_put_contents($argv[1], $json.PHP_EOL, LOCK_EX) === false) exit(1);
      @chmod($argv[1], 0644);
    ' "$ROUTE_STATUS_FILE" "$route" "$label" "$detail" "$success"
}

# Legacy ist beendet. Der Runner bereitet ausschließlich die aktuelle WebDAV-Runtime vor.
php "$SCRIPT_DIR/reconcile-webdav.php"

webdav_configs=("$CONFIG_DIR"/webdav-connection-*.conf)
if [[ ${#webdav_configs[@]} -eq 0 ]]; then
    echo "Keine WebDAV-NC-Connector-Verbindungen konfiguriert."
    exit 0
fi

route_piwigo_root=""
for candidate in "${webdav_configs[@]}"; do
    [[ -f "$candidate" ]] || continue
    candidate_id="$(read_config_value CONNECTION_ID "$candidate")"
    if [[ "$TARGET_CONNECTION_ID" -gt 0 && "$candidate_id" != "$TARGET_CONNECTION_ID" ]]; then
        continue
    fi
    route_piwigo_root="$(read_config_value PIWIGO_ROOT "$candidate")"
    [[ -n "$route_piwigo_root" ]] && break
done
[[ -n "$route_piwigo_root" ]] || route_piwigo_root="$PIWIGO_ROOT_DEFAULT"
ROUTE_STATUS_FILE="${route_piwigo_root%/}/_data/bratonien-tools/nc-connector-status/route-status.json"

failure_count=0
matched_count=0
summary_parts=()

for config in "${webdav_configs[@]}"; do
    [[ -f "$config" ]] || continue
    name="$(basename "$config")"
    connection_id="$(read_config_value CONNECTION_ID "$config")"
    if [[ ! "$connection_id" =~ ^[0-9]+$ ]] || [[ "$connection_id" -lt 1 ]]; then
        echo "NC Connector: $name besitzt keine gueltige Verbindungs-ID." >&2
        failure_count=$((failure_count + 1))
        summary_parts+=("$name: ungueltige Verbindungs-ID")
        continue
    fi
    if [[ "$TARGET_CONNECTION_ID" -gt 0 && "$connection_id" != "$TARGET_CONNECTION_ID" ]]; then
        continue
    fi

    matched_count=$((matched_count + 1))
    echo "NC Connector WebDAV #$connection_id: $name"
    output=""
    if output="$(env PIWIGO_CONFIG="$config" bash "$SCRIPT_DIR/sync-webdav.sh" 2>&1)"; then
        [[ -z "$output" ]] || printf '%s\n' "$output"
        summary_parts+=("WebDAV #$connection_id erfolgreich")
    else
        code=$?
        [[ -z "$output" ]] || printf '%s\n' "$output" >&2
        failure_count=$((failure_count + 1))
        summary_parts+=("WebDAV #$connection_id fehlgeschlagen (Exit $code)")
    fi
done

if [[ "$TARGET_CONNECTION_ID" -gt 0 && "$matched_count" -eq 0 ]]; then
    write_route_status "failed" "FEHLER - Verbindung #$TARGET_CONNECTION_ID" "Keine WebDAV-Laufzeitkonfiguration für Verbindung #$TARGET_CONNECTION_ID gefunden." "0"
    echo "NC Connector: keine WebDAV-Laufzeitkonfiguration für Verbindung #$TARGET_CONNECTION_ID gefunden." >&2
    exit 1
fi

summary_detail="$(IFS='; '; printf '%s' "${summary_parts[*]}")"
[[ -n "$summary_detail" ]] || summary_detail="Keine WebDAV-Verbindung wurde ausgefuehrt."

if [[ "$failure_count" -eq 0 ]]; then
    write_route_status "webdav" "WebDAV" "$summary_detail" "1"
    echo "NC Connector: WebDAV-Verbindung wurde erfolgreich verarbeitet."
    exit 0
fi

write_route_status "failed" "FEHLER - WebDAV-Verbindung" "$summary_detail" "0"
echo "NC Connector: die angeforderte WebDAV-Verbindung ist fehlgeschlagen." >&2
exit 1
