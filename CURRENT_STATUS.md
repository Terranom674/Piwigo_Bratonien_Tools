# Aktueller Entwicklungsstand

## Version

**Bratonien Tools 0.9.6.2**

## NC Connector

Der NC Connector besitzt nur noch einen produktiven Verbindungsweg: **Nextcloud per WebDAV**.

### Verbindungsmodell

- `adapter=remote`
- `source_mode=webdav-placeholder`
- Nextcloud-Adresse, Benutzer und Passwort/App-Passwort werden pro Verbindung verschlüsselt gespeichert.
- Piwigo-API und Benutzername/Passwort-Fallback werden ebenfalls pro Verbindung gespeichert.
- Der Assistent zeigt ausschließlich die Verzeichnisse, auf die der angemeldete Nextcloud-Benutzer per WebDAV zugreifen kann.
- Mehrere Verzeichnisse können pro Verbindung ausgewählt werden.

### Laufzeit

Der gemeinsame Runner verarbeitet ausschließlich WebDAV-Verbindungen:

1. `runtime/reconcile-webdav.php`
2. `runtime/cleanup-webdav-piwigo.php`
3. `runtime/sync-webdav.sh` für jede vorhandene WebDAV-Konfiguration

Die verbindungsspezifischen Runtime-Konfigurationen liegen unter `/etc/bratonien-tools/nc-connector/`. Zustandsdaten liegen unter `/var/lib/bratonien-tools/nc-connector/connection-ID`.

### Shadow Tree und Piwigo

`runtime/lib/build_webdav_placeholder_source.py` bildet die ausgewählten Nextcloud-Inhalte als lokale Platzhalterquelle ab. `runtime/lib/shadow_tree.py` erzeugt daraus die Dateisystemstruktur, die Piwigo als physische Site sieht.

Jede WebDAV-Verbindung besitzt ihre eigene Piwigo-Site. `runtime/lib/piwigo-sync.php` übergibt genau diese Site an `bratonien.nc.syncProductive` und `bratonien.nc.syncOrphans`. Es gibt keinen zusätzlichen Sync auf Site 1.

Der Shadow Tree dient ausschließlich dazu, Piwigo die Alben und Bilder als Dateisystemstruktur bereitzustellen. Albumlogik, Dateisynchronisierung und die daraus folgenden Piwigo-Datensätze bleiben Aufgabe von Piwigo.

### Bildauslieferung

Die Dateien im Shadow Tree sind Platzhalter. `include/webdav_image_runtime.inc.php` erkennt die WebDAV-Zuordnung und ersetzt die Bildquelle bei der Auslieferung durch den zugehörigen Nextcloud-Inhalt. `webdav-image.php` streamt die Bilddaten serverseitig, ohne Nextcloud-Zugangsdaten an den Browser weiterzugeben.

### Löschen

Eine WebDAV-Verbindung kann unabhängig von ihrer Reihenfolge gelöscht werden. Beim Löschen werden die zugehörige Piwigo-Site und die zugehörigen Piwigo-Datensätze entfernt. Die Originaldateien in Nextcloud bleiben unangetastet. Verwaiste WebDAV-Runtime- und Piwigo-Daten werden vom gemeinsamen Lauf bereinigt.

### Diagnose

Der Status wird pro Verbindung geführt. Fehler enthalten Zeitpunkt, betroffene Verbindung, Prozesszustand und technische Details. Die Administration zeigt keine lokalen Datenbank-, Storage- oder Mount-Einstellungen mehr an.

## Entfernte Connector-Komponenten

Nicht mehr Bestandteil des Plugins sind lokale Nextcloud-Datenbankanbindungen, PostgreSQL-Reader, Source-/Activity-Views, Storage-Mappings, Host-Mounts, lokale Reconcile-/Sync-Skripte, Cutover-/Migrationswerkzeuge und der frühere Simulations-Webservice `bratonien.nc.sync`.

## Prüfpflicht vor Merge

Jede Änderung wird vor dem Merge mindestens mit folgenden GitHub-Actions-Prüfungen validiert:

- PHP-Syntax für 8.2, 8.3, 8.4 und 8.5
- Prüfung auf doppelte globale `bratonien_tools_*`-Funktionen
- JavaScript-Syntax
- Python-Syntax/Compile
- Shell-Syntax mit `bash -n`
