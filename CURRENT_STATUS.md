# Aktueller Entwicklungsstand

Stand: 17.08.2026

## Plugin

- Aktuelle Plugin-Version: **0.9.3.10**
- Aktueller Entwicklungsblock: **NC Connector – Verbindungsverwaltung / laufende Optimierung**
- NC Connector ist Feature 10 und noch nicht vollständig abgeschlossen.
- Solange dieser Optimierungsblock läuft, bleibt die Version im Bereich `0.9.3.x`.

## Aktueller GitHub-Stand

- Aktuelle Versionsanhebung: `0.9.3.10`
- Anlass: Nextcloud-Showcase-View im zugehörigen Provisioning wurde auf `folder` und `file` vereinheitlicht; damit entspricht die vom Connector genutzte Infrastruktur dem bereits vorhandenen Plugin-Stand.
- Zugehöriger Proxmox-Scripts-Commit: `440173936071240935fd38f4158d8030e9b2e546`

## Architektur NC Connector

- Nextcloud bleibt die einzige dauerhafte Quelle der Originalbilder.
- Piwigo erhält nur die für die Galerie benötigte Verzeichnis-/Symlink-Struktur und erzeugt daraus seine Derivate/Cache-Dateien.
- Der Connector arbeitet mit einem PostgreSQL-Leser und den Views `piwigo_showcase_sources` und `piwigo_showcase_activity`.
- Laufzeitdaten liegen unter `/var/lib/bratonien-tools/nc-connector/...`.
- Verbindungs-Konfigurationen liegen unter `/etc/bratonien-tools/nc-connector/connection-*.conf`.
- Shadow Tree: Verzeichnisse und Symlinks, keine Kopien der Originaldateien.

## Nextcloud-Freigabemodell

Für Showcase gelten zwei Fälle:

1. komplette Ordner werden geteilt;
2. einzelne Bilder werden direkt in das Nextcloud-Stammverzeichnis geteilt.

Die bisherige View filterte ausschließlich `item_type = 'folder'`. Dadurch wurden einzeln geteilte Bilder im Stammverzeichnis vom Connector nicht gesehen.

Der neue Stand erweitert den Connector auf `folder` und `file`:

- Ordner werden weiterhin als Verzeichnisbaum gespiegelt.
- Einzeldateien werden direkt als Symlink im Galerie-/Shadow-Root angelegt.
- Für einzelne Dateien wird **kein künstlicher Unterordner** erzeugt.

## Manifest / Shadow Tree

`runtime/lib/build_manifest.py` wurde auf die Unterscheidung `folder` / `file` erweitert.

Das Manifest führt dabei die Freigabeart mit, damit `runtime/lib/shadow_tree.py` unterscheiden kann:

- `folder` -> Verzeichnisstruktur spiegeln;
- `file` -> Symlink direkt im Galerie-Root.

Die SQL-View-Anpassung wurde so überarbeitet, dass die bestehende Spaltenreihenfolge bei einem Upgrade erhalten bleibt und `item_type` migrationssicher ergänzt werden kann.

## Piwigo-Synchronisation

### Bestehender Admin-Weg

Der produktive Fallback benutzt weiterhin den bestehenden Admin-Sync über `runtime/lib/piwigo-db-sync.pl`.

### API-Weg

Es existiert der eigene Webservice-Endpunkt:

`bratonien.nc.sync`

Aktueller Zustand:

- nur für Piwigo **16.4.0** freigegeben;
- aktuell nur **Simulation**;
- produktiver API-Sync (`simulate=false`) ist bewusst noch deaktiviert;
- API-Key-Authentifizierung wurde erfolgreich gegen Piwigo getestet;
- API-Simulation und originale Piwigo-Admin-Simulation lieferten in den bisherigen Vergleichstests dieselben Ergebnisse.

Der API-Weg soll später der bevorzugte Weg werden. Der klassische Admin-Weg bleibt als Fallback erhalten.

## Fallback-Zugangsdaten

Für den späteren Admin-Fallback gilt:

- bereits dauerhaft gespeicherte Admin-Zugangsdaten dürfen genutzt werden;
- wenn keine gespeichert sind, muss die UI die Eingabe für den Fallback anbieten;
- es muss ausdrücklich die Option **„nur vorübergehend verwenden“** geben;
- temporär eingegebene Zugangsdaten dürfen nicht stillschweigend gespeichert werden;
- dauerhafte Speicherung nur nach ausdrücklicher Auswahl und verschlüsselt.

## Bisherige Tests

Bestätigt:

- stabile Ordnerfreigaben funktionieren;
- Entfernen einer Ordnerfreigabe wurde korrekt als zu entfernende Kategorie erkannt;
- Wiederherstellung der Freigabe stellte den Shadow Tree wieder her;
- API-Simulation und originale Admin-Simulation waren bei den bisherigen Tests deckungsgleich;
- Piwigo selbst unterstützt Dateien direkt im Galerie-Root; die frühere Nichterkennung einzelner Bilder kam vom Nextcloud-View, nicht von Piwigo.

## Aktuell offener Test

Ein einzelnes Bild liegt bereits im Nextcloud-Stammverzeichnis und ist als einzelne Datei geteilt.

Der nächste Test soll die komplette neue Kette prüfen:

`Nextcloud-View -> Manifest -> Shadow Tree -> Piwigo-API-Simulation`

Erwartung:

- das Bild erscheint in der View als `item_type = file`;
- es erscheint im Manifest als Datei-Freigabe;
- es wird direkt im Galerie-/Shadow-Root als Symlink angelegt;
- die Piwigo-Simulation erkennt es anschließend als neues Element.

## Testmodus / Sicherheit

- Der produktive Timer bleibt während der kontrollierten Tests ausgeschaltet.
- Für Connector-Tests wird `PIWIGO_SYNC_OVERRIDE=0` verwendet, damit der Shadow Tree aktualisiert werden kann, ohne den produktiven Piwigo-Datenbank-Sync auszuführen.
- Der verwendete Piwigo-API-Key ist ausschließlich ein temporärer Entwicklungs-/Test-Key und wird nicht Bestandteil der produktiven Konfiguration.

## Bekannte offene Punkte

- finaler End-to-End-Test für einzeln geteilte Bilder im Stammverzeichnis;
- produktiven API-Sync erst nach erfolgreichen Paritätstests aktivieren;
- Admin-Fallback mit temporären bzw. optional dauerhaft gespeicherten Zugangsdaten fertigstellen;
- Activity-Gate-Statusmeldung unterscheidet aktuell nicht sauber zwischen „keine Änderungen“ und „Änderungen erkannt, aber noch im Debounce“;
- Remote-Nextcloud-Adapter ist noch nicht umgesetzt;
- UI und Restpunkte der Verbindungsverwaltung werden nach Abschluss der aktuellen technischen Tests weiter bereinigt.

## Repository-Fallback

Während der Entwicklung bleibt GitHub das führende Repository. Zusätzlich existiert auf dem privaten Gitea-System ein Pull-Mirror als Ausfall-/Fallback-Ebene.
