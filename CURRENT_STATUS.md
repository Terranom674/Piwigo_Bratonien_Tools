# Aktueller Entwicklungsstand

Stand: 17.08.2026

## Plugin

- Aktuelle Plugin-Version: **0.9.3.17**
- Aktueller Entwicklungsblock: **NC Connector – Verbindungsverwaltung / laufende Optimierung**
- NC Connector ist Feature 10 und noch nicht vollständig abgeschlossen.
- Solange dieser Optimierungsblock läuft, bleibt die Version im Bereich `0.9.3.x`.

## Aktueller GitHub-Stand

Der lokale NC Connector ist für den aktuellen Bratonien-Einsatz im bisherigen Login-Weg End-to-End funktionsfähig. Mit `0.9.3.17` beginnt der Umbau auf API-first für die Piwigo-Synchronisierung.

Bereits erfolgreich umgesetzt und getestet:

- Nextcloud-Freigaben vom Typ `folder` und `file` werden aus der Source-View gelesen.
- Ordnerfreigaben werden als Verzeichnisbaum im Shadow Tree gespiegelt.
- Einzeldateifreigaben werden als Symlink direkt im Galerie-Root angelegt und als echte Piwigo-Orphans ohne Albumzuordnung registriert.
- Entfernte Einzeldateifreigaben werden beim normalen Connector-Lauf aus Shadow Tree und Piwigo entfernt.
- Das Nextcloud-Original bleibt bei Löschvorgängen unangetastet.
- Connector-getriggerte neue physische Alben werden standardmäßig privat angelegt.
- Der gemeinsame systemd-Timer `bratonien-nc-connector.timer` läuft produktiv.

Neu in `0.9.3.17`:

- die Piwigo-API ist als bevorzugter Synchronisierungsweg vorgesehen;
- ein erfolgreich geprüfter API-Key wird verschlüsselt in der Piwigo-Konfiguration gespeichert;
- produktive API-Synchronisierung ist ausdrücklich an die freigegebene Piwigo-Version **16.4.0** gebunden;
- neuer Webservice `bratonien.nc.syncProductive` delegiert den freigegebenen produktiven Dateisync an Piwigos Core-Synchronisierung;
- `runtime/lib/piwigo-sync.php` versucht zuerst die API und fällt nur bei nicht nutzbarer API auf Benutzername/Passwort zurück;
- der bisherige Benutzername/Passwort-Zugang kann als verschlüsselter Fallback gespeichert oder gelöscht werden;
- ein Benutzername/Passwort-Fallback kann außerdem einmalig manuell ausgeführt werden, ohne die Zugangsdaten zu speichern;
- neue lokale Verbindungen werden intern als `api-first` angelegt; ein dauerhaft gespeicherter Login-Fallback ist technisch nicht mehr zwingend, sofern ein API-Zugang vorhanden ist.

## Architektur NC Connector

- Nextcloud bleibt die einzige dauerhafte Quelle der Originalbilder.
- Piwigo erhält nur die benötigte Verzeichnis-/Symlink-Struktur und erzeugt daraus seine Derivate und Cache-Dateien.
- Der Connector arbeitet mit einem PostgreSQL-Leser und den Views `piwigo_showcase_sources` und `piwigo_showcase_activity`.
- Laufzeitdaten liegen unter `/var/lib/bratonien-tools/nc-connector/...`.
- Verbindungs-Konfigurationen liegen unter `/etc/bratonien-tools/nc-connector/connection-*.conf`.
- Der Shadow Tree enthält Verzeichnisse und Symlinks, keine Kopien der Originaldateien.
- Alle aktivierten Verbindungen werden über `runtime/run-all.sh` gemeinsam verarbeitet.

## Nextcloud-Freigabemodell

Der Connector unterstützt komplette Ordner und einzelne Bilder.

- `folder` -> Verzeichnisstruktur spiegeln und über Piwigos Dateisynchronisierung als physische Albumstruktur einlesen;
- `file` -> Symlink direkt im Galerie-Root und anschließende Registrierung als Piwigo-Orphan.

Für einzelne Dateien wird kein künstlicher Unterordner erzeugt.

## Activity-Gate

Das Activity-Gate berücksichtigt neben dem Nextcloud-Aktivitätsstand auch die Signatur der aktuell sichtbaren Freigaben. Weiterhin vorhanden sind Quiet-Time, maximale Wartezeit, periodischer Full-Sync und verbindungsspezifischer State.

## Piwigo-Synchronisation

### API-first

Der normale Runtime-Lauf verwendet ab `0.9.3.17` folgende Priorität:

1. gespeicherter Piwigo-API-Key;
2. bei API-Fehler, fehlender Freigabe für die installierte Piwigo-Version oder nicht vorhandener API: gespeicherter Benutzername/Passwort-Fallback;
3. ohne nutzbaren API- und Fallback-Zugang wird der Piwigo-Sync mit einer klaren Fehlermeldung abgebrochen.

Nach einem Piwigo-Update wird die produktive API nicht automatisch für die neue Version freigeschaltet. Erst nach einem Kompatibilitätstest wird die Versionsfreigabe im Plugin angehoben. Bis dahin bleibt nur der Login-Fallback zulässig.

### Physische Alben

`bratonien.nc.syncProductive` ist aktuell nur für Piwigo **16.4.0** freigegeben. Der Endpoint läuft als Administrator-Webservice und verwendet für den eigentlichen Dateisync Piwigos Core-Synchronisierung. Neue Connector-Alben werden dabei privat angelegt.

### Root-Dateien / Orphans

`bratonien.nc.syncOrphans` verarbeitet direkte Dateien im Galerie-Root separat. Neue Root-Dateien werden als Piwigo-Orphans ohne Albumzuordnung registriert. Entfernte Root-Dateien werden nur aus Piwigo entfernt; das Nextcloud-Original bleibt unberührt.

## Erfolgreiche Live-Tests bis 0.9.3.16

Bestätigt wurden:

- Ordnerfreigaben werden korrekt eingelesen;
- Einzeldateifreigaben werden als `file` erkannt und im Galerie-Root verlinkt;
- Orphan-Anlage und Orphan-Löschung funktionieren;
- physische neue Connector-Alben werden privat angelegt;
- der automatische Timer kann regulär laufen.

## Offener Test für 0.9.3.17

Vor Abschluss des API-first-Blocks muss noch live geprüft werden:

1. Plugin-Update auf `0.9.3.17`;
2. API-Key im NC Connector prüfen und speichern;
3. produktiver Lauf verwendet `bratonien.nc.syncProductive` erfolgreich;
4. Ordner- und Orphan-Sync bleiben unverändert korrekt;
5. gespeicherter Login-Fallback übernimmt bei absichtlich nicht nutzbarer API;
6. einmaliger Fallback funktioniert ohne Speicherung;
7. gelöschter Fallback wird vom Runtime-Lauf nicht mehr verwendet.

## Sicherheit / Betriebsmodell

- Nextcloud bleibt Eigentümer der Originaldateien.
- Der Connector löscht keine Originaldateien aus den Storage-Mounts.
- API- und Login-Zugangsdaten werden verschlüsselt gespeichert und nicht in das Repository geschrieben.
- Die API hat Vorrang, ist aber streng versionsgebunden.
- Benutzername/Passwort ist nur Fallback und kann dauerhaft, einmalig oder gar nicht verwendet werden.
- Neue Connector-Alben werden privat angelegt.

## Bekannte offene Punkte

Der NC Connector bleibt im Entwicklungsblock `0.9.3.x`. Nach erfolgreichem API-first-Livetest folgen insbesondere:

- Bereinigung und Vereinfachung der Verbindungsverwaltung im Admin-UI;
- Remote-Nextcloud-Adapter;
- Entscheidung über verbleibende Legacy-/Migrationshilfen;
- weitere Komfortfunktionen für Status, Diagnose und Administration.

## Repository-Fallback

Während der Entwicklung bleibt GitHub das führende Repository. Zusätzlich existiert auf dem privaten Gitea-System ein Pull-Mirror als Ausfall-/Fallback-Ebene.
