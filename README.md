# Piwigo Bratonien Tools

Modular aufgebautes Piwigo-Plugin mit erweiterten Werkzeugen fuer Administration, Bildverarbeitung, Nextcloud-Anbindung und Frontend-Funktionen.

Das Projekt ist aus der Bratonien-Piwigo-Installation entstanden, wird aber bewusst so entwickelt, dass die einzelnen Funktionen moeglichst neutral und auch ausserhalb dieser Installation nutzbar bleiben.

Aktuelle Plugin-Version: **0.14.2.11**

## Funktionsumfang

### NC Connector

Der NC Connector verwaltet Nextcloud-Quellen fuer Piwigo und fuehrt den regelmaessigen Abgleich mit einer Plugin-eigenen Sync-Runtime aus.

Aktuell vorhanden:

- eigene Connection-Verwaltung innerhalb von Bratonien Tools
- Grundlage fuer mehrere Nextcloud-Verbindungen
- lokaler Nextcloud-Adapter ueber PostgreSQL-Reader und explizite Storage-Mounts
- Verifikation von PostgreSQL-Verbindung, Source-View, Activity-View und Storage-Mounts
- Statusmodell fuer importierte, verifizierte, vorbereitete und aktive Verbindungen
- regelmaessige Ausfuehrung ueber `bratonien-nc-connector.timer`
- Anzeige von Timer-Status, letztem Lauf, letztem Ergebnis und naechstem geplanten Lauf
- Plugin-eigene Sync-Runtime unter `runtime/`
- Activity-Gate mit Quiet-Time, Max-Wartezeit und periodischem Full-Sync
- Shadow Tree fuer Piwigo ohne Kopie der Originaldateien
- Originalbilder bleiben an ihrer Nextcloud-/Storage-Quelle
- lokale Piwigo-Derivate und Caches bleiben davon getrennt und koennen jederzeit neu erzeugt werden

Die Runtime baut aus den freigegebenen Nextcloud-Quellen einen Piwigo-kompatiblen Shadow Tree. Dateien werden dabei nicht in eine zweite permanente Originalbibliothek kopiert, sondern ueber Symlinks auf die vorhandenen Storage-Mounts referenziert.

#### Migration bestehender Installationen

Fuer bestehende Installationen, die zuvor den separaten `piwigo-sync` aus `Proxmox_Scripts` verwendet haben, existiert ein kontrollierter Migrationsweg:

- bestehende Verbindung einmalig importieren
- Connector-Kopie unabhaengig verifizieren
- kontrollierten Cutover vorbereiten
- Connector-Timer aktivieren und Legacy-Timer deaktivieren
- Runtime vom bisherigen `/opt/piwigo-sync` auf die Plugin-eigene Runtime umstellen
- Legacy-Bestand anschliessend entfernen

Der produktive Alt-Sync bleibt waehrend Import und Verifikation unangetastet. Erst beim expliziten Cutover wird die Zeitsteuerung uebernommen.

Nach abgeschlossener Migration koennen folgende Legacy-Bestandteile entfernt werden:

- `/opt/piwigo-sync`
- `/etc/piwigo-sync`
- `piwigo-sync.service`
- `piwigo-sync.timer`

`/var/lib/piwigo-sync` bleibt derzeit als Laufzeitverzeichnis fuer Name-Map, Activity-State, Manifest und Connector-Status bestehen.

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

Relevante Klassen:

```css
.bratonien-picture-zones
.bratonien-picture-zone
.bratonien-picture-zone-previous
.bratonien-picture-zone-next
.bratonien-picture-zone-thumbnails
.bratonien-picture-zone-photoswipe
.bratonien-picture-zone.is-active
```

Die Icons verwenden vorhandene Font-Awesome-Klassen des aktiven Frontends.

### Selbstaktualisierung

Bratonien Tools kann den aktuellen Stand des GitHub-Repositories pruefen und sich aus der Administration heraus aktualisieren.

- Versionspruefung gegen `main.inc.php` im GitHub-Repository
- Update-Pruefung wird kurzzeitig zwischengespeichert
- Updates duerfen nur vom Piwigo-Webmaster ausgefuehrt werden
- Download des aktuellen `main`-Branches als ZIP
- Pruefung auf `ZipArchive` und Schreibrechte des Plugin-Verzeichnisses
- detailliertere Downloadfehler, unter anderem cURL-, HTTP- und Content-Type-Informationen
- Status wird nach Update-Pruefungen und Aktionen neu eingelesen, damit die Administrationsseite nicht mit veralteten Versionsinformationen weiterarbeitet

## Architektur

Das Plugin ist modular aufgebaut. Administrative Werkzeuge werden getrennt implementiert und ueber eine zentrale Registry eingebunden. Frontend-, Runtime- und Piwigo-Integrationen liegen ebenfalls in eigenen Modulen.

Wichtige Bestandteile:

- `main.inc.php` - Plugin-Einstieg, Runtime-Module und Admin-Menue
- `admin.php` - zentraler Admin-Controller
- `include/tool_registry.inc.php` - Registry der administrativen Aktionen
- `include/nc_connector.inc.php` - Connection-Verwaltung, Import und Verifikation des NC Connectors
- `include/nc_connector_takeover.inc.php` - Vorbereitung und Ruecknahme der kontrollierten Connector-Uebergabe
- `include/nc_connector_system.inc.php` - Timer-, Laufzeit- und Legacy-Status fuer den NC Connector
- `runtime/sync.sh` - Plugin-eigene Sync-Runtime des NC Connectors
- `runtime/lib/activity_gate.py` - Activity-Gate und zeitgesteuerte Reconciliation
- `runtime/lib/build_manifest.py` - Aufloesung der Nextcloud-Quellen auf konfigurierte Storage-Mounts
- `runtime/lib/shadow_tree.py` - Aufbau des Piwigo-kompatiblen Shadow Trees ohne Kopieren der Originale
- `runtime/lib/piwigo-db-check.php` - Konsistenzpruefung vorhandener Piwigo-Alben
- `runtime/lib/piwigo-db-sync.pl` - Ausloesen der Piwigo-Dateisynchronisierung
- `nc-connector-migrate.php` - einmaliger Migrationshelfer fuer bestehende Legacy-Installationen
- `nc-connector-cutover-v2.php` - kontrollierter Legacy-Cutover
- `nc-connector-runtime-switch.php` - Umstellung einer aktiven Verbindung auf die Plugin-eigene Runtime
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

- verschluesselte Speicherung der Connector-Zugangsdaten
- PostgreSQL-Reader mit minimalen Leserechten fuer lokale Nextcloud-Verbindungen
- explizite Storage-Zuordnungen statt automatischer Freigabe beliebiger Dateipfade
- Verifikation von Datenbank, Views und Mounts vor einer Connector-Uebergabe
- kontrollierte Migration mit getrennten Zustaenden fuer Import, Verifikation und Aktivierung
- Originalbilder werden vom NC Connector nicht in eine zweite permanente Bibliothek kopiert
- Validierung von Cache- und Dateipfaden
- Filterung ausgewaehlter Bild-IDs gegen die aktuell berechtigte Bildmenge
- Nutzung der von Piwigos Stapelverarbeitung validierten Auswahl fuer die fortlaufende Titelvergabe
- gehashte Passwoerter und gehashte Freigabetokens fuer Albumfreigaben
- nicht erratbare Freigabelinks auf Basis eines lokal erzeugten Secrets
- eigene technische Benutzer mit minimalem Albumzugriff fuer Freigaben
- automatische Bereinigung widerrufener und geloeschter Albumfreigaben
- automatischer Erhalt des eigenen Zugriffs beim Umschalten eines Albums auf privat
- Beibehaltung bestehender Piwigo- und Plugin-Berechtigungen
- kontrollierte Uploadziele und Uploadgrenzen
- kein Zugriff auf Originalbilder beim Leeren des Bildcaches
- Webmaster-Pruefung, Schreibbarkeitspruefung und kontrolliertes temporaeres Arbeitsverzeichnis bei Selbstupdates

## Styling und Anpassung

Bratonien Tools soll Funktion und Gestaltung voneinander trennen. Plugin-eigene Styles dienen daher nur der technischen Darstellung und Positionierung.

Farben, Schatten, Hover-Effekte und individuelles Branding sollten ueber das aktive Piwigo-Theme oder Custom CSS umgesetzt werden.

## Entwicklungsstand

Das Plugin befindet sich weiterhin in aktiver Entwicklung. Der NC Connector ist aus der bisherigen externen Nextcloud-Piwigo-Synchronisierung in Bratonien Tools uebernommen worden und verwendet inzwischen seine eigene Runtime und Zeitsteuerung. Die Verwaltung mehrerer Verbindungen sowie weitere Adapter werden schrittweise ausgebaut.
