# Piwigo Bratonien Tools

Modulares Piwigo-Plugin für Administration, Bildverarbeitung, geschützte Freigaben, Fotoauswahl und die Anbindung von Nextcloud an Piwigo.

Aktuelle Plugin-Version: **0.9.5.6**

## Grundprinzip

Bratonien Tools erweitert Piwigo um Funktionen, die in der Bratonien-Installation benötigt werden, hält die einzelnen Module aber möglichst unabhängig voneinander.

Für die Administration gilt: Der normale Nutzer sieht Aufgaben, Entscheidungen und Ergebnisse. Technische Interna werden automatisch ermittelt oder bleiben hinter ausdrücklich technischen Einstellungen verborgen.

## NC Connector

Der NC Connector synchronisiert ausdrücklich ausgewählte Nextcloud-Inhalte mit Piwigo.

### Datenmodell

- Nextcloud bleibt die Quelle der Originaldateien.
- Originalbilder werden nicht in eine zweite dauerhafte Bibliothek kopiert.
- Ein Shadow Tree erzeugt die für Piwigo benötigte Verzeichnisstruktur.
- Der bestehende produktive Weg verwendet lokale bereits vorhandene Speicherpfade und Symlinks auf die Originale.
- Ordner werden als physische Piwigo-Alben synchronisiert.
- Root-Dateien können als echte Piwigo-Orphans ohne Albumzuordnung registriert werden.
- Entfernte Quellen werden aus Shadow Tree und Piwigo entfernt, das Nextcloud-Original bleibt erhalten.
- Neu importierte physische Connector-Alben werden privat angelegt.

### Bestehende produktive Quellenmodi

Der bestehende Connector unterstützt weiterhin getrennte Quellenmodi:

- `legacy-view`
- `user-shares`
- `selected-fileids`

Diese Modi bleiben während der Entwicklung des neuen WebDAV-Wegs vollständig erhalten. Es gibt keine automatische Migration bestehender Verbindungen.

### Verbindungsassistent

Neue Verbindungen werden bevorzugt mit einem Endnutzer-Assistenten angelegt.

Der aktuelle Assistent kann Nextcloud per HTTP/HTTPS und OCS prüfen und Verzeichnisse per WebDAV anzeigen. Für den bestehenden produktiven lokalen Weg existieren weiterhin PostgreSQL-/View-/Storage-Mapping-Schritte, sofern sie benötigt werden.

Wizard-Geheimnisse werden während der Einrichtung serverseitig in der Sitzung gehalten. Ein fehlgeschlagener Test leert die Eingaben nicht. Ein API-Test verändert die dauerhafte API-Konfiguration erst beim erfolgreichen Abschluss des Assistenten.

Die vollständige technische Maske bleibt weiterhin über **Ohne Assistent anlegen** sowie bei bestehenden Verbindungen unter **Technische Einstellungen** erreichbar.

### Lokaler produktiver Adapter

Der derzeit produktive Adapter verwendet je nach bestehender Verbindung:

- PostgreSQL-Reader;
- Source-/Activity-Views;
- explizite Storage-Zuordnungen auf bereits vorhandene lokale Mounts;
- Plugin-eigene Runtime und getrennten State pro Verbindung.

Dieser Weg bleibt erhalten, bis der neue WebDAV-Weg vollständig End-to-End funktioniert.

### Neuer WebDAV-Weg

Der neue Quellenmodus wird zusätzlich zu den bestehenden Modi entwickelt als `webdav-placeholder`.

Ziel:

- nur normale Nextcloud-Anmeldung bzw. App-Passwort und WebDAV benötigen;
- keine Nextcloud-PostgreSQL-Rechte voraussetzen;
- keine Storage-IDs oder Backend-Pfade voraussetzen;
- keine zusätzlichen Host-Mounts voraussetzen;
- kein FUSE, davfs oder rclone voraussetzen;
- keine Originalbilder dauerhaft nach Piwigo kopieren.

Grundidee:

1. Nextcloud-Verzeichnisse werden über WebDAV/PROPFIND gelesen.
2. Ordner, Dateinamen, Datei-ID, MIME-Typ, Größe, ETag und WebDAV-Pfad werden erfasst.
3. Für Bilder wird nur eine winzige lokale Platzhalterquelle erzeugt.
4. Der bestehende Shadow Tree bildet daraus die reale Ordner-/Dateinamensstruktur für Piwigo.
5. Ein Mapping verbindet den Piwigo-/Shadow-Tree-Pfad mit der WebDAV-Quelle.
6. Wenn Piwigo echte Bilddaten benötigt, wird das Original bei Bedarf über WebDAV gelesen.
7. Piwigo-Derivate werden normal in `_data/i/` gecacht.
8. Das Original bleibt ausschließlich in Nextcloud.

Vorhandene Bausteine:

- `runtime/lib/build_webdav_placeholder_source.py` – rekursiver WebDAV-Scan, Platzhalterquelle, Manifest und Mapping;
- `include/nc_connector_webdav.inc.php` – paralleler, zunächst deaktivierter Connection-Typ `webdav-placeholder`;
- Secret-Format v3 mit verbindungseigenem `nextcloud_user` und `nextcloud_password`.

Neue WebDAV-Testverbindungen werden als `adapter=remote` und deaktiviert gespeichert. Sie verändern keine bestehenden Verbindungen und werden noch nicht von der produktiven Runtime aktiviert.

### Gemessene WebDAV-Performance

Ein realer Test mit einem 16.091.204-Byte-Bild ergab vom Piwigo-System:

- interne Nextcloud-Adresse: 1,829 s bei 8.796.915 Byte/s;
- externe Nextcloud-Adresse: 1,865 s bei 8.627.524 Byte/s.

Damit war die externe Verbindung in diesem Test nur ungefähr 2 % langsamer. Der WebDAV-Ansatz wird deshalb weiterverfolgt. Wichtig bleibt, dass Piwigo-Derivate lokal gecacht werden und WebDAV nicht bei jedem Thumbnail-Aufruf erneut verwendet wird.

### Activity Gate

Der bestehende regelmäßige Lauf berücksichtigt:

- Nextcloud-Aktivität;
- Signatur der sichtbaren Quellen;
- Quiet-Time;
- maximale Wartezeit;
- periodischen Full-Sync;
- notwendigen Reparaturlauf bei beschädigtem Shadow Tree oder fehlenden Piwigo-Alben.

Der neue WebDAV-Modus erhält später eine eigene Änderungserkennung, insbesondere über WebDAV-Metadaten wie ETag.

### Piwigo-Synchronisierung

Der produktive Lauf ist API-first:

1. `bratonien.nc.syncProductive` für physische Alben und Bilder;
2. `bratonien.nc.syncOrphans` für Root-Dateien;
3. Benutzername/Passwort nur als optionaler Fallback.

Die produktive direkte API-Synchronisierung ist aktuell ausdrücklich für **Piwigo 16.4.0** freigegeben. Bei einer anderen Piwigo-Version wird sie nicht stillschweigend als kompatibel angenommen.

Der direkte produktive Sync führt außerdem die für den normalen Piwigo-Import relevanten Nacharbeiten aus, darunter Albuminformationen, globale Ränge, Bildinformationen, Pfade und Cache-Invalidierung. Er ruft dafür keine Admin-Seite fernsteuernd auf.

### Runtime

Aktive bestehende Verbindungen werden über einen gemeinsamen systemd-Timer verarbeitet:

- `bratonien-nc-connector.timer`
- `bratonien-nc-connector.service`
- `runtime/run-all.sh`
- `runtime/sync.sh`

Verbindungsspezifische Konfigurationen liegen unter `/etc/bratonien-tools/nc-connector/`, State-Daten unter `/var/lib/bratonien-tools/nc-connector/connection-ID`.

Der Shadow-Tree-Austausch besitzt einen Rollback: Scheitert der Wechsel auf den neuen Baum, wird der vorherige Galeriebaum wiederhergestellt.

Der WebDAV-Parallelweg wird in der nächsten Bauphase mit einem eigenen Reconcile-/Sync-Zweig ergänzt. Bis dahin bleibt er deaktiviert und kann keine bestehende Runtime beeinflussen.

### Statusanzeige

Die Administration zeigt letzten und nächsten Lauf. Der Status wird regelmäßig im Hintergrund abgefragt. Dafür wird die Admin-Seite **nicht** neu geladen; offene Dialoge, Assistenten und aufgeklappte Bereiche bleiben erhalten.

### Bestehende Verbindungen

Bestehende Verbindungen können:

- umbenannt;
- geprüft;
- technisch bearbeitet, solange sie nicht aktiv sind;
- deaktiviert und anschließend gelöscht werden.

Seit 0.9.5.5 hängt das Löschen einer Verbindung nicht mehr davon ab, dass der Webserver in Root-eigene Runtime-Verzeichnisse schreiben kann. Die Datenbankverbindung wird entfernt; verwaiste Runtime-Dateien werden vor einem späteren Sync bereinigt.

Das Löschen einer Connector-Verbindung entfernt keine Nextcloud-Originale und keine Piwigo-Bilder.

### Migrationsregel für WebDAV

Der bestehende lokale Weg bleibt so lange vollständig erhalten, bis `webdav-placeholder` End-to-End funktioniert.

Vor einer freiwilligen Migration bestehender Verbindungen müssen mindestens erfolgreich getestet sein:

- Verbindungsanlage;
- Verzeichnisauswahl;
- Shadow Tree;
- Piwigo-Registrierung;
- echte Bildausgabe;
- Derivat-Cache;
- Änderungserkennung;
- Löschen/Deaktivieren;
- Fehlerbehandlung.

## Bildcache

- Piwigo-Bildderivate gezielt leeren;
- vorhandene Bildgrößen neu erzeugen;
- Cache-Aufbau als Worker-Prozess starten und abbrechen;
- Worker-Zahl automatisch oder manuell konfigurieren;
- Originalbilder bleiben unangetastet.

## Wasserzeichenverwaltung

- eigene Wasserzeichendateien;
- Wasserzeichenprofile;
- globale Regeln für öffentliche/private Alben;
- Album-Ausnahmen und Vererbung;
- Position, Transparenz und Skalierung;
- eigener Runtime-Filter für Piwigo-Derivate;
- bisherige Piwigo-Wasserzeichenkonfiguration wird beim Aktivieren gesichert.

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

Private Alben können über eigene Freigabelinks geteilt werden:

- optionales Passwort;
- optionales Ablaufdatum;
- eigener technischer Piwigo-Benutzer pro Freigabe;
- minimaler Albumzugriff;
- Hash-Speicherung von Passwörtern und Tokens;
- Widerruf und automatische Bereinigung beim Löschen eines Albums.

## Erweiterte Bildnavigation

Die Piwigo-Bilddetailseite erhält responsive Navigationszonen für vorheriges Bild, nächstes Bild, Rückkehr zur Übersicht und PhotoSwipe/Vollbild.

## Selbstaktualisierung

Der integrierte Updater:

- liest den Zielstand aus GitHub;
- bindet ein Update an einen konkreten Commit statt an einen während des Updates beweglichen Branch-Stand;
- prüft Version und SHA-256 des Zielstands;
- erstellt vor dem Austausch ein Backup;
- nutzt Post/Redirect/Get, damit ein Browser-Reload keine schreibende Aktion erneut ausführt;
- verlangt Webmaster-Rechte, `ZipArchive` und ausreichende Schreibrechte.

## Architektur

Wichtige Dateien und Verzeichnisse:

- `main.inc.php` – Plugin-Einstieg und Runtime-Hooks
- `admin.php` – zentraler Admin-Controller mit Post/Redirect/Get
- `include/tool_registry.inc.php` – Registry administrativer Aktionen
- `include/nc_connector.inc.php` – Datenmodell und gemeinsame Connector-Funktionen
- `include/nc_connector_wizard.inc.php` – Endnutzer-Assistent und Connection-Bearbeitung
- `include/nc_connector_manage.inc.php` – Credential-Format, Storage-Mappings, Verifikation und Löschen
- `include/nc_connector_connection_scope.inc.php` – verbindungseigene Authentifizierung
- `include/nc_connector_create_api.inc.php` – API-first-Verbindungserstellung und Secret v3
- `include/nc_connector_webdav.inc.php` – paralleler WebDAV-Connection-Typ
- `include/nc_connector_piwigo_api.inc.php` – API-Zugang und Fallback-Verwaltung
- `include/nc_connector_system.inc.php` – Timer- und Laufzeitstatus
- `include/nc_productive_ws.inc.php` – produktiver Piwigo-Dateisync
- `include/nc_orphan_ws.inc.php` – Orphan-Synchronisierung
- `runtime/lib/activity_gate.py` – Activity Gate
- `runtime/lib/build_manifest.py` – bestehende Auflösung der Nextcloud-Quellen auf Storage-Mounts
- `runtime/lib/build_selected_manifest.py` – ausgewählte Nextcloud-Datei-IDs auf bestehende Storage-Pfade auflösen
- `runtime/lib/build_webdav_placeholder_source.py` – experimentelle WebDAV-Platzhalterquelle
- `runtime/lib/shadow_tree.py` – atomarer Shadow Tree mit Rollback
- `runtime/lib/piwigo-sync.php` – API-first-Piwigo-Sync mit Fallback
- `runtime/run-all.sh` – Multi-Connection-Runner und Bereinigung verwaister Runtime-Dateien
- `runtime/sync.sh` – Ablauf einer bestehenden lokalen Verbindung
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
- Secret v3 speichert Nextcloud-Zugangsdaten verbindungseigen und verschlüsselt;
- Wizard-Geheimnisse werden nicht in Browser-Web-Storage persistiert;
- bestehende lokale Storage-Mounts werden explizit zugeordnet und validiert;
- SQL-View-Namen werden vor Verwendung validiert;
- produktive Piwigo-API ist versionsgebunden;
- Originalbilder werden vom Connector nicht gelöscht;
- der neue WebDAV-Weg soll Originalbilder nicht dauerhaft lokal speichern;
- Update-Pakete werden an Commit und Hash gebunden;
- bestehende Verbindungen bleiben während der WebDAV-Entwicklung unangetastet.

## Entwicklungsstand

`0.9.5.x` ist der aktuelle Entwicklungsblock. Der bestehende lokale NC Connector bleibt produktiv und wird nicht durch den experimentellen WebDAV-Weg ersetzt.

Mit 0.9.5.6 ist die parallele Verbindungsschicht angelegt: Secret v3 kann Nextcloud-Zugangsdaten speichern und `webdav-placeholder` existiert als eigener deaktivierter Connection-Typ. Der nächste Schritt ist ein strikt getrennter Reconcile-/Runtime-Zweig für diesen Modus und danach der erste Platzhalter-PoC durch den bestehenden Shadow Tree und Piwigo-Sync.

Den detaillierten aktuellen Plan enthält `CURRENT_STATUS.md`.
