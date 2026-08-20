# Aktueller Entwicklungsstand

## Version

**Bratonien Tools 0.9.6.8.19**

Zielplattform der produktiven NC-Connector-Synchronisierung ist derzeit **Piwigo 16.4.0**.

## NC Connector

Der NC Connector besitzt einen produktiven Verbindungsweg: **Nextcloud per WebDAV**.

### Verbindungsmodell

- `adapter=remote`
- `source_mode=webdav-placeholder`
- Nextcloud-Adresse, Benutzer und Passwort/App-Passwort werden pro Verbindung verschlüsselt gespeichert.
- Piwigo-API und Benutzername/Passwort-Fallback werden ebenfalls pro Verbindung gespeichert.
- Der Assistent zeigt ausschließlich Verzeichnisse, auf die der angemeldete Nextcloud-Benutzer per WebDAV zugreifen kann.
- Ein leerer `webdav_path` ist ein gültiger Root und bedeutet den Dateibereich des authentifizierten Nextcloud-Benutzers.

### Root-Verhalten

Bei `webdav_path=""` wird die technische Nextcloud-Benutzerebene nicht als Piwigo-Album angelegt.

Direkte Unterordner des Nextcloud-Benutzerroots werden als Top-Level-Piwigo-Alben gespiegelt. Direkt im Root liegende Einzelbilder verbleiben auf Root-Ebene des verbindungseigenen Shadow Trees und werden durch die bestehende Orphan-Logik behandelt.

Es gibt keine fest verdrahteten Album-Namen.

### Laufzeit

Der gemeinsame Runner verarbeitet WebDAV-Verbindungen in dieser Reihenfolge:

1. `runtime/reconcile-webdav.php`
2. `runtime/repair-webdav-orphans.php`
3. `runtime/cleanup-webdav-piwigo.php`
4. `runtime/sync-webdav.sh` für jede vorhandene WebDAV-Konfiguration

Die verbindungsspezifischen Runtime-Konfigurationen liegen unter `/etc/bratonien-tools/nc-connector/`. Zustandsdaten liegen unter `/var/lib/bratonien-tools/nc-connector/connection-ID`.

Der Reconcile- und Shell-Pfad unterstützt den leeren WebDAV-Root ausdrücklich und gibt ihn als `--root ""` an den Platzhalter-Builder weiter.

### Platzhalterquelle und Shadow Tree

`runtime/lib/build_webdav_placeholder_source.py` bildet die Nextcloud-Inhalte als lokale Platzhalterquelle ab. Für Bilder existieren physische 1×1-Platzhalterdateien; die Originale werden dabei nicht dauerhaft heruntergeladen.

`runtime/lib/shadow_tree.py` erzeugt atomar die Dateisystemstruktur, die Piwigo als physische Site sieht. Shadow-Verzeichnisse werden so angelegt, dass der Webserver die für die On-Demand-Materialisierung nötigen Dateisystemoperationen ausführen kann.

Jede WebDAV-Verbindung besitzt ihre eigene Piwigo-Site. Es gibt keinen zusätzlichen Sync auf Site 1.

### Piwigo-Synchronisierung

`runtime/lib/piwigo-sync.php` bevorzugt `bratonien.nc.syncProductive` für Piwigo 16.4.0. Wenn für die Verbindung keine Piwigo-API eingerichtet ist, wird der gespeicherte Administrator-/Webmaster-Benutzername/Passwort-Fallback verwendet und Piwigos nativer `site_update` ausgeführt.

Danach wird `bratonien.nc.syncOrphans` für die konkrete WebDAV-Site ausgeführt.

Der Shadow Tree stellt Piwigo ausschließlich die physische Album-/Bildstruktur bereit. Albumdatensätze, Bilddatensätze, Bild-Kategorie-Verknüpfungen und Piwigo-Derivate bleiben Aufgabe von Piwigo.

### On-Demand-Materialisierung vor `i.php`

Die produktive WebDAV-Derivaterzeugung sitzt vor Piwigos `i.php`.

`include/webdav_materialize_runtime.inc.php` löst aus dem Piwigo-Bildpfad die konkrete Verbindung, den ausgewählten Nextcloud-Root und den relativen WebDAV-Pfad auf.

Für ein noch nicht vorhandenes Derivat läuft `webdav-derivative.php`:

1. Zugriffsprüfung für das Piwigo-Bild;
2. Download des Originals aus Nextcloud in eine temporäre Datei;
3. temporäres Bereitstellen des Originals am exakten von Piwigo erwarteten Quellpfad;
4. Aufruf des nativen Piwigo-`i.php`;
5. Piwigo erzeugt sein normales Derivat;
6. Wiederherstellung des Shadow-/Platzhalterzustands;
7. Auslieferung des Piwigo-Derivats.

Das Original verbleibt in Nextcloud. Es gibt keine dauerhafte vollständige Originalkopie in Piwigo.

### Orphan-Logik

Direkt im WebDAV-Site-Root liegende Bilder werden nicht künstlich in ein Album gezwungen. Sie werden durch `bratonien.nc.syncOrphans` als Piwigo-Orphans synchronisiert.

`runtime/repair-webdav-orphans.php` läuft vor den verbindungsspezifischen Syncs und repariert historische WebDAV-Orphan-Zustände.

### Private Top-Level-Alben

Da die technische Nextcloud-Benutzerebene nicht mehr als Elternalbum existiert, können WebDAV-Unterordner echte private Top-Level-Piwigo-Alben sein. Beim Connector-Fallback-Sync sorgt `bratonien_tools_preserve_connector_top_level_access()` dafür, dass der verwendete Piwigo-Administrator auf diese privaten Top-Level-Alben zugreifen kann. Nach Änderungen wird der Benutzer-Cache invalidiert.

Die allgemeine bestehende Funktion `bratonien_tools_preserve_private_album_access()` in `include/album_shares.inc.php` bleibt davon getrennt.

### Administrations-Dashboard

Piwigo 16.4.0 zeigt die Album-Kachel im Standardtemplate nur bei `NB_ALBUMS > 1` an. Dadurch verschwand die Kachel nach dem korrekten Entfernen der künstlichen Nextcloud-Benutzerebene, sobald nur noch ein echtes Album vorhanden war.

Bratonien Tools setzt für die Verwaltungsübersicht einen Template-Prefilter und ändert ausschließlich diese Anzeigebedingung auf `NB_ALBUMS > 0`. Die Albumzahl selbst stammt weiterhin unverändert aus Piwigos `get_pwg_general_statitics()`.

### Bildauslieferung

Fehlende Piwigo-Derivate werden durch den On-Demand-Gate erzeugt. Bereits vorhandene Derivate werden direkt aus Piwigos eigenem Derivat-Cache ausgeliefert.

Für direkte Originalanforderungen existiert weiterhin die serverseitige WebDAV-Originalauslieferung. Nextcloud-Zugangsdaten werden dem Browser nicht offengelegt.

### Löschen

Eine WebDAV-Verbindung kann unabhängig von ihrer Reihenfolge gelöscht werden. Beim Löschen werden die zugehörige Piwigo-Site und die zugehörigen Piwigo-Datensätze entfernt. Die Originaldateien in Nextcloud bleiben unangetastet. Verwaiste WebDAV-Runtime- und Piwigo-Daten werden vom gemeinsamen Lauf bereinigt.

### Diagnose

Der WebDAV-Derivative-Gate schreibt Request-bezogene `[BRAT-WD ...]`-Diagnoseeinträge. Der Verbindungsstatus wird pro Verbindung geführt. Runtime-Probleme lassen sich zusätzlich über `bratonien-nc-connector.service` nachvollziehen.

## Aktuell relevante Dateien

- `main.inc.php` – Plugin-Hooks, Connector-Sync-Hilfen, Dashboard-Prefilter
- `include/webdav_materialize_runtime.inc.php` – WebDAV-Quellauflösung und Materialisierung
- `webdav-derivative.php` – On-Demand-Gate vor Piwigos `i.php`
- `webdav-image.php` – direkte WebDAV-Originalauslieferung
- `include/nc_productive_ws.inc.php` – direkter Piwigo-Core-Sync für 16.4.0
- `include/nc_orphan_ws.inc.php` – Root-Orphan-Synchronisierung
- `runtime/reconcile-webdav.php` – Runtime-Reconcile einschließlich leerem WebDAV-Root
- `runtime/repair-webdav-orphans.php` – Orphan-Reparatur
- `runtime/cleanup-webdav-piwigo.php` – Bereinigung verwaister Piwigo-Sites
- `runtime/sync-webdav.sh` – verbindungsspezifischer Sync
- `runtime/run-all.sh` – gemeinsamer Runner
- `runtime/lib/build_webdav_placeholder_source.py` – WebDAV-Scan und Platzhalterquelle
- `runtime/lib/shadow_tree.py` – atomarer Shadow Tree
- `runtime/lib/piwigo-sync.php` – Piwigo-API/Fallback-Sync

## Nicht mehr das produktive WebDAV-Modell

Nicht mehr maßgeblich für die produktive WebDAV-Derivaterzeugung ist das frühere Modell, bei dem Bild-URLs einfach auf einen separaten WebDAV-Bildstream umgebogen wurden oder der Connector selbst einen parallelen vollständigen Derivatbestand verwalten sollte.

Maßgeblich ist: **physischer Platzhalter + On-Demand-Original + natives Piwigo-`i.php` + Wiederherstellung des Platzhalters**.

## Prüfpflicht vor Merge

Die GitHub-Actions-Konfiguration prüft mindestens:

- PHP-Syntax für 8.2, 8.3, 8.4 und 8.5
- doppelte globale `bratonien_tools_*`-Funktionen
- JavaScript-Syntax
- Python-Syntax/Compile
- Shell-Syntax mit `bash -n`
