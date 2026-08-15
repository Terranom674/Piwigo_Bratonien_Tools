# Piwigo Bratonien Tools

Eigene, modular aufgebaute Administrationswerkzeuge fuer die Bratonien-Piwigo-Installation.

## Ziel

Das Plugin ist bewusst als Werkzeugkasten aufgebaut. Neue Funktionen werden als getrennte Tools implementiert und anschliessend zentral registriert. Die Admin-Oberflaeche stellt registrierte Tools automatisch dar.

## Aktuelles Tool: Bildcache leeren

Der One-Click-Button **Bildcache leeren** entfernt alle von Piwigo erzeugten Dateien unter `_data/i/`, mit Ausnahme von `index.htm`.

Damit werden unter anderem geloescht:

- Standard-Bildderivate
- Vorschaubilder
- gdThumb-Custom-Derivate (`cu_*`)
- weitere von Piwigo oder Plugins dort erzeugte Bildvarianten

Originalbilder unter `galleries/` werden nicht veraendert. Piwigo erzeugt benoetigte Derivate beim naechsten Aufruf neu.

Der Browsercache ist bewusst nicht Bestandteil dieses Tools.

## Struktur

- `main.inc.php` - Plugin-Einstieg und Admin-Menue
- `admin.php` - zentraler Admin-Controller
- `include/tool_registry.inc.php` - Registry aller verfuegbaren Werkzeuge
- `tools/` - Implementierungen einzelner Werkzeuge
- `template/admin.tpl` - gemeinsame Admin-Oberflaeche
- `maintain.class.php` - Piwigo-Lifecycle

## Neues Tool hinzufuegen

1. Neue Implementierung unter `tools/` anlegen.
2. Datei in `include/tool_registry.inc.php` laden.
3. Tool mit Titel, Beschreibung, Button, Bestaetigung und Handler in der Registry eintragen.

Die Admin-Oberflaeche muss fuer normale One-Click-Werkzeuge nicht angepasst werden.

## Sicherheit

Das Plugin prueft Administratorrechte und Piwigos CSRF-Token. Das Bildcache-Tool validiert ausserdem den Zielpfad vor dem Loeschen und greift ausschliesslich auf Piwigos `_data/i`-Verzeichnis zu.
