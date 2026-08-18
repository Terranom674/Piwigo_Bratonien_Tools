# Aktueller Entwicklungsstand

Stand: 18.08.2026

## Plugin

- Aktuelle Plugin-Version: **0.9.4.4**
- Aktueller Entwicklungsblock: **NC Connector – Endnutzer-Assistent und Konsolidierung**
- `0.9.3.43` markiert den abgeschlossenen vorherigen Meilenstein.
- `0.9.4.x` ist der neue Entwicklungsblock.

## Produktiver Stand

Der lokale NC Connector ist im aktuellen Bratonien-Einsatz End-to-End funktionsfähig:

- Nextcloud-Freigaben `folder` und `file` werden verarbeitet.
- Ordnerfreigaben werden als physische Piwigo-Alben synchronisiert.
- Einzeldateifreigaben werden als echte Piwigo-Orphans ohne Albumzuordnung registriert.
- Entfernte Freigaben werden aus Piwigo entfernt, Originaldateien bleiben erhalten.
- Neue physische Connector-Alben werden privat angelegt.
- API-first-Synchronisierung ist produktiv für Piwigo **16.4.0** freigegeben.
- Piwigo-Album- und Fotoinformationen werden nach dem direkten Sync aktualisiert.
- Der gemeinsame systemd-Timer verarbeitet aktive Verbindungen regelmäßig.

## 0.9.4.1

Die Statusaktualisierung im Admin-UI wurde von Seiten-Reload auf gezielte DOM-Aktualisierung umgestellt. Der Poller aktualisiert nur noch **Letzter Lauf** und **Nächster Lauf**. Offene Dialoge, Details und der Assistent bleiben dadurch erhalten.

## 0.9.4.2

### Verbindungsassistent

Der Assistent wurde aus Endnutzersicht konsolidiert. Technische Werte werden nur gezielt abgefragt, wenn eine automatische Prüfung sie nicht bestätigen kann.

### Storage-Mappings

Das Storage-Format erlaubt einen leeren `source_prefix`. Damit kann ein kompletter Storage direkt auf einen lokalen Mount zeigen.

### Native Aktivierung

`nc-connector-install.php` unterstützt API-only-Verbindungen ohne dauerhaft gespeicherten Login-Fallback. Ein Fallback ist nur nötig, wenn keine nutzbare API gespeichert ist.

## 0.9.4.3

- Syntaxfehler im Wizard-Abschluss korrigiert.
- Neue Wizard-Durchläufe werden von alten serverseitigen Zuständen getrennt.
- Abbrechen und Schließen verwerfen den laufenden Wizard-State.

## 0.9.4.4

Der Connector-Benutzer aus Schritt 1 ist zugleich der lesende Datenbank-Benutzer. Der Assistent fragt deshalb Benutzername und Passwort nicht erneut ab.

Ablauf:

1. Nextcloud-Adresse + Connector-Benutzer + Passwort.
2. Web-Zugang und OCS werden geprüft.
3. Derselbe Benutzer und dasselbe Passwort werden automatisch für PostgreSQL verwendet.
4. Der Assistent prüft den üblichen Weg mit Nextcloud-Host, Port 5432 und Datenbank `nextcloud` bzw. bekannte passende Verbindungswerte.
5. Nur wenn die Datenbank dort nicht erreichbar ist, werden Host, Port und Datenbankname abgefragt. Benutzer und Passwort bleiben unverändert aus Schritt 1.
6. Danach folgen gegebenenfalls Storage-Zuordnung, Showcase-Benutzer, Piwigo-API und Fallback.

Die PHP-Datei `include/nc_connector_wizard.inc.php` wurde vor dem Versionsbump mit dem PHP-Parser geprüft.

## Architektur NC Connector

- Nextcloud ist Quelle der Originalbilder.
- Piwigo erhält einen Shadow Tree aus Verzeichnissen und Symlinks.
- PostgreSQL-Reader und die Views `piwigo_showcase_sources` / `piwigo_showcase_activity` liefern Quellen und Aktivität.
- Runtime-Konfigurationen: `/etc/bratonien-tools/nc-connector/connection-*.conf`
- State: `/var/lib/bratonien-tools/nc-connector/connection-ID`
- Öffentlicher Admin-Status: Piwigo `_data/bratonien-tools/nc-connector-status/`
- Runner: `runtime/run-all.sh`
- Einzelverbindung: `runtime/sync.sh`

## Piwigo-Synchronisierung

Priorität:

1. gespeicherter Piwigo-API-Key;
2. bei nicht nutzbarer API gespeicherter Benutzername/Passwort-Fallback;
3. ohne beide Wege Abbruch mit Fehlerstatus.

Produktive API-Methoden:

- `bratonien.nc.syncProductive`
- `bratonien.nc.syncOrphans`

`bratonien.nc.syncProductive` ist auf Piwigo 16.4.0 versionsgebunden. Der direkte Sync verwendet Bratonien-eigene Logik auf Basis der Piwigo-Core-Funktionen, nicht das Rendern oder Fernsteuern einer Admin-Seite.

## Activity Gate und Shadow Tree

Das Activity Gate berücksichtigt:

- Aktivitätsstand;
- Share-Fingerprint;
- Quiet-Time;
- Max-Wartezeit;
- periodischen Full-Sync;
- Reparaturbedarf bei beschädigter lokaler Struktur.

Der Shadow-Tree-Wechsel besitzt einen Rollback auf den vorherigen Baum, falls das Umschalten fehlschlägt.

## Sicherheit

- Nextcloud-Originale werden nicht gelöscht.
- Connector-Zugangsdaten werden verschlüsselt gespeichert.
- Wizard-Secrets bleiben serverseitig.
- Storage-Pfade werden validiert.
- SQL-View-Namen werden validiert.
- Piwigo-API ist versionsgebunden.
- Mutierende Admin-Aktionen verwenden Post/Redirect/Get, sodass Browser-Reloads sie nicht erneut ausführen.
- Self-Updates sind an einen konkreten Commit und SHA-256 gebunden.

## Noch offen

- vollständiger Remote-Nextcloud-Adapter ohne direkten PostgreSQL-/Storage-Zugriff;
- weitere Bereinigung alter Legacy-Migrationshelfer, sobald sie nicht mehr benötigt werden;
- weitere Endnutzer-Optimierung des Assistenten auf Basis realer Fehler- und Installationspfade.

## Repository-Fallback

GitHub bleibt das führende Repository. Das private Gitea-System dient weiterhin als Mirror/Fallback.
