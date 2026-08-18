# Piwigo Bratonien Tools

Modulares Piwigo-Plugin für Administration, Bildverarbeitung, geschützte Freigaben, Fotoauswahl und die Anbindung von Nextcloud an Piwigo.

Aktuelle Plugin-Version: **0.9.6.1**

## Grundprinzip

Bratonien Tools erweitert Piwigo um Funktionen, die in der Bratonien-Installation benötigt werden, hält die einzelnen Module aber möglichst unabhängig voneinander.

Für die Administration gilt: Der normale Nutzer sieht Aufgaben, Entscheidungen und Ergebnisse. Technische Interna werden automatisch ermittelt oder bleiben hinter ausdrücklich technischen Einstellungen verborgen.

## NC Connector

Der NC Connector synchronisiert ausdrücklich ausgewählte Nextcloud-Inhalte mit Piwigo.

### Datenmodell

- Nextcloud bleibt die Quelle der Originaldateien.
- Originalbilder werden nicht dauerhaft in eine zweite Bibliothek unter Piwigo kopiert.
- Ein Shadow Tree erzeugt die für Piwigo benötigte Ordnerstruktur.
- Ordner werden als physische Piwigo-Alben synchronisiert.
- Root-Dateien können als Piwigo-Orphans registriert werden.
- Neu importierte physische Connector-Alben werden privat angelegt.
- Entfernte Quellen werden aus Shadow Tree und Piwigo entfernt; die Nextcloud-Originale bleiben erhalten.

### Bestehende lokale Quellenmodi

Der bestehende Connector unterstützt weiterhin:

- `legacy-view`
- `user-shares`
- `selected-fileids`

Diese Modi bleiben erhalten. Es gibt keine automatische Migration auf WebDAV.

### WebDAV-Modus

Der neue Endnutzerpfad verwendet `source_mode=webdav-placeholder` und `adapter=remote`.

Benötigt werden nur:

- Nextcloud-Adresse;
- Nextcloud-Benutzer bzw. App-Passwort;
- normaler WebDAV-Zugriff auf die Inhalte dieses Benutzers;
- eine normale Piwigo-Installation mit ihrer vorhandenen PHP-/Bildverarbeitungsumgebung.

Nicht vorausgesetzt werden:

- PostgreSQL-Zugriff auf Nextcloud;
- `occ`-Adminzugriff;
- Storage-IDs oder Backend-Pfade;
- zusätzliche Host-Mounts;
- FUSE;
- davfs;
- rclone.

### WebDAV-Ablauf

1. Der Assistent prüft Nextcloud und liest die sichtbaren Verzeichnisse per WebDAV.
2. Ausgewählte Verzeichnisse werden rekursiv per PROPFIND eingelesen.
3. Ordner, Dateiname, Nextcloud-Datei-ID, MIME-Typ, Größe, ETag und WebDAV-Pfad werden erfasst.
4. `runtime/lib/build_webdav_placeholder_source.py` erzeugt eine lokale Platzhalterquelle ohne dauerhafte Originalkopie.
5. `runtime/lib/shadow_tree.py` baut daraus den verbindungseigenen Shadow Tree.
6. Der WebDAV-Galeriebaum liegt unter `_data/bratonien-tools/nc-webdav-gallery/connection-ID`.
7. Jede WebDAV-Verbindung wird als eigene physische Piwigo-Site registriert. Die ausgewählte Nextcloud-Wurzel erscheint dadurch direkt als Album; technische `bratonien-webdav-ID`-Wrapper bleiben unsichtbar.
8. `runtime/lib/precache-webdav-previews.php` erzeugt vorbereitete Arbeitsbilder als JPEG beziehungsweise PNG mit maximal 4096 px Kantenlänge.
9. `runtime/lib/build-webdav-derivatives.php` korrigiert die Piwigo-Bildabmessungen und erzeugt die konfigurierten Standard- und Custom-Derivate im Hintergrundlauf.
10. Der Frontend-Aufruf erzeugt keine Derivate. Fehlt eines, wird das vorbereitete Arbeitsbild als sicherer Fallback ausgeliefert.
11. Wenn das Original benötigt wird, streamt `webdav-image.php` es serverseitig direkt aus Nextcloud. Zugangsdaten werden dem Browser nicht offengelegt.

### Piwigo-Synchronisierung

Der WebDAV-Pfad nutzt die vorhandene Piwigo-Synchronisationslogik mit einer verbindungseigenen Site.

Nach dem Dateiabgleich laufen die vorhandenen Nacharbeiten, darunter:

- Metadaten-Synchronisierung;
- Bild- und Kategorieintegrität;
- Uppercats/Kategoriestruktur;
- globale Ränge;
- Pfadpflege;
- Rating-Score;
- Benutzer-Cache-Invalidierung;
- Orphan-Abgleich.

Die direkte produktive Synchronisierung ist aktuell ausdrücklich auf **Piwigo 16.4.0** abgestimmt.

### Fallback-Zugang

Piwigo wird API-first angesprochen. Ist für eine Verbindung keine API konfiguriert, kann Benutzername/Passwort als Fallback verwendet werden.

Der Assistent prüft Fallback-Zugangsdaten vor dem Speichern. Eine fehlgeschlagene Prüfung schließt oder leert den Assistenten nicht.

### Statusanzeige

Die Administration zeigt den Laufzeitstatus regelmäßig im Hintergrund an. Dafür wird die Admin-Seite nicht neu geladen; offene Dialoge, Assistenten und aufgeklappte Bereiche bleiben erhalten.

Fehler werden nach Prozessschritt getrennt dargestellt, unter anderem für WebDAV-Einlesen, Piwigo-Synchronisierung, Preview-Erzeugung und Derivat-Erzeugung.

### Löschen einer Verbindung

Seit **0.9.6.1** besitzt der WebDAV-Pfad einen vollständigen Lösch-Lebenszyklus:

- Beim Löschen einer WebDAV-Verbindung werden ihre Piwigo-Site, Alben und Bilddatensätze entfernt.
- Zugehörige Piwigo-Derivate werden entfernt.
- Nextcloud-Originale bleiben unverändert.
- Runtime-, Shadowtree-, Source-, Preview- und Statusdaten werden bereinigt.
- `runtime/cleanup-webdav-piwigo.php` erkennt zusätzlich bereits verwaiste WebDAV-Piwigo-Sites, für die keine Connector-Verbindung mehr existiert, und bereinigt sie im gemeinsamen Runner nachträglich.

### Runtime

Aktive Verbindungen werden über den gemeinsamen Runner verarbeitet:

- `bratonien-nc-connector.timer`
- `bratonien-nc-connector.service`
- `runtime/run-all.sh`

Verbindungsspezifische Konfigurationen liegen unter `/etc/bratonien-tools/nc-connector/`, State-Daten unter `/var/lib/bratonien-tools/nc-connector/connection-ID`.

Die Reihenfolge des gemeinsamen Laufs ist:

1. lokale Verbindungen reconciliieren;
2. WebDAV-Verbindungen reconciliieren;
3. verwaiste WebDAV-Piwigo-Inhalte bereinigen;
4. allgemeine verwaiste Runtime-Dateien bereinigen;
5. WebDAV-Verbindungen synchronisieren;
6. lokale Verbindungen synchronisieren.

## Bildcache

Bratonien Tools kann:

- Piwigo-Bildderivate gezielt leeren;
- vorhandene Bildgrößen neu erzeugen;
- den Cache-Aufbau als Worker-Prozess starten und abbrechen;
- die Worker-Zahl automatisch oder manuell konfigurieren.

Originalbilder bleiben unangetastet.

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
- bindet ein Update an einen konkreten Commit;
- prüft Version und SHA-256 des Zielstands;
- erstellt vor dem Austausch ein Backup;
- nutzt Post/Redirect/Get;
- verlangt Webmaster-Rechte, `ZipArchive` und ausreichende Schreibrechte.

## Wichtige Dateien und Verzeichnisse

- `main.inc.php` – Plugin-Einstieg und Runtime-Hooks
- `admin.php` – zentraler Admin-Controller
- `include/nc_connector.inc.php` – Datenmodell und gemeinsame Connector-Funktionen
- `include/nc_connector_wizard.inc.php` – Verbindungsassistent
- `include/nc_connector_wizard_webdav_flow.inc.php` – WebDAV-first-Wizardablauf
- `include/nc_connector_delete_safe.inc.php` – sicheres Löschen einschließlich WebDAV-Piwigo-Inhalten
- `include/nc_productive_ws.inc.php` – produktiver Piwigo-Dateisync
- `include/nc_orphan_ws.inc.php` – Orphan-Synchronisierung
- `include/webdav_image_runtime.inc.php` – WebDAV-Bildzuordnung, Preview-/Derivathilfen und URL-Filter
- `webdav-image.php` – berechtigungsgeprüfter WebDAV-Bildstream
- `runtime/reconcile-webdav.php` – WebDAV-Runtime-Reconcile
- `runtime/cleanup-webdav-piwigo.php` – Bereinigung gelöschter/verwaister WebDAV-Verbindungen in Piwigo
- `runtime/sync-webdav.sh` – Ablauf einer WebDAV-Verbindung
- `runtime/run-all.sh` – gemeinsamer Multi-Connection-Runner
- `runtime/lib/build_webdav_placeholder_source.py` – rekursiver WebDAV-Scan und Platzhalterquelle
- `runtime/lib/precache-webdav-previews.php` – vorbereitete JPEG-/PNG-Arbeitsbilder
- `runtime/lib/build-webdav-derivatives.php` – CLI-Derivat-Builder
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
- SQL-View-Namen werden vor Verwendung validiert;
- produktive Piwigo-API ist versionsgebunden;
- Originalbilder werden vom Connector nicht gelöscht;
- WebDAV-Originalbilder werden nicht dauerhaft lokal gespeichert;
- Update-Pakete werden an Commit und Hash gebunden.

## Entwicklungsstand

`0.9.6.x` ist die aktuelle Versionslinie.

Mit **0.9.6.1** ist der WebDAV-Pfad einschließlich Piwigo-Registrierung, vorbereiteter Bildquelle, CLI-Derivatstrecke und Lösch-/Cleanup-Lebenszyklus im Repository umgesetzt. Der bestehende lokale Connector bleibt parallel erhalten.

Der jeweils detaillierte Prüf- und Entwicklungsstand steht in [`CURRENT_STATUS.md`](CURRENT_STATUS.md).
