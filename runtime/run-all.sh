#!/usr/bin/env bash
set -Eeuo pipefail

CONFIG_DIR="/etc/bratonien-tools/nc-connector"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
shopt -s nullglob

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

read_webdav_failure_detail() {
    local config="$1"
    local connection_id piwigo_root status_file

    connection_id="$(read_config_value CONNECTION_ID "$config")"
    piwigo_root="$(read_config_value PIWIGO_ROOT "$config")"
    [[ -n "$piwigo_root" ]] || piwigo_root="/var/www/piwigo"

    if [[ -z "$connection_id" ]]; then
        printf '%s' 'WebDAV-Lauf fehlgeschlagen; Verbindungs-ID konnte nicht ermittelt werden.'
        return 0
    fi

    status_file="${piwigo_root%/}/_data/bratonien-tools/nc-connector-status/connection-${connection_id}.json"
    if [[ ! -r "$status_file" ]]; then
        printf '%s' "WebDAV-Lauf fehlgeschlagen; Detailstatus fuer Verbindung ${connection_id} ist nicht lesbar."
        return 0
    fi

    php -r '
      $file = $argv[1];
      $data = json_decode((string)@file_get_contents($file), true);
      if (!is_array($data)) {
        echo "WebDAV-Lauf fehlgeschlagen; Detailstatus ist ungueltig.";
        exit(0);
      }
      $message = trim((string)($data["message"] ?? ""));
      $detail = trim((string)($data["error_detail"] ?? ""));
      $parts = array();
      if ($message !== "") $parts[] = $message;
      if ($detail !== "") $parts[] = $detail;
      if (!$parts) $parts[] = "WebDAV-Lauf fehlgeschlagen; kein Fehlerdetail gespeichert.";
      $text = preg_replace("/\\s+/u", " ", implode(" - ", $parts));
      echo trim((string)$text);
    ' "$status_file"
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

webdav_success=0
webdav_failed=0
webdav_failure_detail=""

for config in "${webdav_configs[@]}"; do
    name="$(basename "$config")"
    echo "NC Connector WebDAV primaer: $name"
    if env PIWIGO_CONFIG="$config" bash "$SCRIPT_DIR/sync-webdav.sh"; then
        webdav_success=1
    else
        webdav_failed=1
        webdav_failure_detail="$(read_webdav_failure_detail "$config")"
        echo "NC Connector: WebDAV-Lauf fehlgeschlagen: $webdav_failure_detail" >&2
        echo "NC Connector: Legacy-Fallback bleibt verfuegbar." >&2
    fi
done

if [[ "$webdav_success" -eq 1 ]]; then
    write_route_status \
        "webdav" \
        "WebDAV (primaer)" \
        "WebDAV erfolgreich. Legacy-Fallback wurde in diesem Lauf nicht ausgefuehrt." \
        "0" \
        "1"
    echo "NC Connector: WebDAV erfolgreich; Legacy-Verbindung wird in diesem Lauf nicht ausgefuehrt."
    exit 0
fi

if [[ ${#configs[@]} -eq 0 ]]; then
    [[ -n "$webdav_failure_detail" ]] || webdav_failure_detail="WebDAV ist fehlgeschlagen; kein Detailstatus verfuegbar."
    write_route_status \
        "failed" \
        "FEHLER - kein Datenweg" \
        "WebDAV-Fehler: $webdav_failure_detail Keine Legacy-Fallback-Verbindung vorhanden." \
        "0" \
        "0"
    echo "NC Connector: WebDAV ist fehlgeschlagen und es ist keine Legacy-Fallback-Verbindung vorhanden." >&2
    exit 1
fi

echo "NC Connector: kein erfolgreicher WebDAV-Lauf; Legacy-Fallback wird ausgefuehrt."
legacy_result=0

for config in "${configs[@]}"; do
    name="$(basename "$config")"
    connection_id=""
    if [[ "$name" =~ ^connection-([0-9]+)\.conf$ ]]; then
        connection_id="${BASH_REMATCH[1]}"
    fi

    piwigo_root="$(read_config_value PIWIGO_ROOT "$config")"
    [[ -n "$piwigo_root" ]] || piwigo_root="/var/www/piwigo"
    tombstone_dir="${piwigo_root%/}/_data/bratonien-tools/nc-connector-status"

    if [[ -n "$connection_id" && -f "$tombstone_dir/deleted-$connection_id" ]]; then
        echo "NC Connector: Verbindung $connection_id wurde geloescht; Laufzeitdateien werden entfernt."
        rm -f -- "$CONFIG_DIR/connection-$connection_id.conf" \
            "$CONFIG_DIR/connection-$connection_id.db-password" \
            "$CONFIG_DIR/connection-$connection_id.piwigo-password" \
            "$CONFIG_DIR/connection-$connection_id.storages.tsv" \
            "$CONFIG_DIR/connection-$connection_id.roots.tsv"
        rm -f -- "$tombstone_dir/deleted-$connection_id"
        continue
    fi

    echo "NC Connector Legacy-Fallback: $name"
    if ! env PIWIGO_CONFIG="$config" bash "$SCRIPT_DIR/sync.sh"; then
        legacy_result=1
    fi
done

if [[ "$legacy_result" -eq 0 ]]; then
    [[ -n "$webdav_failure_detail" ]] || webdav_failure_detail="WebDAV war nicht erfolgreich; kein Detailstatus verfuegbar."
    write_route_status \
        "legacy_fallback" \
        "LEGACY-FALLBACK AKTIV" \
        "WebDAV-Fehler: $webdav_failure_detail Legacy-Fallback wurde erfolgreich ausgefuehrt." \
        "1" \
        "1"
    echo "NC Connector: Legacy-Fallback erfolgreich."
    exit 0
fi

[[ -n "$webdav_failure_detail" ]] || webdav_failure_detail="WebDAV war nicht erfolgreich; kein Detailstatus verfuegbar."
write_route_status \
    "failed" \
    "FEHLER - WebDAV und Fallback" \
    "WebDAV-Fehler: $webdav_failure_detail Legacy-Fallback ist ebenfalls fehlgeschlagen." \
    "1" \
    "0"

if [[ "$webdav_failed" -eq 1 ]]; then
    echo "NC Connector: WebDAV und Legacy-Fallback sind fehlgeschlagen." >&2
fi
exit 1
