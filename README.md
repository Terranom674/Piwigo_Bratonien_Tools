# Piwigo Bratonien Tools

Modulares Piwigo-Plugin für Administration, Bildverarbeitung, geschützte Freigaben, Fotoauswahl und die Anbindung von Nextcloud an Piwigo.

Aktuelle Plugin-Version: **0.9.6.2**

## Grundprinzip

Bratonien Tools erweitert Piwigo um Funktionen, die in der Bratonien-Installation benötigt werden, hält die einzelnen Module aber möglichst unabhängig voneinander.

Für die Administration gilt: Der normale Nutzer sieht Aufgaben, Entscheidungen und Ergebnisse. Technische Interna werden automatisch ermittelt oder bleiben hinter ausdrücklich technischen Einstellungen verborgen.

## NC Connector

Der NC Connector bindet ausdrücklich ausgewählte Nextcloud-Inhalte ausschließlich per WebDAV an Piwigo an.

### Datenmodell

- Nextcloud bleibt die Quelle der Originaldateien.
- Originalbilder werden nicht dauerhaft in eine zweite Bibliothek unter Piwigo kopiert.
- Ein Shadow Tree erzeugt die für Piwigo benötigte Ordnerstruktur.
- Ordner werden als physische Piwigo-Alben synchronisiert.
- Root-Dateien können als Piwigo-Orphans registriert werden.
- Neu importierte physische Connector-Alben werden privat angelegt.
- Entfernte Quellen verschwinden aus Shadow Tree und Piwigo; die Nextcloud-Originale bleiben erhalten.

### Voraussetzungen

Benötigt werden nur:

- Nextcloud-Adresse;
- Nextcloud-Benutzer bzw. App-Passwort;
- normaler WebDAV-Zugriff auf die Inhalte dieses Benutzers;
- eine normale Piwigo-Installation mit ihrer vorhandenen PHP-/Bildverarbeitungsumgebung.

Nicht benötigt werden Nextcloud-Datenbankzugriff, `occ`-Adminzugriff, Storage-IDs, Backend-Pfade, zusätzliche Host-Mounts, FUSE, davfs oder rclone.

### WebDAV-Ablauf

1. Der Assistent prüft Nextcloud und liest die sichtbaren Verzeichnisse per WebDAV.
2. Ausgewählte Verzeichnisse werden rekursiv per PROPFIND eingelesen.
3. Ordner, Dateiname, Nextcloud-Datei-ID, MIME-Typ, Größe, ETag und WebDAV-Pfad werden erfasst.
4. `runtime/lib/build_webdav_placeholder_source.py` erzeugt eine lokale Platzhalterquelle ohne dauerhafte Originalkopie.
5. `runtime/lib/shadow_tree.py` baut daraus den verbindungseigenen Shadow Tree.
6. Der WebDAV-Galeriebaum liegt unter `_data/bratonien-tools/nc-webdav-gallery/connection-ID`.
7. Jede WebDAV-Verbindung wird als eigene physische Piwigo-Site registriert.
8. Piwigos Dateisynchronisierung erhält ausschließlich diese verbindungseigene Site.
9. Die WebDAV-Bildzuordnung ersetzt beim Ausliefern Platzhalter-URLs durch die echte Nextcloud-Bildquelle.
10. `webdav-image.php` streamt benötigte Bilder serverseitig; Zugangsdaten werden dem Browser nicht offengelegt.

### Piwigo-Synchronisierung

Der Connector verwendet die vorhandene Piwigo-Dateisynchronisierung für die verbindungseigene WebDAV-Site. Der Shadow Tree ist die Dateisystemdarstellung, die Piwigo für Alben und Bilder benötigt.

`runtime/lib/piwigo-sync.php` ruft dafür `bratonien.nc.syncProductive` auf. Die Methode führt den Piwigo-Core-Sync für die konkrete Site aus. `bratonien.nc.syncOrphans` arbeitet ebenfalls nur auf der übergebenen WebDAV-Site.

Die produktive Synchronisierung ist aktuell auf **Piwigo 16.4.0** abgestimmt.

### Piwigo-Zugang

Der Zugang wird pro Verbindung gespeichert. Bevorzugt wird eine Piwigo-API. Wird keine API eingerichtet, kann ein Administrator-/Webmaster-Benutzer mit Passwort als Fallback verwendet werden.

### Statusanzeige

Die Administration zeigt Laufzeitstatus und Fehler pro Verbindung. Fehlerdetails bleiben der betroffenen Verbindung zugeordnet.

### Löschen einer Verbindung

Beim Löschen einer WebDAV-Verbindung werden ihre zugehörige Piwigo-Site und die dazugehörigen Piwigo-Datensätze entfernt. Nextcloud-Dateien bleiben unverändert. Runtime-, Shadowtree-, Source-, Preview- und Statusdaten werden bereinigt.

### Runtime

Aktive WebDAV-Verbindungen werden über den gemeinsamen Runner verarbeitet:

- `bratonien-nc-connector.timer`
- `bratonien-nc-connector.service`
- `runtime/run-all.sh`

Verbindungsspezifische Konfigurationen liegen unter `/etc/bratonien-tools/nc-connector/`, State-Daten unter `/var/lib/bratonien-tools/nc-connector/connection-ID`.

Der Lauf besteht aus:

1. WebDAV-Verbindungen reconciliieren;
2. verwaiste WebDAV-Piwigo-Inhalte bereinigen;
3. jede vorhandene WebDAV-Verbindung synchronisieren.

## Bildcache

Bratonien Tools kann Piwigo-Bildderivate gezielt leeren, vorhandene Bildgrößen neu erzeugen und den Cache-Aufbau als Worker-Prozess starten bzw. abbrechen. Originalbilder bleiben unangetastet.

## Wasserzeichenverwaltung

- eigene Wasserzeichendateien;
- Wasserzeichenprofile;
- globale Regeln für öffentliche/private Alben;
- Album-Ausnahmen und Vererbung;
- Position, Transparenz und Skalierung;
- eigener Runtime-Filter für Piwigo-Derivate;
- Sicherung der bisherigen Piwigo-Wasserzeichenkonfiguration beim Aktivieren.

## Bilddateien und Pfade

- Upload nach `local/bratonien/assets/`;
- Vorschau, Abmessungen und Dateigröße;
- Löschen verwalteter Assets;
- konfigurierbare PHP-Uploadgrenzen über `.user.ini`, sofern die Serverkonfiguration dies zulässt.

## Fotoauswahl und Batch Downloader

Bratonien Tools erweitert Albumseiten um eine öffentliche bzw. berechtigungsabhängige Bildauswahl und übergibt nur die ausgewählten Bild-IDs an das Piwigo-Plugin Batch Downloader.

**Abhängigkeit:** Batch Downloader muss installiert und aktiv sein.

## Fortlaufende Bildtitel

Die globale Piwigo-Stapelverarbeitung erhält die Aktion **Fortlaufende Bildtitel** mit Präfix, Startnummer, Stellenzahl, Sortierung und Schutz vorhandener individueller Titel. Physische Dateinamen werden nicht verändert.

## Albumzugriff

Alben können in Bratonien Tools zwischen öffentlich und privat umgeschaltet werden. Beim Sperren wird verhindert, dass sich der handelnde Benutzer versehentlich selbst aussperrt.

## Geschützte Albumfreigaben

Private Alben können über eigene Freigabelinks geteilt werden, optional mit Passwort und Ablaufdatum. Die Freigaben verwenden einen technischen Piwigo-Benutzer mit minimalem Albumzugriff und können widerrufen werden.

## Erweiterte Bildnavigation

Die Piwigo-Bilddetailseite erhält responsive Navigationszonen für vorheriges Bild, nächstes Bild, Rückkehr zur Übersicht und PhotoSwipe/Vollbild.

## Selbstaktualisierung

Der integrierte Updater liest den Zielstand aus GitHub, bindet ein Update an einen konkreten Commit, prüft Version und SHA-256, erstellt vor dem Austausch ein Backup und verlangt Webmaster-Rechte sowie die notwendigen Servervoraussetzungen.

## Wichtige Dateien und Verzeichnisse

- `main.inc.php` – Plugin-Einstieg und Runtime-Hooks
- `admin.php` – zentraler Admin-Controller
- `include/nc_connector.inc.php` – WebDAV-Verbindungsmodell
- `include/nc_connector_wizard.inc.php` – Verbindungsassistent
- `include/nc_connector_wizard_webdav_flow.inc.php` – WebDAV-Wizardablauf
- `include/nc_connector_delete_safe.inc.php` – Löschen einschließlich WebDAV-Piwigo-Inhalten
- `include/nc_productive_ws.inc.php` – Piwigo-Core-Dateisync
- `include/nc_orphan_ws.inc.php` – Orphan-Synchronisierung der konkreten WebDAV-Site
- `include/webdav_image_runtime.inc.php` – WebDAV-Bildzuordnung und URL-Filter
- `webdav-image.php` – berechtigungsgeprüfter WebDAV-Bildstream
- `runtime/reconcile-webdav.php` – WebDAV-Runtime-Reconcile
- `runtime/cleanup-webdav-piwigo.php` – Bereinigung verwaister WebDAV-Piwigo-Sites
- `runtime/sync-webdav.sh` – Ablauf einer WebDAV-Verbindung
- `runtime/run-all.sh` – gemeinsamer WebDAV-Runner
- `runtime/lib/build_webdav_placeholder_source.py` – rekursiver WebDAV-Scan und Platzhalterquelle
- `runtime/lib/shadow_tree.py` – atomarer Shadow Tree
- `runtime/lib/piwigo-sync.php` – Piwigo-Sync mit API/Fallback
- `main-cache-build.php` – allgemeiner Piwigo-Derivat-/Cache-Builder
- `include/self_update.inc.php` – Self-Updater
- `include/album_shares.inc.php` – geschützte Albumfreigaben
- `include/public_selection.inc.php` – Fotoauswahl
- `include/batch_titles.inc.php` – fortlaufende Titel
- `include/watermark_*.inc.php` – Wasserzeichen-Engine
- `tools/` – administrative Einzelwerkzeuge
- `template/`, `js/`, `css/` – Oberfläche

## Sicherheit

- administrative Schreibaktionen verwenden Piwigos CSRF-Schutz;
- Connector-Zugangsdaten werden verschlüsselt gespeichert;
- Nextcloud-Zugangsdaten werden verbindungseigen gespeichert und nur serverseitig verwendet;
- Wizard-Geheimnisse werden nicht im Browser-Web-Storage persistiert;
- produktive Piwigo-API ist versionsgebunden;
- Originalbilder werden vom Connector nicht gelöscht;
- WebDAV-Originalbilder werden nicht dauerhaft lokal gespeichert;
- Update-Pakete werden an Commit und Hash gebunden.
