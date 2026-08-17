# Piwigo Bratonien Tools

Modular aufgebautes Piwigo-Plugin mit erweiterten Werkzeugen fuer Administration, Bildverarbeitung, Nextcloud-Anbindung und Frontend-Funktionen.

Das Projekt ist aus der Bratonien-Piwigo-Installation entstanden, wird aber bewusst so entwickelt, dass die einzelnen Funktionen moeglichst neutral und auch ausserhalb dieser Installation nutzbar bleiben.

Aktuelle Plugin-Version: **0.9.3.16**

## Funktionsumfang

### NC Connector

Der NC Connector verwaltet Nextcloud-Quellen fuer Piwigo und fuehrt den regelmaessigen Abgleich mit einer Plugin-eigenen Sync-Runtime aus.

Aktuell vorhanden:

- mehrere Connector-Verbindungen im eigenen Datenmodell
- lokale Verbindungen direkt in Bratonien Tools anlegen
- verschluesselte Speicherung von PostgreSQL- und Piwigo-Sync-Zugangsdaten
- lokaler Nextcloud-Adapter ueber PostgreSQL-Reader und explizite Storage-Mounts
- Verifikation von PostgreSQL-Verbindung, Source-View, Activity-View und Storage-Mounts
- native Aktivierung einer verifizierten Verbindung ohne vorherige Legacy-Installation
- eigener State pro Verbindung unter `/var/lib/bratonien-tools/nc-connector/connection-ID`
- gemeinsame Runtime-Konfiguration unter `/etc/bratonien-tools/nc-connector/`
- Multi-Connection-Runner unter `runtime/run-all.sh`
- gemeinsame Zeitsteuerung ueber `bratonien-nc-connector.timer`
- Verbindungen koennen kontrolliert deaktiviert und danach geloescht werden
- Anzeige von Timer-Status, letztem Lauf, letztem Ergebnis und naechstem geplanten Lauf
- Plugin-eigene Sync-Runtime unter `runtime/`
- Activity-Gate mit Quiet-Time, Max-Wartezeit, Share-Fingerprint und periodischem Full-Sync
- Shadow Tree fuer Piwigo ohne Kopie der Originaldateien
- Unterstuetzung von Nextcloud-Freigaben fuer komplette Ordner und einzelne Dateien
- Ordnerfreigaben werden als physische Piwigo-Alben synchronisiert
- neu importierte Connector-Alben werden direkt als privat angelegt
- einzelne Dateifreigaben werden direkt im Galerie-Root verlinkt und als echte Piwigo-Orphans registriert
- entfernte Einzeldateifreigaben werden beim naechsten Lauf wieder aus Piwigo entfernt, ohne das Original zu loeschen
- Originalbilder bleiben an ihrer Nextcloud-/Storage-Quelle
- lokale Piwigo-Derivate und Caches bleiben davon getrennt und koennen jederzeit neu erzeugt werden

Die Runtime baut aus den freigegebenen Nextcloud-Quellen einen Piwigo-kompatiblen Shadow Tree. Dateien werden dabei nicht in eine zweite permanente Originalbibliothek kopiert, sondern ueber Symlinks auf die vorhandenen Storage-Mounts referenziert.

#### Freigabemodell

Der Connector unterscheidet zwei Quelltypen:

- `folder` - komplette Ordnerfreigabe; der Verzeichnisbaum wird gespiegelt und ueber Piwigos normale Dateisynchronisierung als Albumstruktur eingelesen
- `file` - einzelne Dateifreigabe; die Datei wird direkt als Symlink im Galerie-Root angelegt und separat als Piwigo-Orphan ohne Albumzuordnung registriert

Fuer Einzeldateien wird kein kuenstlicher Unterordner erzeugt. Entfernt Nextcloud eine solche Freigabe, entfernt der Connector den Root-Symlink und anschliessend nur den zugehoerigen Piwigo-Datenbankeintrag. Das Original bleibt unangetastet.

#### Neuinstallation

Eine frische Piwigo-Installation benoetigt keinen vorher vorhandenen `piwigo-sync` mehr.

Der vorgesehene Ablauf ist:

1. lokale Nextcloud-Verbindung in Bratonien Tools anlegen
2. PostgreSQL, Views und Storage-Mounts pruefen
3. die angezeigte Root-Aktivierung ausfuehren
4. der Installer legt Runtime-Konfiguration, Secrets, State-Verzeichnis sowie systemd-Service und -Timer an
5. vor der Aktivierung wird ein echter Lauf mit der Plugin-Runtime getestet

Der gemeinsame Service verarbeitet alle aktivierten `connection-*.conf`-Dateien nacheinander. Dadurch koennen mehrere Verbindungen mit einer gemeinsamen Zeitsteuerung betrieben werden.

#### Migration bestehender Installationen

Fuer bestehende Installationen, die zuvor den separaten `piwigo-sync` aus `Proxmox_Scripts` verwendet haben, existiert weiterhin ein kontrollierter Migrationsweg:

- bestehende Verbindung einmalig importieren
- Connector-Kopie unabhaengig verifizieren
- kontrollierten Cutover vorbereiten
- Connector-Timer aktivieren und Legacy-Timer deaktivieren
- Runtime vom bisherigen `/opt/piwigo-sync` auf die Plugin-eigene Runtime umstellen
- Legacy-Bestand entfernen
- den verbliebenen Laufzeit-State anschliessend mit `nc-connector-normalize.php` in die native Struktur unter `/var/lib/bratonien-tools/nc-connector/` ueberfuehren

Nach abgeschlossener Migration werden `/opt/piwigo-sync`, `/etc/piwigo-sync`, `piwigo-sync.service` und `piwigo-sync.timer` nicht mehr benoetigt.

### Bildcache

- Piwigo-Bildcache gezielt leeren
- vorhandene Bildderivate neu erzeugen
- Cache-Aufbau als Worker-Prozess starten und abbrechen
- Worker-Einstellungen verwalten
- Originalbilder bleiben unangetastet

### Wasserzeichenverwaltung

Erweiterte Wasserzeichenverwaltung als Alternative zur einfachen Piwigo-Standardkonfiguration.

Unter anderem vorhanden:

- eigene Wasserzeichendateien
- Wasserzeichenprofile
- Standardprofil
- albumbezogene Regeln
- getrennte Einstellungen fuer Positionierung und Verarbeitung
- eigener Runtime-Filter fuer Piwigo-Bildderivate
- Aktivierung und Deaktivierung der erweiterten Wasserzeichen-Engine

Beim Aktivieren wird die bisherige Piwigo-Wasserzeichenkonfiguration gesichert und nicht dauerhaft ueberschrieben. Beim Deaktivieren kann sie wiederhergestellt werden.

### Bilddateien & Pfade

Verwaltung eigener Bilddateien fuer die Piwigo-Installation.

- Upload nach `local/bratonien/assets/`
- Vorschau vorhandener Dateien
- Anzeige von Dateiname, Pfad, Abmessungen und Dateigroesse
- Dateien loeschen
- konfigurierbare PHP-Uploadgrenzen ueber `.user.ini`
- Validierung von `upload_max_filesize` und `post_max_size`

### Oeffentliche Bildauswahl fuer Batch Downloader

Erweitert Albumseiten um die Moeglichkeit, einzelne Bilder fuer einen Download auszuwaehlen.

- Auswahlmodus direkt in der Albumansicht
- einzelne Bilder markieren
- alle Bilder auswaehlen oder Auswahl aufheben
- nur die ausgewaehlten Bilder an Batch Downloader uebergeben
- vorhandene Piwigo- und Batch-Downloader-Berechtigungen bleiben wirksam
- getrennte Freigabe fuer Gaeste, registrierte Benutzer und Gruppen

**Abhaengigkeit:** Das Piwigo-Plugin Batch Downloader muss installiert und aktiv sein.

Die ZIP-Erstellung wird nicht dupliziert. Bratonien Tools uebergibt lediglich die berechtigten Bild-IDs an Batch Downloader.

### Fortlaufende Bildtitel in der Stapelverarbeitung

Erweitert Piwigos globale Stapelverarbeitung um die Aktion **Fortlaufende Bildtitel**.

- arbeitet ausschliesslich mit der aktuellen Piwigo-Auswahl
- frei waehlbares Titelpraefix
- frei waehlbare Startnummer
- einstellbare Stellenzahl mit fuehrenden Nullen
- direkte Vorschau des resultierenden Titelformats
- wahlweise alle ausgewaehlten Titel ueberschreiben oder nur leere beziehungsweise typische Kamera-/Importtitel ersetzen
- Reihenfolge nach Dateiname, Aufnahmedatum oder aktueller Albumreihenfolge
- vorhandene individuelle Titel koennen gezielt geschuetzt werden
- physische Dateinamen werden nicht veraendert

Beispiel: `Samt 2026 - 001`, `Samt 2026 - 002`, `Samt 2026 - 003`.

Die Albumreihenfolge steht nur zur Verfuegung, wenn die Stapelverarbeitung auf genau ein Album ohne rekursive Unteralben gefiltert ist.

### Albumzugriff verwalten

In der Administrationsoberflaeche koennen Alben direkt zwischen **oeffentlich** und **privat** umgeschaltet werden.

- Albumliste mit Suchfeld
- paginierte Darstellung
- Umschalten des Zugriffs direkt aus Bratonien Tools
- Verwendung nativer Piwigo-Icons fuer den Zugriffsstatus
- beim Sperren eines Albums behaelt der aktuell handelnde Benutzer automatisch direkten Zugriff

Der Schutz vor versehentlichem Selbstaussperren greift auch dann, wenn ein Album ueber Piwigos eigene Album-Zugriffsverwaltung von oeffentlich auf privat gesetzt wird.

### Geschuetzte Albumfreigaben

Private Alben koennen direkt mit Bratonien Tools geteilt werden. ShareAlbum oder ein anderes Freigabe-Plugin ist dafuer nicht erforderlich.

- eigener individueller Freigabelink pro Freigabe
- Passwort **optional**; ohne Passwort reicht der nicht erratbare Link
- Passwoerter werden nur als Hash gespeichert
- integrierter Generator fuer sichere Passwoerter
- erzeugte Passwoerter koennen angezeigt und kopiert werden
- optionales Ablaufdatum
- optionaler Freigabe-Tag, um mehrere Freigaben desselben Albums auseinanderzuhalten
- eigener technischer Piwigo-Benutzer pro Freigabe
- der technische Benutzer erhaelt nur Zugriff auf das freigegebene private Album
- aktive Freigabelinks werden in der Administration angezeigt und koennen direkt kopiert werden
- bei aelteren, nicht rekonstruierbaren Links kann ein neuer Link erzeugt werden; der bisherige Link wird dabei ungueltig
- Freigaben koennen einzeln widerrufen werden
- beim Widerruf wird der technische Benutzer samt Sitzungen und Albumrecht entfernt
- wird das Album geloescht, werden zugehoerige Freigaben automatisch bereinigt

Freigabe-Tokens werden nicht im Klartext gespeichert. Fuer aktuelle Freigaben kann der Link deterministisch aus dem technischen Freigabebenutzer, dem Album und einem lokal gespeicherten Secret rekonstruiert werden; in der Datenbank liegt nur der Hash des Tokens.

### Erweiterte Bildnavigation

Auf der Bilddetailseite werden die vorhandenen Piwigo-Image-Maps weiterverwendet und responsiv neu aufgeteilt.

Die Bildflaeche besteht aus vier Navigationszonen:

- links: vorheriges Bild
- rechts: naechstes Bild
- oben mittig: zurueck zur Vorschau beziehungsweise Albumansicht
- Mitte: PhotoSwipe / Vollbildansicht

Die Zonen werden anhand der aktuell dargestellten Bildgroesse berechnet und bei Groessenaenderungen neu gesetzt.

Fuer die sichtbaren Hover-Elemente werden neutrale CSS-Klassen bereitgestellt. Das Plugin enthaelt bewusst kein Bratonien-spezifisches Farbschema. Individuelles Branding kann ueber Theme- oder Custom-CSS erfolgen.

### Selbstaktualisierung

Bratonien Tools kann den aktuellen Stand des GitHub-Repositories pruefen und sich aus der Administration heraus aktualisieren.

- Versionspruefung gegen `main.inc.php` im GitHub-Repository
- Update-Pruefung wird kurzzeitig zwischengespeichert
- Updates duerfen nur vom Piwigo-Webmaster ausgefuehrt werden
- Download des aktuellen `main`-Branches als ZIP
- Pruefung auf `ZipArchive` und Schreibrechte des Plugin-Verzeichnisses
- detailliertere Downloadfehler
- Status wird nach Update-Pruefungen und Aktionen neu eingelesen

## Architektur

Das Plugin ist modular aufgebaut. Administrative Werkzeuge werden getrennt implementiert und ueber eine zentrale Registry eingebunden. Frontend-, Runtime- und Piwigo-Integrationen liegen ebenfalls in eigenen Modulen.

Wichtige Bestandteile:

- `main.inc.php` - Plugin-Einstieg, Runtime-Module und Admin-Menue
- `admin.php` - zentraler Admin-Controller
- `include/tool_registry.inc.php` - Registry der administrativen Aktionen
- `include/nc_connector.inc.php` - Connector-Datenmodell, Legacy-Import und gemeinsame Low-Level-Funktionen
- `include/nc_connector_manage.inc.php` - native Connection-Verwaltung und Verifikation
- `include/nc_connector_takeover.inc.php` - kontrollierte Legacy-Uebergabe
- `include/nc_connector_system.inc.php` - Timer- und Laufzeitstatus ueber alle aktiven Verbindungen
- `include/nc_connector_ws.inc.php` - read-only Paritaets-/Synchronisationspruefung ueber den Piwigo-Webservice
- `include/nc_orphan_ws.inc.php` - produktive Synchronisation einzelner Root-Dateien als Piwigo-Orphans
- `runtime/sync.sh` - Plugin-eigene Sync-Runtime einer Verbindung
- `runtime/run-all.sh` - gemeinsamer Runner fuer alle installierten Verbindungen
- `runtime/lib/activity_gate.py` - Activity-Gate, Share-Fingerprint und zeitgesteuerte Reconciliation
- `runtime/lib/build_manifest.py` - Aufloesung von Ordner- und Dateifreigaben auf konfigurierte Storage-Mounts
- `runtime/lib/shadow_tree.py` - Aufbau des Piwigo-kompatiblen Shadow Trees ohne Kopieren der Originale
- `runtime/lib/piwigo-db-check.php` - Konsistenzpruefung vorhandener Piwigo-Alben
- `runtime/lib/piwigo-db-sync.pl` - Ausloesen der Piwigo-Dateisynchronisierung und anschliessender Orphan-Abgleich
- `nc-connector-install.php` - native Aktivierung einer verifizierten Verbindung
- `nc-connector-disable.php` - kontrollierte Deaktivierung einer aktiven Verbindung
- `nc-connector-normalize.php` - Ueberfuehrung einer migrierten aktiven Verbindung in den nativen State- und Multi-Connection-Aufbau
- `nc-connector-migrate.php` - einmaliger Migrationshelfer fuer bestehende Legacy-Installationen
- `nc-connector-cutover-v2.php` - kontrollierter Legacy-Cutover
- `nc-connector-runtime-switch.php` - Umstellung einer aktiven Legacy-Verbindung auf die Plugin-eigene Runtime
- `nc-connector-legacy-cleanup.php` - kontrollierte Entfernung alter Sync-Scripts und systemd-Units
- `include/public_selection.inc.php` - oeffentliche Fotoauswahl und Batch-Downloader-Anbindung
- `include/picture_navigation.inc.php` - Einbindung der erweiterten Bildnavigation
- `include/batch_titles.inc.php` - fortlaufende Titelvergabe in Piwigos Stapelverarbeitung
- `include/album_lock.inc.php` - Laden und Umschalten des Album-Zugriffsstatus
- `include/album_shares.inc.php` - Albumfreigaben, Freigabetokens und Schutz vor Selbstaussperren bei privaten Alben
- `include/cache_worker_settings.inc.php` - Einstellungen fuer den Cache-Worker
- `include/dependencies.inc.php` - Abhaengigkeitspruefungen
- `include/watermark_*.inc.php` - Wasserzeichen-Engine und Runtime
- `include/self_update.inc.php` - Versionspruefung und Selbstaktualisierung
- `tools/album_rules.inc.php` - albumbezogene Regeln
- `tools/asset_manager.inc.php` - Verwaltung eigener Bilddateien
- `tools/image_cache.inc.php` - Cache-Verwaltung
- `tools/watermark*.inc.php` - administrative Wasserzeichenmodule
- `main-cache-build.php` - Cache-Aufbau im Worker-Prozess
- `main-cache-status.php` - Statusschnittstelle fuer den Cache-Worker
- `js/` - Frontend- und Admin-JavaScript
- `css/` - strukturelles Plugin-CSS
- `template/` - Admin- und Frontend-Templates
- `maintain.class.php` - Piwigo-Lifecycle

## Neues administratives Tool hinzufuegen

1. Neue Implementierung unter `tools/` anlegen.
2. Datei in `include/tool_registry.inc.php` laden.
3. Handler in `bratonien_tools_get_tools()` registrieren.
4. Benoetigte Darstellung in der gemeinsamen Administrationsoberflaeche ergaenzen.

Funktionen sollten moeglichst eigenstaendig bleiben und keine Bratonien-spezifische Gestaltung voraussetzen.

## Sicherheit

Administrative Aktionen pruefen Piwigo-Berechtigungen und verwenden fuer schreibende Aktionen Piwigos CSRF-Schutz.

Weitere Schutzmechanismen sind funktionsabhaengig, unter anderem:

- verschluesselte Speicherung der Connector-Zugangsdaten mit einem lokalen Connector-Secret
- PostgreSQL-Reader mit minimalen Leserechten fuer lokale Nextcloud-Verbindungen
- explizite Storage-Zuordnungen statt automatischer Freigabe beliebiger Dateipfade
- Verifikation von Datenbank, Views und Mounts vor einer Aktivierung
- Runtime-Test vor der nativen Aktivierung
- Root-only-Hilfsprogramme fuer systemd-, Secret- und State-Aenderungen
- getrennte State-Verzeichnisse pro Connector-Verbindung
- Originalbilder werden vom NC Connector nicht in eine zweite permanente Bibliothek kopiert
- Validierung von Cache- und Dateipfaden
- Filterung ausgewaehlter Bild-IDs gegen die aktuell berechtigte Bildmenge
- Nutzung der von Piwigos Stapelverarbeitung validierten Auswahl fuer die fortlaufende Titelvergabe
- gehashte Passwoerter und gehashte Freigabetokens fuer Albumfreigaben
- nicht erratbare Freigabelinks auf Basis eines lokal erzeugten Secrets
- eigene technische Benutzer mit minimalem Albumzugriff fuer Freigaben
- automatische Bereinigung widerrufener und geloeschter Albumfreigaben
- automatischer Erhalt des eigenen Zugriffs beim Umschalten eines Albums auf privat
- Connector-importierte physische Alben werden standardmaessig privat angelegt
- Beibehaltung bestehender Piwigo- und Plugin-Berechtigungen
- kontrollierte Uploadziele und Uploadgrenzen
- kein Zugriff auf Originalbilder beim Leeren des Bildcaches
- Webmaster-Pruefung, Schreibbarkeitspruefung und kontrolliertes temporaeres Arbeitsverzeichnis bei Selbstupdates

## Styling und Anpassung

Bratonien Tools soll Funktion und Gestaltung voneinander trennen. Plugin-eigene Styles dienen daher nur der technischen Darstellung und Positionierung.

Farben, Schatten, Hover-Effekte und individuelles Branding sollten ueber das aktive Piwigo-Theme oder Custom CSS umgesetzt werden.

## Entwicklungsstand

Das Plugin befindet sich weiterhin in aktiver Entwicklung. Mit Version **0.9.3.16** ist der lokale NC-Connector fuer den aktuellen Bratonien-Einsatz End-to-End funktionsfaehig: Ordner- und Einzeldateifreigaben werden erkannt, der Shadow Tree wird automatisch gepflegt, Piwigo wird synchronisiert, Einzeldateien werden als Orphans verwaltet und der regelmaessige Lauf erfolgt ueber den gemeinsamen systemd-Timer.

Als naechster groesserer Connector-Ausbauschritt bleibt der Remote-Nextcloud-Adapter offen.