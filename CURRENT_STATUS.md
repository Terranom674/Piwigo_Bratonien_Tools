# Aktueller Entwicklungsstand

Stand: 17.08.2026

## Plugin

- Aktuelle Plugin-Version: **0.9.3.16**
- Aktueller Entwicklungsblock: **NC Connector – Verbindungsverwaltung / laufende Optimierung**
- NC Connector ist Feature 10 und noch nicht vollständig abgeschlossen.
- Solange dieser Optimierungsblock läuft, bleibt die Version im Bereich `0.9.3.x`.

## Aktueller GitHub-Stand

Der lokale NC Connector ist für den aktuellen Bratonien-Einsatz inzwischen End-to-End funktionsfähig.

Erfolgreich umgesetzt und getestet:

- Nextcloud-Freigaben vom Typ `folder` und `file` werden aus der Source-View gelesen.
- Das Manifest führt beide Freigabetypen korrekt.
- Ordnerfreigaben werden als Verzeichnisbaum im Shadow Tree gespiegelt.
- Einzeldateifreigaben werden als Symlink direkt im Galerie-Root angelegt.
- Einzeldateien werden anschließend als echte Piwigo-Orphans ohne Albumzuordnung registriert.
- Entfernte Einzeldateifreigaben werden beim normalen Connector-Lauf aus dem Shadow Tree und aus Piwigo entfernt.
- Das Nextcloud-Original bleibt bei allen Löschvorgängen unangetastet.
- Der normale Connector-Lauf führt klassischen Piwigo-Dateisync und Orphan-Abgleich automatisch hintereinander aus.
- Der gemeinsame systemd-Timer `bratonien-nc-connector.timer` kann wieder produktiv verwendet werden.
- Connector-getriggerte neue physische Alben werden standardmäßig privat angelegt, damit neu importierte Inhalte nicht ungeordnet öffentlich erscheinen.

## Architektur NC Connector

- Nextcloud bleibt die einzige dauerhafte Quelle der Originalbilder.
- Piwigo erhält nur die für die Galerie benötigte Verzeichnis-/Symlink-Struktur und erzeugt daraus seine Derivate und Cache-Dateien.
- Der Connector arbeitet mit einem PostgreSQL-Leser und den Views `piwigo_showcase_sources` und `piwigo_showcase_activity`.
- Laufzeitdaten liegen unter `/var/lib/bratonien-tools/nc-connector/...`.
- Verbindungs-Konfigurationen liegen unter `/etc/bratonien-tools/nc-connector/connection-*.conf`.
- Der Shadow Tree enthält Verzeichnisse und Symlinks, keine Kopien der Originaldateien.
- Alle aktivierten Verbindungen werden über `runtime/run-all.sh` gemeinsam verarbeitet.

## Nextcloud-Freigabemodell

Der Connector unterstützt zwei Fälle:

1. komplette Ordner werden geteilt;
2. einzelne Bilder werden direkt geteilt.

Verarbeitung:

- `folder` -> Verzeichnisstruktur spiegeln und über Piwigos klassische Dateisynchronisierung als physische Albumstruktur einlesen;
- `file` -> Symlink direkt im Galerie-Root und anschließende Registrierung als Piwigo-Orphan.

Für einzelne Dateien wird kein künstlicher Unterordner erzeugt.

## Manifest / Shadow Tree

`runtime/lib/build_manifest.py` unterstützt zwei View-Schemata:

- modern: `share_id, item_type, display_name, storage_id, source_path`;
- legacy: `share_id, display_name, storage_id, source_path`.

Bei einer Legacy-View wird der Typ nach Auflösung des Storage-Pfads über Datei oder Verzeichnis bestimmt.

Das Manifest führt danach in beiden Fällen:

`share_id, item_type, display_name, source_path`

`runtime/lib/shadow_tree.py` verarbeitet daraus:

- `folder` -> Verzeichnisstruktur spiegeln;
- `file` -> Symlink direkt im Galerie-Root.

## Activity-Gate

Das Activity-Gate berücksichtigt neben dem Nextcloud-Aktivitätsstand auch die Signatur der aktuell sichtbaren Freigaben.

Damit werden Strukturänderungen an den Shares auch dann erkannt, wenn sie nicht zuverlässig durch einen reinen Maximalwert der Nextcloud-Aktivität abgebildet werden.

Weiterhin vorhanden:

- Quiet-Time;
- maximale Wartezeit;
- periodischer Full-Sync;
- Verbindungsspezifischer State.

## Piwigo-Synchronisation

### Physische Alben

`runtime/lib/piwigo-db-sync.pl` meldet sich an Piwigo an und löst die normale Dateisynchronisierung für die physischen Albumverzeichnisse aus.

Connector-getriggerte Neuanlagen werden dabei mit privatem Standardstatus verarbeitet.

### Root-Dateien / Orphans

Piwigos klassische Dateisynchronisation kann Dateien direkt unter dem Galerie-Root nicht als neues Element einem physischen Album zuordnen.

Deshalb verarbeitet `bratonien.nc.syncOrphans` diese Dateien separat.

Für neue Root-Dateien:

- wird ein normaler Eintrag in `piwigo_images` angelegt;
- `storage_category_id` bleibt `NULL`;
- es wird kein Eintrag in `piwigo_image_category` erzeugt;
- Metadaten und Piwigo-Aktivität werden über vorhandene Piwigo-Funktionen aktualisiert;
- die Datei erscheint im Batch Manager unter `With no album / Orphans`.

Für entfernte Root-Dateien wird nur der Piwigo-Datenbankeintrag entfernt. Das Nextcloud-Original bleibt unberührt.

### API-Weg

Es existieren zwei eigene Webservice-Endpunkte:

- `bratonien.nc.sync` – vollständiger read-only Paritätstest des klassischen Piwigo-Syncs;
- `bratonien.nc.syncOrphans` – dedizierte Root-Orphan-Synchronisation mit Simulation und produktivem Modus.

Beide Wege sind aktuell für Piwigo **16.4.0** freigegeben.

## Erfolgreiche Live-Tests

Bestätigt wurden:

- Ordnerfreigaben werden korrekt eingelesen.
- Eine einzelne Nextcloud-Dateifreigabe wurde im Manifest als `file` erkannt.
- Der zugehörige Symlink wurde direkt im Galerie-Root erzeugt.
- Die Orphan-Simulation erkannte die Datei als `new_orphans = 1` ohne Datenbankschreibzugriff.
- Der produktive Lauf registrierte die Datei als echtes Piwigo-Orphan.
- Die Piwigo-Fotoanzahl stieg dabei entsprechend an.
- Nach Entfernen der Nextcloud-Freigabe meldete der normale Connector-Lauf `files = 0` und `Piwigo-Orphans synchronisiert: +0 / -1`.
- Der Root-Symlink wurde entfernt.
- Ein anschließender Orphan-Abgleich fand keine Restdatei mehr.
- Der automatische Timer kann wieder regulär laufen.

## Sicherheit / Betriebsmodell

- Nextcloud bleibt Eigentümer der Originaldateien.
- Der Connector löscht keine Originaldateien aus den Storage-Mounts.
- Zugangsdaten werden nicht in das Repository geschrieben.
- Aktive Verbindungen verwenden getrennte Konfigurations-, Secret- und State-Dateien.
- Der Piwigo-Sync wird nur für aktivierte Connector-Verbindungen ausgeführt.
- Neue Connector-Alben werden privat angelegt, bevor sie regulär einsortiert oder freigegeben werden.

## Bekannte offene Punkte

Der lokale Connector-Grundbetrieb ist abgeschlossen. Offen bleiben vor allem die nächsten Ausbauschritte:

- Remote-Nextcloud-Adapter;
- weitere Bereinigung und Vereinfachung der Verbindungsverwaltung im Admin-UI;
- endgültige Entscheidung, welche Legacy-/Migrationshilfen nach stabiler Betriebsphase noch im Plugin verbleiben sollen;
- weitere Komfortfunktionen für Status, Diagnose und Administration.

## Repository-Fallback

Während der Entwicklung bleibt GitHub das führende Repository. Zusätzlich existiert auf dem privaten Gitea-System ein Pull-Mirror als Ausfall-/Fallback-Ebene.
