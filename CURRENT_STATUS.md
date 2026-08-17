# Aktueller Entwicklungsstand

Stand: 17.08.2026

## Plugin

- Aktuelle Plugin-Version: **0.9.3.13**
- Aktueller Entwicklungsblock: **NC Connector – Verbindungsverwaltung / laufende Optimierung**
- NC Connector ist Feature 10 und noch nicht vollständig abgeschlossen.
- Solange dieser Optimierungsblock läuft, bleibt die Version im Bereich `0.9.3.x`.

## Aktueller GitHub-Stand

- Aktuelle Versionsanhebung: `0.9.3.13`
- `runtime/sync.sh` bleibt jetzt mit bereits bestehenden `connection-*.conf` kompatibel, die `NC_ACTIVITY_VIEW` noch nicht enthalten.
- Fehlt `NC_ACTIVITY_VIEW`, wird automatisch `piwigo_showcase_activity` verwendet.
- Fehlt `NC_DB_VIEW`, wird automatisch `piwigo_showcase_sources` verwendet.
- Die in `0.9.3.12` ergänzte Share-Fingerprint-Prüfung des Activity-Gates bleibt aktiv.
- Neue oder entfernte Shares können damit einen Lauf direkt auslösen, auch wenn Nextcloud dafür keinen passenden Activity-Eintrag liefert.
- Für bestehende Installationen existiert im Proxmox-Scripts-Repo eine upgrade-sichere Nextcloud-View-Migration auf `folder` und `file`, ohne das Passwort von `piwigo_reader` zu verändern.

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

Der Connector unterstützt `folder` und `file`:

- Ordner werden weiterhin als Verzeichnisbaum gespiegelt.
- Einzeldateien werden direkt als Symlink im Galerie-/Shadow-Root angelegt.
- Für einzelne Dateien wird **kein künstlicher Unterordner** erzeugt.

## Manifest / Shadow Tree

`runtime/lib/build_manifest.py` unterstützt zwei View-Schemata:

- modern: `share_id, item_type, display_name, storage_id, source_path`;
- legacy: `share_id, display_name, storage_id, source_path`.

Bei einer Legacy-View wird der Typ nach Auflösung des Storage-Pfads über Datei/Verzeichnis bestimmt. Das Manifest führt danach in beiden Fällen vier Spalten:

`share_id, item_type, display_name, source_path`

`runtime/lib/shadow_tree.py` verarbeitet daraus:

- `folder` -> Verzeichnisstruktur spiegeln;
- `file` -> Symlink direkt im Galerie-Root.

## Piwigo-Synchronisation

### Bestehender Admin-Weg

Der produktive Fallback benutzt weiterhin den bestehenden Admin-Sync über `runtime/lib/piwigo-db-sync.pl`.

### API-Weg

Es existiert der eigene Webservice-Endpunkt `bratonien.nc.sync`.

Aktueller Zustand:

- nur für Piwigo **16.4.0** freigegeben;
- aktuell nur **Simulation**;
- produktiver API-Sync (`simulate=false`) ist bewusst noch deaktiviert;
- API-Key-Authentifizierung wurde erfolgreich gegen Piwigo getestet;
- API-Simulation und originale Piwigo-Admin-Simulation lieferten in den bisherigen Vergleichstests dieselben Ergebnisse.

Der API-Weg soll später der bevorzugte Weg werden. Der klassische Admin-Weg bleibt als Fallback erhalten.

## Aktuell offener Test

Ein einzelnes Bild liegt bereits im Nextcloud-Stammverzeichnis und ist als einzelne Datei geteilt.

Der nächste Test soll nach Installation von `0.9.3.13` die komplette Kette prüfen:

`Nextcloud-View -> Activity/Share-Gate -> Manifest -> Shadow Tree -> Piwigo-API-Simulation`

Erwartung:

- die Einzeldateifreigabe erscheint in der Source-View mit `item_type = file`;
- der Gate erkennt die geänderte Share-Struktur;
- die Freigabe erscheint im Manifest als `file`;
- sie wird direkt im Galerie-/Shadow-Root als Symlink angelegt;
- die Piwigo-Simulation erkennt sie anschließend als neues Element.

## Testmodus / Sicherheit

- Der produktive Timer bleibt während der kontrollierten Tests ausgeschaltet.
- Für Connector-Tests kann `PIWIGO_SYNC_OVERRIDE=0` verwendet werden, damit der Shadow Tree aktualisiert werden kann, ohne den produktiven Piwigo-Datenbank-Sync auszuführen.
- Der verwendete Piwigo-API-Key ist ausschließlich ein temporärer Entwicklungs-/Test-Key und wird nicht Bestandteil der produktiven Konfiguration.

## Bekannte offene Punkte

- finaler End-to-End-Test für einzeln geteilte Bilder im Stammverzeichnis;
- produktiven API-Sync erst nach erfolgreichen Paritätstests aktivieren;
- Admin-Fallback mit temporären bzw. optional dauerhaft gespeicherten Zugangsdaten fertigstellen;
- Remote-Nextcloud-Adapter ist noch nicht umgesetzt;
- UI und Restpunkte der Verbindungsverwaltung werden nach Abschluss der aktuellen technischen Tests weiter bereinigt.

## Repository-Fallback

Während der Entwicklung bleibt GitHub das führende Repository. Zusätzlich existiert auf dem privaten Gitea-System ein Pull-Mirror als Ausfall-/Fallback-Ebene.
