# Piwigo Bratonien Tools

Modulares Piwigo-Plugin für Administration, Bildverarbeitung, geschützte Freigaben, Fotoauswahl und die Anbindung von Nextcloud an Piwigo.

Aktuelle Plugin-Version: **0.9.4.4**

## Grundprinzip

Bratonien Tools erweitert Piwigo um Funktionen, die in der Bratonien-Installation benötigt werden, hält die einzelnen Module aber möglichst unabhängig voneinander.

Für die Administration gilt: Der normale Nutzer sieht Aufgaben, Entscheidungen und Ergebnisse. Technische Interna werden automatisch ermittelt oder bleiben hinter ausdrücklich technischen Einstellungen verborgen.

## NC Connector

Der NC Connector synchronisiert ausdrücklich freigegebene Nextcloud-Inhalte mit Piwigo.

### Datenmodell

- Nextcloud bleibt die Quelle der Originaldateien.
- Originalbilder werden nicht in eine zweite dauerhafte Bibliothek kopiert.
- Ein Shadow Tree erzeugt die für Piwigo benötigte Verzeichnisstruktur mit Symlinks.
- Ordnerfreigaben werden als physische Piwigo-Alben synchronisiert.
- Einzeldateifreigaben werden im Galerie-Root verlinkt und als echte Piwigo-Orphans ohne Albumzuordnung registriert.
- Entfernte Freigaben werden aus Shadow Tree und Piwigo entfernt, das Nextcloud-Original bleibt erhalten.
- Neu importierte physische Connector-Alben werden privat angelegt.

### Verbindungsassistent

Neue Verbindungen werden bevorzugt mit einem Endnutzer-Assistenten angelegt.

Ablauf:

1. Nextcloud-Adresse, Benutzer und Passwort eingeben.
2. Der Assistent prüft HTTP/HTTPS, Nextcloud-Status und Anmeldung.
3. Derselbe in Schritt 1 angegebene Connector-Benutzer und dasselbe Passwort werden auch für den lesenden PostgreSQL-Zugriff verwendet; es gibt im normalen Ablauf keine zweite Benutzer-/Passwortabfrage.
4. Vorhandene passende Connector-Werte für Host, Port, Datenbank und Storage-Zuordnungen werden wiederverwendet, aber erneut mit dem aktuellen Zugang geprüft.
5. Nur wenn der übliche PostgreSQL-Weg nicht erreichbar ist, werden abweichende Datenbank-Adresse, Port und Datenbankname gezielt abgefragt. Benutzer und Passwort bleiben dabei die Angaben aus Schritt 1.
6. Nicht automatisch zuordenbare Storage-Mounts werden einzeln bestätigt.
7. Danach wird der Nextcloud-Benutzer gewählt, dessen Freigaben verwendet werden sollen. Empfohlen wird ein eigener `showcase`-Benutzer.
8. Piwigo-API-Zugang testen oder bewusst überspringen.
9. Optionaler bzw. bei übersprungener API verpflichtender Login-Fallback.
10. Erst der Abschluss legt die Verbindung dauerhaft an.

Wizard-Geheimnisse werden während der Einrichtung serverseitig in der Sitzung gehalten. Ein fehlgeschlagener Test leert die Eingaben nicht. Ein API-Test verändert die dauerhafte API-Konfiguration erst beim erfolgreichen Abschluss des Assistenten.

Die vollständige technische Maske bleibt weiterhin über **Ohne Assistent anlegen** sowie bei bestehenden Verbindungen unter **Technische Einstellungen** erreichbar.

### Lokaler Adapter

Der derzeit produktive Adapter verwendet:

- PostgreSQL-Reader mit minimalen Leserechten;
- Source-View `piwigo_showcase_sources`;
- Activity-View `piwigo_showcase_activity`;
- explizite Storage-Zuordnungen auf bereits vorhandene lokale Mounts;
- Plugin-eigene Runtime und getrennten State pro Verbindung.

Ein Storage-Mapping besteht aus `storage_id`, einem optionalen `source_prefix` und dem lokalen Mount. Ein leerer Prefix ist zulässig und bedeutet, dass der Mount direkt dem Storage-Root entspricht.

### Activity Gate

Der regelmäßige Lauf berücksichtigt:

- Nextcloud-Aktivität;
- Signatur der sichtbaren Freigaben;
- Quiet-Time;
- maximale Wartezeit;
- periodischen Full-Sync;
- notwendigen Reparaturlauf bei beschädigtem Shadow Tree oder fehlenden Piwigo-Alben.

### Piwigo-Synchronisierung

Der produktive Lauf ist API-first:

1. `bratonien.nc.syncProductive` für physische Alben und Bilder;
2. `bratonien.nc.syncOrphans` für Root-Dateien;
3. Benutzername/Passwort nur als optionaler Fallback.

Die produktive direkte API-Synchronisierung ist aktuell ausdrücklich für **Piwigo 16.4.0** freigegeben. Bei einer anderen Piwigo-Version wird sie nicht stillschweigend als kompatibel angenommen.

Der direkte produktive Sync führt außerdem die für den normalen Piwigo-Import relevanten Nacharbeiten aus, darunter Albuminformationen, globale Ränge, Bildinformationen, Pfade und Cache-Invalidierung. Er ruft dafür keine Admin-Seite fernsteuernd auf.

### Runtime

Aktive Verbindungen werden über einen gemeinsamen systemd-Timer verarbeitet:

- `bratonien-nc-connector.timer`
- `bratonien-nc-connector.service`
- `runtime/run-all.sh`
- `runtime/sync.sh`

Verbindungsspezifische Konfigurationen liegen unter `/etc/bratonien-tools/nc-connector/`, State-Daten unter `/var/lib/bratonien-tools/nc-connector/connection-ID`.

Der Shadow-Tree-Austausch besitzt einen Rollback: Scheitert der Wechsel auf den neuen Baum, wird der vorherige Galeriebaum wiederhergestellt.

### Statusanzeige

Die Administration zeigt letzten und nächsten Lauf. Der Status wird regelmäßig im Hintergrund abgefragt. Dafür wird die Admin-Seite **nicht** neu geladen; offene Dialoge, Assistenten und aufgeklappte Bereiche bleiben erhalten.

### Bestehende Verbindungen

Bestehende Verbindungen können:

- umbenannt;
- geprüft;
- technisch bearbeitet, solange sie nicht aktiv sind;
- deaktiviert und anschließend gelöscht werden.

Das Löschen einer Connector-Verbindung entfernt keine Nextcloud-Originale und keine Piwigo-Bilder. Erreichbare Connector-eigene Status-/State-Daten der Verbindung werden bereinigt.

### Legacy-Migration

Für ältere Installationen mit dem früheren separaten `piwigo-sync` existieren weiterhin Migrations-/Cutover-Helfer. Neue Installationen sollen den nativen Connector-Weg verwenden.

## Bildcache

- Piwigo-Bildderivate gezielt leeren;
- vorhandene Bildgrößen neu erzeugen;
- Cache-Aufbau als Worker-Prozess starten und abbrechen;
- Worker-Zahl automatisch oder manuell konfigurieren;
- Originalbilder bleiben unangetastet.

## Wasserzeichenverwaltung

- eigene Wasserzeichendateien;
- Wasserzeichenprofile;
- globale Regeln für öffentliche/private Alben;
- Album-Ausnahmen und Vererbung;
- Position, Transparenz und Skalierung;
- eigener Runtime-Filter für Piwigo-Derivate;
- bisherige Piwigo-Wasserzeichenkonfiguration wird beim Aktivieren gesichert.

## Bilddateien und Pfade

- Upload nach `local/bratonien/assets/`;
- Vorschau, Abmessungen und Dateigröße;
- Löschen verwalteter Assets;
- konfigurierbare PHP-Uploadgrenzen über `.user.ini`, sofern die Serverkonfiguration dies zulässt.

## Fotoauswahl und Batch Downloader

Bratonien Tools erweitert Albumseiten um eine öffentliche bzw. berechtigungsabhängige Bildauswahl und übergibt nur die ausgewählten Bild-IDs an das Piwigo-Plugin Batch Downloader.

**Abhängigkeit:** Batch Downloader muss installiert und aktiv sein.

## Fortlaufende Bildtitel

Die globale Piwigo-Stapelverarbeitung erhält die Aktion **Fortlaufende Bildtitel** mit Präfix, Startnummer, Stellenzahl, Sortierung und Schutz vorhandener individueller Titel. Physische Dateinamen werden nicht verändert.

## Albumzugriff

Alben können in Bratonien Tools zwischen öffentlich und privat umgeschaltet werden. Beim Sperren wird verhindert, dass sich der handelnde Benutzer versehentlich selbst aussperrt.

## Geschützte Albumfreigaben

Private Alben können über eigene Freigabelinks geteilt werden:

- optionales Passwort;
- optionales Ablaufdatum;
- eigener technischer Piwigo-Benutzer pro Freigabe;
- minimaler Albumzugriff;
- Hash-Speicherung von Passwörtern und Tokens;
- Widerruf und automatische Bereinigung beim Löschen eines Albums.

## Erweiterte Bildnavigation

Die Piwigo-Bilddetailseite erhält responsive Navigationszonen für vorheriges Bild, nächstes Bild, Rückkehr zur Übersicht und PhotoSwipe/Vollbild.

## Selbstaktualisierung

Der integrierte Updater:

- liest den Zielstand aus GitHub;
- bindet ein Update an einen konkreten Commit statt an einen während des Updates beweglichen Branch-Stand;
- prüft Version und SHA-256 des Zielstands;
- erstellt vor dem Austausch ein Backup;
- nutzt Post/Redirect/Get, damit ein Browser-Reload keine schreibende Aktion erneut ausführt;
- verlangt Webmaster-Rechte, `ZipArchive` und ausreichende Schreibrechte.

## Architektur

Wichtige Dateien und Verzeichnisse:

- `main.inc.php` – Plugin-Einstieg und Runtime-Hooks
- `admin.php` – zentraler Admin-Controller mit Post/Redirect/Get
- `include/tool_registry.inc.php` – Registry administrativer Aktionen
- `include/nc_connector.inc.php` – Datenmodell und gemeinsame Connector-Funktionen
- `include/nc_connector_wizard.inc.php` – Endnutzer-Assistent und Connection-Bearbeitung
- `include/nc_connector_manage.inc.php` – Credential-Format, Storage-Mappings, Verifikation und Löschen
- `include/nc_connector_create_api.inc.php` – API-first-Verbindungserstellung
- `include/nc_connector_piwigo_api.inc.php` – API-Zugang und Fallback-Verwaltung
- `include/nc_connector_system.inc.php` – Timer- und Laufzeitstatus
- `include/nc_productive_ws.inc.php` – produktiver Piwigo-Dateisync
- `include/nc_orphan_ws.inc.php` – Orphan-Synchronisierung
- `runtime/lib/activity_gate.py` – Activity Gate
- `runtime/lib/build_manifest.py` – Auflösung der Nextcloud-Freigaben auf Storage-Mounts
- `runtime/lib/shadow_tree.py` – atomarer Shadow Tree mit Rollback
- `runtime/lib/piwigo-sync.php` – API-first-Piwigo-Sync mit Fallback
- `runtime/run-all.sh` – Multi-Connection-Runner
- `runtime/sync.sh` – Ablauf einer Verbindung
- `nc-connector-install.php` – native Root-Aktivierung
- `nc-connector-disable.php` – Deaktivierung
- `nc-connector-normalize.php` – Normalisierung älterer aktiver Verbindungen
- `nc-connector-*-cleanup/switch/cutover` – Legacy-Migrationshelfer
- `include/self_update.inc.php` – Self-Updater
- `include/album_shares.inc.php` – geschützte Albumfreigaben
- `include/public_selection.inc.php` – Fotoauswahl
- `include/batch_titles.inc.php` – fortlaufende Titel
- `include/watermark_*.inc.php` – Wasserzeichen-Engine
- `tools/` – administrative Einzelwerkzeuge
- `template/`, `js/`, `css/` – Oberfläche

## Sicherheit

- administrative Schreibaktionen verwenden Piwigos CSRF-Schutz;
- Connector-Zugangsdaten werden verschlüsselt gespeichert;
- Wizard-Geheimnisse werden nicht in Browser-Web-Storage persistiert;
- Storage-Mounts werden explizit zugeordnet und validiert;
- SQL-View-Namen werden vor Verwendung validiert;
- gefährliche technische Pfade sind nicht Teil des normalen Wizard-Happy-Paths;
- produktive Piwigo-API ist versionsgebunden;
- Originalbilder werden vom Connector nicht gelöscht;
- Update-Pakete werden an Commit und Hash gebunden;
- Root-Helfer prüfen CLI-/Root-Ausführung, bevor sie Systemdienste oder `/etc`-/`/var/lib`-Daten verändern.

## Entwicklungsstand

`0.9.4.x` ist der aktuelle Entwicklungsblock. Der lokale NC Connector ist End-to-End produktiv im Einsatz. Der Verbindungsassistent und die Verwaltung werden weiter aus Endnutzersicht konsolidiert. Ein vollständig entfernter Nextcloud-Adapter ohne direkten PostgreSQL-/Storage-Zugriff ist noch nicht umgesetzt.
