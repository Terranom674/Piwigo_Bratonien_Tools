# Aktueller Entwicklungsstand

## Version

**Bratonien Tools 0.9.7.1.7**

Verbindliches Versionsschema: **0.x.x.x.x**.

Zielplattform der produktiven NC-Connector-Synchronisierung ist derzeit **Piwigo 16.4.0**.

## NC Connector

Der produktive Verbindungsweg ist **Nextcloud per WebDAV**.

### Verbindungsmodell

- `adapter=remote`
- `source_mode=webdav-placeholder`
- Nextcloud-Adresse, Benutzer und Passwort/App-Passwort werden pro Verbindung verschlüsselt gespeichert.
- Piwigo-API und Benutzername/Passwort-Fallback werden pro Verbindung gespeichert.
- Der Assistent zeigt ausschließlich per WebDAV sichtbare Verzeichnisse.
- Ein leerer `webdav_path` ist ein gültiger Benutzer-Root.

### Root-Verhalten

Bei `webdav_path=""` wird die technische Nextcloud-Benutzerebene nicht als Piwigo-Album angelegt.

Direkte Unterordner des Nextcloud-Benutzerroots werden als Top-Level-Piwigo-Alben gespiegelt. Direkt im Root liegende Einzelbilder verbleiben auf Root-Ebene des verbindungseigenen Shadow Trees und werden durch die Orphan-Logik verarbeitet.

Es gibt keine fest verdrahteten Album-Namen.

### Laufzeit

Der gemeinsame Runner verarbeitet WebDAV-Verbindungen in dieser Reihenfolge:

1. `runtime/reconcile-webdav.php`
2. `runtime/repair-webdav-orphans.php`
3. `runtime/cleanup-webdav-piwigo.php`
4. `runtime/sync-webdav.sh` für jede vorhandene WebDAV-Konfiguration

Verbindungsspezifische Runtime-Konfigurationen liegen unter `/etc/bratonien-tools/nc-connector/`. Zustandsdaten liegen unter `/var/lib/bratonien-tools/nc-connector/connection-ID`.

State-Verzeichnisse sind für die `www-data`-Gruppe traversierbar/lesbar. `webdav-map.json` wird auch nach atomaren Rewrites mit passenden Runtime-Rechten gehalten.

### Platzhalterquelle, Metadaten und Shadow Tree

`runtime/lib/build_webdav_placeholder_source.py` bildet die Nextcloud-Inhalte als lokale Platzhalterquelle ab. Für Bilder existieren physische 1×1-Platzhalterdateien; Originale werden nicht dauerhaft heruntergeladen.

Der WebDAV-Scan übernimmt `fileid`, `width`, `height`, MIME-Typ, Größe, ETag und WebDAV-Pfad in `webdav-map.json`. `runtime/lib/sync-webdav-metadata.php` überträgt die Originalmaße nach dem normalen Piwigo-Dateisync in `piwigo_images`.

Die physische Source-Struktur wird für den Runtime-Swap mit `root:www-data` und schreibbaren Gruppenrechten angelegt bzw. nach dem Sync gesetzt. Damit kann der Webserver die Platzhalterquelle während der On-Demand-Erzeugung temporär austauschen und anschließend wiederherstellen.

`runtime/lib/shadow_tree.py` erzeugt atomar die Piwigo-kompatible Dateisystemstruktur. Jede WebDAV-Verbindung besitzt ihre eigene Piwigo-Site.

### Piwigo-Synchronisierung

`runtime/lib/piwigo-sync.php` bevorzugt `bratonien.nc.syncProductive` für Piwigo 16.4.0. Wenn keine Piwigo-API eingerichtet ist, wird der gespeicherte Administrator-/Webmaster-Fallback verwendet und Piwigos nativer `site_update` ausgeführt.

Danach läuft `bratonien.nc.syncOrphans` für die konkrete WebDAV-Site.

Albumdatensätze, Bilddatensätze, Bild-Kategorie-Verknüpfungen und Derivate bleiben Aufgabe von Piwigo.

### On-Demand-Materialisierung und Derivaterzeugung

`include/webdav_materialize_runtime.inc.php` löst aus dem Piwigo-Bildpfad die konkrete Verbindung, den Nextcloud-Root, den relativen WebDAV-Pfad und die Mapping-Metadaten auf.

Für ein noch nicht vorhandenes Derivat läuft `webdav-derivative.php`:

1. Zugriffsprüfung für das Piwigo-Bild;
2. Auswahl einer temporären Nextcloud-Quelle;
3. temporäres Bereitstellen dieser Quelle am exakten von Piwigo erwarteten Quellpfad;
4. Erzeugung des normalen Piwigo-Derivats mit Piwigos eigener `pwg_image`-/Derivative-Logik im selben PHP-Prozess;
5. Wiederherstellung des Shadow-/Platzhalterzustands;
6. Auslieferung des Piwigo-Derivats.

Ein interner HTTP-Self-Request auf `i.php` wird nicht verwendet.

Für `custom:s9999x250` und `standard:square` wird bevorzugt der authentifizierte Nextcloud-Endpunkt **`/index.php/core/preview`** anhand der `fileid` verwendet. Die Preview wird mit ungefähr doppelter benötigter Piwigo-Auflösung und unter Berücksichtigung des Original-Seitenverhältnisses angefordert. Varianten mit Bildrotation werden in dieser Stufe nicht über Preview erzeugt.

Ist keine geeignete Preview planbar oder schlägt der Preview-Abruf fehl, erfolgt der vollständige WebDAV-Originaldownload. Große/originale Anforderungen verwenden weiterhin die Originalquelle.

Die temporäre Preview bzw. das temporäre Original wird nach der Derivaterzeugung entfernt. Das fertige Derivat verbleibt ausschließlich in Piwigos normalem `_data/i`-Cache. Es gibt keinen parallelen permanenten Connector-Derivat-Cache.

### Piwigo-Lazyload

Piwigos nativer Thumbnail-Loader ruft nicht gecachte Derivate mit `ajaxload=true` per AJAX ab und erwartet JSON mit einer fertigen `url`.

`webdav-derivative.php` unterstützt dieses Protokoll für:

- bereits vorhandene Zielderivate;
- `target_created_while_waiting`;
- frisch erzeugte Derivate.

Normale direkte Bildanforderungen liefern weiterhin das Bild selbst. Dadurch kann Piwigos eigener Placeholder nach erfolgreicher Erzeugung ohne Seiten-Reload durch das echte Thumbnail ersetzt werden.

### Bilddetail-Navigation

Die eigene Bildnavigation liegt in `js/picture_navigation.js` und `css/picture_navigation.css`. Der aktuelle Stand enthält die Behandlung des sichtbaren Zwischenzustands für WebDAV-Nachbar-Thumbnails, damit während des Nachladens kein Browser-Symbol für ein kaputtes Bild stehen bleiben soll.

### Orphan-Logik

Direkt im WebDAV-Site-Root liegende Bilder werden nicht künstlich in ein Album gezwungen. Sie werden durch `bratonien.nc.syncOrphans` als Piwigo-Orphans synchronisiert.

`runtime/repair-webdav-orphans.php` läuft vor den verbindungsspezifischen Syncs und repariert historische WebDAV-Orphan-Zustände.

### Private Top-Level-Alben

WebDAV-Unterordner können echte private Top-Level-Piwigo-Alben sein. Beim Connector-Fallback-Sync sorgt `bratonien_tools_preserve_connector_top_level_access()` dafür, dass der verwendete Piwigo-Administrator den Zugriff behält. Nach Änderungen wird der Benutzer-Cache invalidiert.

Die allgemeine Funktion `bratonien_tools_preserve_private_album_access()` in `include/album_shares.inc.php` bleibt davon getrennt.

### Administrations-Dashboard

Für Piwigo 16.4.0 wird ausschließlich die Standardtemplate-Bedingung der Album-Kachel von `NB_ALBUMS > 1` auf `NB_ALBUMS > 0` angepasst. Die Albumzahl selbst stammt unverändert aus Piwigo.

### Bildauslieferung

Fehlende Piwigo-Derivate werden durch den On-Demand-Gate erzeugt. Bereits vorhandene Derivate werden direkt aus Piwigos eigenem Derivat-Cache ausgeliefert.

Für direkte Originalanforderungen existiert weiterhin die serverseitige WebDAV-Originalauslieferung. Nextcloud-Zugangsdaten werden dem Browser nicht offengelegt.

### Diagnose

Der WebDAV-Derivative-Gate schreibt Request-bezogene `[BRAT-WD ...]`-Diagnoseeinträge. Relevante Ereignisse sind unter anderem `preview_start`, `preview_failed`, `download_done`, `swap_begin`, `swapped`, `generate_start`, `generate_done`, `restored`, `target_created_while_waiting` und `serve`.

## Self-Updater

`include/self_update.inc.php` liest den Zielstand von GitHub, bindet ihn an einen konkreten Commit, prüft die `main.inc.php` per SHA-256, erstellt vor dem Austausch ein Backup und aktualisiert nur auf eine **höhere** Plugin-Version.

Das verbindliche Versionsschema ist **0.x.x.x.x**.

## Aktuell relevante Dateien

- `main.inc.php` – Plugin-Hooks und Version
- `include/self_update.inc.php` – integrierter Self-Updater
- `include/webdav_materialize_runtime.inc.php` – WebDAV-Quellauflösung und Gate-URL-Erzeugung
- `webdav-derivative.php` – On-Demand-Gate, Preview/Original-Fallback, Piwigo-Derivaterzeugung und AJAX-Lazyload-Antwort
- `webdav-image.php` – direkte WebDAV-Originalauslieferung
- `include/nc_productive_ws.inc.php` – direkter Piwigo-Core-Sync für 16.4.0
- `include/nc_orphan_ws.inc.php` – Root-Orphan-Synchronisierung
- `runtime/reconcile-webdav.php` – Runtime-Reconcile und State-Berechtigungen
- `runtime/repair-webdav-orphans.php` – Orphan-Reparatur
- `runtime/cleanup-webdav-piwigo.php` – Bereinigung verwaister Piwigo-Sites
- `runtime/sync-webdav.sh` – verbindungsspezifischer Sync und Runtime-/Source-Berechtigungen
- `runtime/run-all.sh` – gemeinsamer Runner
- `runtime/lib/build_webdav_placeholder_source.py` – WebDAV-Scan, Mapping und Platzhalterquelle
- `runtime/lib/sync-webdav-metadata.php` – Übernahme der Originalmaße nach Piwigo
- `runtime/lib/shadow_tree.py` – atomarer Shadow Tree
- `runtime/lib/piwigo-sync.php` – Piwigo-API/Fallback-Sync
- `js/picture_navigation.js` – Bilddetail-Navigation und Thumbnail-Zwischenzustand
- `css/picture_navigation.css` – Bilddetail-Navigationsdarstellung

## Nicht mehr das produktive WebDAV-Modell

Nicht mehr maßgeblich sind ein separater permanenter WebDAV-Derivatbestand oder ein interner HTTP-Aufruf von `webdav-derivative.php` zurück auf Piwigos `i.php`.

Maßgeblich ist:

**physischer Platzhalter + temporäre Nextcloud-Preview bzw. Original-Fallback + Piwigos Bildverarbeitung im selben PHP-Prozess + normales Piwigo-Derivat + Wiederherstellung des Platzhalters**.

## Prüfpflicht vor Merge

Die GitHub-Actions-Konfiguration prüft mindestens:

- PHP-Syntax für 8.2, 8.3, 8.4 und 8.5
- doppelte globale `bratonien_tools_*`-Funktionen
- JavaScript-Syntax
- Python-Syntax/Compile
- Shell-Syntax mit `bash -n`
