#!/usr/bin/env bash
set -Eeuo pipefail

CONFIG_DIR="/etc/bratonien-tools/nc-connector"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
shopt -s nullglob

declare -A webdav_success_for_legacy=()
declare -A webdav_failure_for_legacy=()
declare -A legacy_seen=()

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

numeric_connection_id() {
    local value="${1:-}"
    if [[ "$value" =~ ^[0-9]+$ ]]; then
        printf '%s' "$value"
    else
        printf '0'
    fi
}

compact_text() {
    printf '%s\n' "$1" | tail -n 20 | tr '\n' ' ' | sed 's/[[:space:]]\+/ /g; s/^[[:space:]]//; s/[[:space:]]$//'
}

read_webdav_failure_detail() {
    local config="$1"
    local captured_output="${2:-}"
    local connection_id piwigo_root status_file status_detail

    connection_id="$(read_config_value CONNECTION_ID "$config")"
    piwigo_root="$(read_config_value PIWIGO_ROOT "$config")"
    [[ -n "$piwigo_root" ]] || piwigo_root="/var/www/piwigo"

    status_detail=""
    if [[ -n "$connection_id" ]]; then
        status_file="${piwigo_root%/}/_data/bratonien-tools/nc-connector-status/connection-${connection_id}.json"
        if [[ -r "$status_file" ]]; then
            status_detail="$(php -r '
              $file = $argv[1];
              $data = json_decode((string)@file_get_contents($file), true);
              if (!is_array($data)) exit(0);
              $message = trim((string)($data["message"] ?? ""));
              $detail = trim((string)($data["error_detail"] ?? ""));
              $parts = array();
              if ($message !== "") $parts[] = $message;
              if ($detail !== "") $parts[] = $detail;
              if ($parts) {
                $text = preg_replace("/\\s+/u", " ", implode(" - ", $parts));
                echo trim((string)$text);
              }
            ' "$status_file")"
        fi
    fi

    if [[ -n "$captured_output" ]]; then
        captured_output="$(compact_text "$captured_output")"
    fi

    if [[ -n "$status_detail" && -n "$captured_output" ]]; then
        printf '%s' "$status_detail | Prozessausgabe: $captured_output"
    elif [[ -n "$status_detail" ]]; then
        printf '%s' "$status_detail"
    elif [[ -n "$captured_output" ]]; then
        printf '%s' "$captured_output"
    elif [[ -z "$connection_id" ]]; then
        printf '%s' 'WebDAV-Prozess wurde gestartet, aber die Verbindungs-ID fehlt in der Runtime-Konfiguration.'
    else
        printf '%s' "WebDAV-Prozess fuer Verbindung ${connection_id} endete mit Fehler, lieferte aber weder Statusdatei noch Prozessausgabe."
    fi
}

write_route_status() {
    local route="$1"
    local label="$2"
    local detail="$3"
    local fallback_used="$4"
    local success="$5"

    [[ -n "${ROUTE_STATUS_FILE:-}" ]] || return 0
    mkdir -p -- "$(dirname -- "$ROUTE_STATUS_FILE")"

    php -r '
      $payload = array(
        "timestamp" => time(),
        "route" => (string)$argv[2],
        "label" => (string)$argv[3],
        "detail" => (string)$argv[4],
        "fallback_used" => $argv[5] === "1",
        "success" => $argv[6] === "1"
      );
      $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
      if (!is_string($json) || file_put_contents($argv[1], $json.PHP_EOL, LOCK_EX) === false) {
        fwrite(STDERR, "Route-Status konnte nicht geschrieben werden.\n");
        exit(1);
      }
      @chmod($argv[1], 0644);
    ' "$ROUTE_STATUS_FILE" "$route" "$label" "$detail" "$fallback_used" "$success"
}

if ! php "$SCRIPT_DIR/reconcile.php"; then
    echo "NC Connector: gespeicherte lokale Verbindungen konnten nicht mit der Runtime abgeglichen werden." >&2
    exit 1
fi

if ! php "$SCRIPT_DIR/reconcile-webdav.php"; then
    echo "NC Connector: WebDAV-Verbindungen konnten nicht mit der Runtime abgeglichen werden." >&2
    exit 1
fi

if ! php "$SCRIPT_DIR/cleanup-webdav-piwigo.php"; then
    echo "NC Connector: Piwigo-Inhalte geloeschter WebDAV-Verbindungen konnten nicht bereinigt werden." >&2
    exit 1
fi

if ! php "$SCRIPT_DIR/cleanup-stale.php"; then
    echo "NC Connector: verwaiste Laufzeitdateien konnten nicht bereinigt werden." >&2
    exit 1
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
    route_piwigo_root="$(read_config_value PIWIGO_ROOT "$candidate")"
    [[ -n "$route_piwigo_root" ]] && break
done
[[ -n "$route_piwigo_root" ]] || route_piwigo_root="/var/www/piwigo"
ROUTE_STATUS_FILE="${route_piwigo_root%/}/_data/bratonien-tools/nc-connector-status/route-status.json"

webdav_success_count=0
webdav_failure_count=0
legacy_run_count=0
legacy_failure_count=0
fallback_used=0
unpaired_webdav_failure=0
summary_parts=()

for config in "${webdav_configs[@]}"; do
    name="$(basename "$config")"
    connection_id="$(numeric_connection_id "$(read_config_value CONNECTION_ID "$config")")"
    legacy_id="$(numeric_connection_id "$(read_config_value MIGRATION_LEGACY_CONNECTION_ID "$config")")"

    if [[ "$connection_id" -lt 1 ]]; then
        echo "NC Connector: $name besitzt keine gueltige WebDAV-Verbindungs-ID." >&2
        webdav_failure_count=$((webdav_failure_count + 1))
        unpaired_webdav_failure=1
        summary_parts+=("$name: ungueltige Verbindungs-ID")
        continue
    fi

    echo "NC Connector WebDAV #$connection_id: $name"
    webdav_output=""
    if webdav_output="$(env PIWIGO_CONFIG="$config" bash "$SCRIPT_DIR/sync-webdav.sh" 2>&1)"; then
        webdav_success_count=$((webdav_success_count + 1))
        [[ -z "$webdav_output" ]] || printf '%s\n' "$webdav_output"
        if [[ "$legacy_id" -gt 0 ]]; then
            webdav_success_for_legacy["$legacy_id"]="$connection_id"
            summary_parts+=("WebDAV #$connection_id erfolgreich; Legacy #$legacy_id uebersprungen")
        else
            summary_parts+=("WebDAV #$connection_id erfolgreich")
        fi
    else
        webdav_exit=$?
        webdav_failure_count=$((webdav_failure_count + 1))
        [[ -z "$webdav_output" ]] || printf '%s\n' "$webdav_output" >&2
        webdav_failure_detail="$(read_webdav_failure_detail "$config" "$webdav_output")"
        webdav_failure_detail="Exit-Code ${webdav_exit}: ${webdav_failure_detail}"
        echo "NC Connector: WebDAV #$connection_id fehlgeschlagen: $webdav_failure_detail" >&2

        if [[ "$legacy_id" -gt 0 ]]; then
            webdav_failure_for_legacy["$legacy_id"]="$webdav_failure_detail"
            echo "NC Connector: Nur Legacy-Verbindung #$legacy_id ist fuer diesen WebDAV-Lauf als Fallback vorgesehen." >&2
        else
            unpaired_webdav_failure=1
            summary_parts+=("WebDAV #$connection_id fehlgeschlagen ohne Legacy-Fallback")
        fi
    fi
done

for config in "${configs[@]}"; do
    name="$(basename "$config")"
    connection_id="0"
    if [[ "$name" =~ ^connection-([0-9]+)\.conf$ ]]; then
        connection_id="${BASH_REMATCH[1]}"
    fi

    if [[ "$connection_id" -lt 1 ]]; then
        echo "NC Connector: $name besitzt keine gueltige Legacy-Verbindungs-ID." >&2
        legacy_failure_count=$((legacy_failure_count + 1))
        summary_parts+=("$name: ungueltige Legacy-Verbindungs-ID")
        continue
    fi
    legacy_seen["$connection_id"]=1

    piwigo_root="$(read_config_value PIWIGO_ROOT "$config")"
    [[ -n "$piwigo_root" ]] || piwigo_root="/var/www/piwigo"
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

    if [[ -n "${webdav_success_for_legacy[$connection_id]:-}" ]]; then
        echo "NC Connector: Legacy #$connection_id wird nicht ausgefuehrt, weil der zugeordnete WebDAV-Nachfolger #${webdav_success_for_legacy[$connection_id]} erfolgreich war."
        continue
    fi

    legacy_run_count=$((legacy_run_count + 1))
    if [[ -n "${webdav_failure_for_legacy[$connection_id]:-}" ]]; then
        echo "NC Connector Legacy-Fallback #$connection_id nach WebDAV-Fehler: $name"
        fallback_used=1
    else
        echo "NC Connector Legacy #$connection_id: $name"
    fi

    if env PIWIGO_CONFIG="$config" bash "$SCRIPT_DIR/sync.sh"; then
        if [[ -n "${webdav_failure_for_legacy[$connection_id]:-}" ]]; then
            summary_parts+=("WebDAV fuer Legacy #$connection_id fehlgeschlagen; Legacy-Fallback erfolgreich")
        else
            summary_parts+=("Legacy #$connection_id erfolgreich")
        fi
    else
        legacy_failure_count=$((legacy_failure_count + 1))
        summary_parts+=("Legacy #$connection_id fehlgeschlagen")
    fi
done

for legacy_id in "${!webdav_failure_for_legacy[@]}"; do
    if [[ -z "${legacy_seen[$legacy_id]:-}" ]]; then
        echo "NC Connector: WebDAV fuer Legacy #$legacy_id ist fehlgeschlagen, aber die zugeordnete Legacy-Runtime fehlt." >&2
        legacy_failure_count=$((legacy_failure_count + 1))
        summary_parts+=("Fallback #$legacy_id fehlt")
    fi
done

summary_detail="$(IFS='; '; printf '%s' "${summary_parts[*]}")"
[[ -n "$summary_detail" ]] || summary_detail="Keine Connector-Route wurde ausgefuehrt."

if [[ "$unpaired_webdav_failure" -eq 0 && "$legacy_failure_count" -eq 0 ]]; then
    if [[ "$fallback_used" -eq 1 ]]; then
        route="mixed_fallback"
        label="MIGRATION - FALLBACK AKTIV"
    elif [[ "$webdav_success_count" -gt 0 && "$legacy_run_count" -gt 0 ]]; then
        route="mixed"
        label="WebDAV + Legacy"
    elif [[ "$webdav_success_count" -gt 0 ]]; then
        route="webdav"
        label="WebDAV"
    else
        route="legacy"
        label="Legacy"
    fi

    write_route_status "$route" "$label" "$summary_detail" "$fallback_used" "1"
    echo "NC Connector: alle erforderlichen Verbindungen wurden erfolgreich verarbeitet."
    exit 0
fi

write_route_status \
    "failed" \
    "FEHLER - mindestens eine Verbindung" \
    "$summary_detail" \
    "$fallback_used" \
    "0"

echo "NC Connector: mindestens eine erforderliche Verbindung ist fehlgeschlagen." >&2
exit 1
