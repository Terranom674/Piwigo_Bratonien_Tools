#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
PIWIGO_ROOT_DEFAULT="${BRATONIEN_NC_PIWIGO_ROOT:-$(cd -- "$SCRIPT_DIR/../../.." && pwd)}"
CONFIG_DIR="${BRATONIEN_NC_CONFIG_DIR:-/etc/bratonien-tools/nc-connector}"
NATIVE_MODE="${BRATONIEN_NC_NATIVE:-0}"
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

if [[ "$NATIVE_MODE" != "1" ]]; then
    php "$SCRIPT_DIR/reconcile.php"
fi
php "$SCRIPT_DIR/reconcile-webdav.php"
php "$SCRIPT_DIR/cleanup-webdav-piwigo.php"
if [[ "$NATIVE_MODE" != "1" ]]; then
    php "$SCRIPT_DIR/cleanup-stale.php"
fi

configs=("$CONFIG_DIR"/connection-*.conf)
webdav_configs=("$CONFIG_DIR"/webdav-connection-*.conf)

if [[ ${#configs[@]} -eq 0 && ${#webdav_configs[@]} -eq 0 ]]; then
    echo "Keine NC-Connector-Verbindungen konfiguriert."
    exit 0
fi

route_piwigo_root=""
for candidate in "${webdav_configs[@]}" "${configs[@]}"; do
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
webdav_count=0
local_count=0
summary_parts=()
matched_count=0

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
    webdav_count=$((webdav_count + 1))
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

if [[ "$NATIVE_MODE" != "1" ]]; then
    for config in "${configs[@]}"; do
        [[ -f "$config" ]] || continue
        name="$(basename "$config")"
        connection_id="0"
        if [[ "$name" =~ ^connection-([0-9]+)\.conf$ ]]; then
            connection_id="${BASH_REMATCH[1]}"
        fi
        if [[ "$connection_id" -lt 1 ]]; then
            echo "NC Connector: $name besitzt keine gueltige Verbindungs-ID." >&2
            failure_count=$((failure_count + 1))
            summary_parts+=("$name: ungueltige Verbindungs-ID")
            continue
        fi
        if [[ "$TARGET_CONNECTION_ID" -gt 0 && "$connection_id" != "$TARGET_CONNECTION_ID" ]]; then
            continue
        fi

        matched_count=$((matched_count + 1))
        piwigo_root="$(read_config_value PIWIGO_ROOT "$config")"
        [[ -n "$piwigo_root" ]] || piwigo_root="$PIWIGO_ROOT_DEFAULT"
        tombstone_dir="${piwigo_root%/}/_data/bratonien-tools/nc-connector-status"
        if [[ -f "$tombstone_dir/deleted-$connection_id" ]]; then
            echo "NC Connector: Verbindung $connection_id wurde geloescht; Laufzeitdateien werden entfernt."
            rm -f -- "$CONFIG_DIR/connection-$connection_id.conf" \
                "$CONFIG_DIR/connection-$connection_id.db-password" \
                "$CONFIG_DIR/connection-$connection_id.piwigo-password" \
                "$CONFIG_DIR/connection-$connection_id.storages.tsv" \
                "$CONFIG_DIR/connection-$connection_id.roots.tsv"
            rm -f -- "$tombstone_dir/deleted-$connection_id"
            continue
        fi

        local_count=$((local_count + 1))
        echo "NC Connector Local #$connection_id: $name"
        if env PIWIGO_CONFIG="$config" bash "$SCRIPT_DIR/sync.sh"; then
            summary_parts+=("Local #$connection_id erfolgreich")
        else
            failure_count=$((failure_count + 1))
            summary_parts+=("Local #$connection_id fehlgeschlagen")
        fi
    done
fi

if [[ "$TARGET_CONNECTION_ID" -gt 0 && "$matched_count" -eq 0 ]]; then
    write_route_status "failed" "FEHLER - Verbindung #$TARGET_CONNECTION_ID" "Keine Laufzeitkonfiguration für Verbindung #$TARGET_CONNECTION_ID gefunden." "0"
    echo "NC Connector: keine Laufzeitkonfiguration für Verbindung #$TARGET_CONNECTION_ID gefunden." >&2
    exit 1
fi

summary_detail="$(IFS='; '; printf '%s' "${summary_parts[*]}")"
[[ -n "$summary_detail" ]] || summary_detail="Keine Verbindung wurde ausgefuehrt."

if [[ "$failure_count" -eq 0 ]]; then
    if [[ "$webdav_count" -gt 0 && "$local_count" -gt 0 ]]; then
        route="mixed"
        label="WebDAV + Local"
    elif [[ "$webdav_count" -gt 0 ]]; then
        route="webdav"
        label="WebDAV"
    else
        route="local"
        label="Local"
    fi
    write_route_status "$route" "$label" "$summary_detail" "1"
    echo "NC Connector: angeforderte Verbindung wurde erfolgreich verarbeitet."
    exit 0
fi

write_route_status "failed" "FEHLER - angeforderte Verbindung" "$summary_detail" "0"
echo "NC Connector: die angeforderte Verbindung ist fehlgeschlagen." >&2
exit 1
