# Aktueller Entwicklungsstand

Stand: 17.08.2026

## Plugin

- Aktuelle Plugin-Version: **0.9.3.14**
- Aktueller Entwicklungsblock: **NC Connector – Verbindungsverwaltung / laufende Optimierung**
- NC Connector ist Feature 10 und noch nicht vollständig abgeschlossen.
- Solange dieser Optimierungsblock läuft, bleibt die Version im Bereich `0.9.3.x`.

## Aktueller GitHub-Stand

- Aktuelle Versionsanhebung: `0.9.3.14`
- Einzeldateifreigaben im Galerie-Root werden nicht mehr ausschließlich der klassischen Piwigo-Dateisynchronisation überlassen.
- Neuer Webservice `bratonien.nc.syncOrphans` registriert direkte Root-Dateien als echte Piwigo-Orphans ohne Album-Zuordnung.
- `runtime/lib/piwigo-db-sync.pl` führt weiterhin zuerst den normalen Piwigo-Dateisync für physische Alben aus und synchronisiert danach die Root-Orphans produktiv über den neuen Webservice.
- Entfernte Root-Freigaben werden aus der Piwigo-Datenbank entfernt, ohne das Nextcloud-Original zu löschen.
- Der bestehende Webservice `bratonien.nc.sync` bleibt weiterhin der read-only Paritätstest für den vollständigen klassischen Sync.
- Die in `0.9.3.12` ergänzte Share-Fingerprint-Prüfung des Activity-Gates bleibt aktiv.
- `runtime/sync.sh` bleibt mit älteren `connection-*.conf` kompatibel und verwendet bei fehlenden Werten automatisch `piwigo_showcase_activity` bzw. `piwigo_showcase_sources`.

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

### Physische Alben

Der produktive Fallback benutzt weiterhin den bestehenden Admin-Sync über `runtime/lib/piwigo-db-sync.pl` für normale Verzeichnisse und physische Alben.

### Root-Dateien / Orphans

Piwigos klassische Dateisynchronisation kann Dateien direkt unter `galleries/` nicht als neues Element einem physischen Album zuordnen. Deshalb verarbeitet `bratonien.nc.syncOrphans` ausschließlich direkte Dateien im konfigurierten Galerie-Root.

Für neue Root-Dateien:

- wird ein normaler Eintrag in `piwigo_images` angelegt;
- `storage_category_id` bleibt `NULL`;
- es wird kein Eintrag in `piwigo_image_category` erzeugt;
- Metadaten und Piwigo-Aktivität werden über die vorhandenen Piwigo-Funktionen aktualisiert;
- die Datei erscheint damit im Batch Manager unter `With no album / Orphans`.

Für entfernte Root-Dateien wird nur der Piwigo-Datenbankeintrag entfernt. Das Nextcloud-Original bleibt unberührt.

### API-Weg

Es existieren jetzt zwei eigene Webservice-Endpunkte:

- `bratonien.nc.sync` – vollständiger read-only Paritätstest des klassischen Piwigo-Syncs;
- `bratonien.nc.syncOrphans` – dedizierte Root-Orphan-Synchronisation mit Simulation und produktivem Modus.

Beide Wege sind aktuell ausschließlich für Piwigo **16.4.0** freigegeben.

## Aktuell offener Test

Die Einzeldateifreigabe `DSC1461-Enhanced-NR (1).jpg` wird bereits korrekt als `file` aus Nextcloud gelesen und als Symlink direkt im Galerie-Root angelegt.

Nach Installation von `0.9.3.14` muss geprüft werden:

1. Simulation von `bratonien.nc.syncOrphans` meldet `new_orphans = 1`;
2. normaler Connector-Lauf registriert das Bild produktiv;
3. danach meldet die Orphan-Simulation `new_orphans = 0` und `registered_orphans = 1`;
4. das Bild erscheint im Piwigo Batch Manager unter `With no album / Orphans`;
5. das Entfernen der Nextcloud-Freigabe entfernt später nur den Piwigo-Eintrag, nicht das Original.

## Testmodus / Sicherheit

- Der produktive Timer bleibt während der kontrollierten Tests ausgeschaltet.
- Für reine Shadow-Tree-Tests kann `PIWIGO_SYNC_OVERRIDE=0` verwendet werden.
- Der verwendete Piwigo-API-Key ist ausschließlich ein temporärer Entwicklungs-/Test-Key und wird nicht Bestandteil der produktiven Konfiguration.

## Bekannte offene Punkte

- finaler End-to-End-Test der neuen Orphan-Synchronisation;
- danach Löschtest einer Einzeldateifreigabe;
- produktiven vollständigen API-Sync erst nach erfolgreichen Paritätstests aktivieren;
- Admin-Fallback mit temporären bzw. optional dauerhaft gespeicherten Zugangsdaten fertigstellen;
- Remote-Nextcloud-Adapter ist noch nicht umgesetzt;
- UI und Restpunkte der Verbindungsverwaltung werden nach Abschluss der aktuellen technischen Tests weiter bereinigt.

## Repository-Fallback

Während der Entwicklung bleibt GitHub das führende Repository. Zusätzlich existiert auf dem privaten Gitea-System ein Pull-Mirror als Ausfall-/Fallback-Ebene.
