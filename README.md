# Piwigo Bratonien Tools

Modular aufgebautes Piwigo-Plugin mit erweiterten Werkzeugen fuer Administration, Bildverarbeitung und Frontend-Funktionen.

Das Projekt ist aus der Bratonien-Piwigo-Installation entstanden, wird aber bewusst so entwickelt, dass die einzelnen Funktionen moeglichst neutral und auch ausserhalb dieser Installation nutzbar bleiben.

Aktuelle Plugin-Version: **0.12.1**

## Funktionsumfang

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

Erweitert Albumseiten um die Moeglichkeit, einzelne Bilder fuer einen Download auszuwählen.

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

Der Status wird nach Update-Pruefungen und Aktionen direkt neu eingelesen, damit die Administrationsseite nicht mit veralteten Versionsinformationen weiterarbeitet.

## Architektur

Das Plugin ist modular aufgebaut. Administrative Werkzeuge werden getrennt implementiert und ueber eine zentrale Registry eingebunden. Frontend- und Piwigo-Integrationen liegen ebenfalls in eigenen Modulen.

Wichtige Bestandteile:

- `main.inc.php` - Plugin-Einstieg, Runtime-Module und Admin-Menue
- `admin.php` - zentraler Admin-Controller
- `include/tool_registry.inc.php` - Registry der administrativen Aktionen
- `include/public_selection.inc.php` - oeffentliche Fotoauswahl und Batch-Downloader-Anbindung
- `include/picture_navigation.inc.php` - Einbindung der erweiterten Bildnavigation
- `include/batch_titles.inc.php` - fortlaufende Titelvergabe in Piwigos Stapelverarbeitung
- `include/watermark_*.inc.php` - Wasserzeichen-Engine und Runtime
- `include/self_update.inc.php` - Selbstaktualisierung
- `tools/` - administrative Werkzeugmodule
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

- Validierung von Cache- und Dateipfaden
- Filterung ausgewaehlter Bild-IDs gegen die aktuell berechtigte Bildmenge
- Nutzung der von Piwigos Stapelverarbeitung validierten Auswahl fuer die fortlaufende Titelvergabe
- Beibehaltung bestehender Piwigo- und Plugin-Berechtigungen
- kontrollierte Uploadziele und Uploadgrenzen
- kein Zugriff auf Originalbilder beim Leeren des Bildcaches

## Styling und Anpassung

Bratonien Tools soll Funktion und Gestaltung voneinander trennen. Plugin-eigene Styles dienen daher nur der technischen Darstellung und Positionierung.

Farben, Schatten, Hover-Effekte und individuelles Branding sollten ueber das aktive Piwigo-Theme oder Custom CSS umgesetzt werden.

## Entwicklungsstand

Das Plugin befindet sich weiterhin in aktiver Entwicklung. Einige Funktionen sind speziell fuer die aktuelle Piwigo-Installation entstanden und werden schrittweise weiter verallgemeinert, bevor das Projekt fuer eine breitere Nutzung vorgesehen ist.
