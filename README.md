# Piwigo Bratonien Tools

Modulares Piwigo-Plugin für Administration, Bildverarbeitung, geschützte Freigaben, Fotoauswahl und die Anbindung von Nextcloud an Piwigo.

Aktuelle Plugin-Version: **9.7.1.0**

## Grundprinzip

Bratonien Tools erweitert Piwigo um Funktionen, die in der Bratonien-Installation benötigt werden, hält die einzelnen Module aber möglichst unabhängig voneinander.

Für die Administration gilt: Der normale Nutzer sieht Aufgaben, Entscheidungen und Ergebnisse. Technische Interna werden automatisch ermittelt oder bleiben hinter ausdrücklich technischen Einstellungen verborgen.

## NC Connector

Der NC Connector bindet ausdrücklich ausgewählte Nextcloud-Inhalte per WebDAV an Piwigo an. Nextcloud bleibt dabei die Quelle der Originaldateien; Piwigo verwaltet Alben, Bilder und seine eigenen Derivate.

### Datenmodell

- Nextcloud bleibt die verbindliche Quelle der Originaldateien.
- Originalbilder werden nicht dauerhaft als zweite vollständige Bibliothek unter Piwigo gespeichert.
- Eine lokale Platzhalterquelle enthält physische 1×1-Dateien, damit Piwigo die Bilder regulär als Dateisysteminhalte erkennen kann.
- Ein Shadow Tree bildet daraus die von Piwigo erwartete Galerie-Ordnerstruktur.
- Ordner werden als physische Piwigo-Alben synchronisiert.
- Direkt im ausgewählten Nextcloud-Root liegende Einzelbilder werden über die vorhandene Orphan-Logik behandelt.
- Neu importierte physische Connector-Alben werden privat angelegt.
- Entfernte Quellen verschwinden aus Shadow Tree und Piwigo; die Nextcloud-Originale bleiben erhalten.

### Nextcloud-Root ohne künstliches Benutzeralbum

Ein leerer `webdav_path` ist ein gültiger Root und bedeutet den Dateibereich des authentifizierten Nextcloud-Benutzers.

Dabei wird der Nextcloud-Benutzername nicht als künstliche Album-Ebene angelegt. Beispiel:

```text
Nextcloud
/
├── 400 Auswahl/
├── Zweites Album/
├── einzelbild-1.jpg
└── einzelbild-2.jpg
```

wird in Piwigo logisch als

```text
400 Auswahl
Zweites Album
```

abgebildet. Die direkt im Root liegenden Einzelbilder werden separat durch die Orphan-Synchronisierung erfasst.

### Voraussetzungen

Benötigt werden nur:

- Nextcloud-Adresse;
- Nextcloud-Benutzer bzw. App-Passwort;
- normaler WebDAV-Zugriff auf die Inhalte dieses Benutzers;
- eine normale Piwigo-Installation mit ihrer vorhandenen PHP-/Bildverarbeitungsumgebung.

Nicht benötigt werden Nextcloud-Datenbankzugriff, `occ`-Adminzugriff, Storage-IDs, Backend-Pfade, zusätzliche Host-Mounts, FUSE, davfs oder rclone.

### WebDAV-Scan und Shadow Tree

1. Der Assistent prüft Nextcloud und liest die sichtbaren Verzeichnisse per WebDAV.
2. Ausgewählte Verzeichnisse werden rekursiv per PROPFIND eingelesen.
3. Ordner, Dateiname, Nextcloud-Datei-ID, MIME-Typ, Größe, ETag, Originalabmessungen und WebDAV-Pfad werden erfasst.
4. `runtime/lib/build_webdav_placeholder_source.py` erzeugt eine lokale Platzhalterquelle ohne dauerhafte Originalkopie.
5. `runtime/lib/shadow_tree.py` baut atomar den verbindungseigenen Shadow Tree.
6. Der WebDAV-Galeriebaum liegt unter `_data/bratonien-tools/nc-webdav-gallery/connection-ID`.
7. Jede WebDAV-Verbindung wird als eigene physische Piwigo-Site registriert.
8. Piwigos Dateisynchronisierung erhält ausschließlich diese verbindungseigene Site.
9. `runtime/lib/sync-webdav-metadata.php` übernimmt die von Nextcloud gelieferten Originalmaße in Piwigos Bilddatensätze.

### On-Demand-Bildauslieferung und Piwigo-Derivate

`include/webdav_materialize_runtime.inc.php` erkennt anhand des Piwigo-Bildpfads die zugehörige WebDAV-Verbindung, den Nextcloud-Root und den relativen WebDAV-Pfad. Aus dem Mapping stehen zusätzlich `fileid`, Originalbreite/-höhe, MIME-Typ, Dateigröße und ETag zur Verfügung. Der Hook `get_derivative_url` leitet fehlende WebDAV-Derivate an `webdav-derivative.php` um.

Wenn ein benötigtes Piwigo-Derivat noch nicht existiert:

1. `webdav-derivative.php` prüft den Piwigo-Zugriff auf das Bild.
2. Für `custom:s9999x250` und `standard:square` wird bevorzugt eine passende authentifizierte Nextcloud-Preview anhand der `fileid` geladen. Die Preview wird mit ungefähr doppelter benötigter Piwigo-Auflösung und erhaltenem Seitenverhältnis angefordert.
3. Ist keine passende Preview planbar oder schlägt der Preview-Abruf fehl, wird automatisch das vollständige Original per WebDAV geladen. Große bzw. originale Anforderungen verwenden weiterhin die Originalquelle.
4. Die temporäre Bildquelle ersetzt für die Dauer der Erzeugung die physische 1×1-Platzhalterdatei bzw. den Shadow-Link am von Piwigo erwarteten Quellpfad.
5. Piwigos eigene `pwg_image`-/Derivative-Logik erzeugt das normale Piwigo-Derivat direkt im selben PHP-Prozess. Ein interner HTTP-Self-Request auf `i.php` wird nicht verwendet.
6. Danach wird die temporäre Bildquelle entfernt und der Platzhalter-/Shadow-Zustand wiederhergestellt.
7. Das fertige Derivat wird ausgeliefert und künftig normal aus Piwigos `_data/i`-Derivat-Cache verwendet.

Damit bleibt Piwigo für Größen, Derivate und Lazy-Loading verantwortlich. Der Connector hält keinen parallelen eigenen vollständigen Derivat-Cache vor.

Für Fälle, in denen Piwigo direkt die Originalquelle benötigt, steht weiterhin die serverseitige WebDAV-Auslieferung zur Verfügung. Nextcloud-Zugangsdaten werden nicht an den Browser weitergegeben.

### Piwigo-Synchronisierung

Der Connector verwendet die vorhandene Piwigo-Dateisynchronisierung für die verbindungseigene WebDAV-Site. Der Shadow Tree ist ausschließlich die Dateisystemdarstellung, die Piwigo für Alben und Bilder benötigt.

`runtime/lib/piwigo-sync.php` bevorzugt die Webservice-Methode `bratonien.nc.syncProductive`. Ist keine Piwigo-API für die Verbindung eingerichtet, wird der vorhandene Administrator-/Webmaster-Benutzername/Passwort-Fallback verwendet und Piwigos nativer `site_update` ausgeführt.

Nach der regulären Dateisynchronisierung läuft `bratonien.nc.syncOrphans` für direkt im Site-Root liegende Einzelbilder.

Die produktive Synchronisierung ist aktuell auf **Piwigo 16.4.0** abgestimmt.

### Private Top-Level-Alben

Durch das Überspringen der technischen Nextcloud-Benutzerebene können WebDAV-Unterordner zu echten Top-Level-Piwigo-Alben werden. Für den Connector-Sync wird deshalb der Zugriff des verwendeten Piwigo-Administrators auf neu erzeugte private Top-Level-Alben erhalten und der Benutzer-Cache anschließend invalidiert.

### Administrations-Dashboard

Piwigo 16.4.0 blendet seine Album-Statistik standardmäßig bei exakt einem Album aus (`NB_ALBUMS > 1`). Bratonien Tools passt ausschließlich diese Dashboard-Anzeige auf `NB_ALBUMS > 0` an. Dadurch wird auch ein einzelnes korrekt angelegtes Album in der Verwaltungsübersicht angezeigt.

### Statusanzeige

Die Administration zeigt Laufzeitstatus und Fehler pro Verbindung. Fehlerdetails bleiben der betroffenen Verbindung zugeordnet.

### Löschen einer Verbindung

Beim Löschen einer WebDAV-Verbindung werden ihre zugehörige Piwigo-Site und die dazugehörigen Piwigo-Datensätze entfernt. Nextcloud-Dateien bleiben unverändert. Runtime-, Shadowtree-, Source-, Preview- und Statusdaten werden bereinigt.

### Runtime

Aktive WebDAV-Verbindungen werden über den gemeinsamen Runner verarbeitet:

- `bratonien-nc-connector.timer`
- `bratonien-nc-connector.service`
- `runtime/run-all.sh`

Verbindungsspezifische Konfigurationen liegen unter `/etc/bratonien-tools/nc-connector/`, State-Daten unter `/var/lib/bratonien-tools/nc-connector/connection-ID`.

Der gemeinsame Lauf besteht aktuell aus:

1. `runtime/reconcile-webdav.php` – gespeicherte Verbindungen in Runtime-Konfigurationen überführen;
2. `runtime/repair-webdav-orphans.php` – vorhandene WebDAV-Orphan-Zustände reparieren;
3. `runtime/cleanup-webdav-piwigo.php` – verwaiste WebDAV-Piwigo-Daten bereinigen;
4. `runtime/sync-webdav.sh` – jede vorhandene WebDAV-Verbindung synchronisieren.

Der verbindungsspezifische Lauf erzeugt Platzhalterquelle und Shadow Tree, führt Piwigos Sync und Orphan-Sync aus und überträgt anschließend die Originalabmessungen aus dem WebDAV-Mapping in Piwigo. Eine vollständige Vorab-Erzeugung von Connector-Derivaten findet nicht statt; fehlende Derivate werden On-Demand erzeugt.

## Bildcache

Bratonien Tools kann Piwigo-Bildderivate gezielt leeren, vorhandene Bildgrößen neu erzeugen und den Cache-Aufbau als Worker-Prozess starten bzw. abbrechen. Originalbilder bleiben unangetastet.

## Wasserzeichenverwaltung

- eigene Wasserzeichendateien;
- Wasserzeichenprofile;
- globale Regeln für öffentliche/private Alben;
- Album-Ausnahmen und Vererbung;
- Position, Transparenz und Skalierung;
- eigener Runtime-Filter für Piwigo-Derivate;
- Sicherung der bisherigen Piwigo-Wasserzeichenkonfiguration beim Aktivieren.

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

Private Alben können über eigene Freigabelinks geteilt werden, optional mit Passwort und Ablaufdatum. Die Freigaben verwenden einen technischen Piwigo-Benutzer mit minimalem Albumzugriff und können widerrufen werden.

## Erweiterte Bildnavigation

Die Piwigo-Bilddetailseite erhält responsive Navigationszonen für vorheriges Bild, nächstes Bild, Rückkehr zur Übersicht und PhotoSwipe/Vollbild.

## Selbstaktualisierung

Der integrierte Updater liest den Zielstand aus GitHub, bindet ein Update an einen konkreten Commit, prüft Version und SHA-256, erstellt vor dem Austausch ein Backup und verlangt Webmaster-Rechte sowie die notwendigen Servervoraussetzungen.

## Wichtige Dateien und Verzeichnisse

- `main.inc.php` – Plugin-Einstieg, Hooks, Connector-Sync-Hilfen und Dashboard-Prefilter
- `admin.php` – zentraler Admin-Controller
- `include/nc_connector.inc.php` – WebDAV-Verbindungsmodell
- `include/nc_connector_wizard.inc.php` – Verbindungsassistent
- `include/nc_connector_wizard_webdav_flow.inc.php` – WebDAV-Wizardablauf
- `include/nc_connector_delete_safe.inc.php` – Löschen einschließlich WebDAV-Piwigo-Inhalten
- `include/nc_productive_ws.inc.php` – direkter Piwigo-Core-Dateisync für Piwigo 16.4.0
- `include/nc_orphan_ws.inc.php` – Orphan-Synchronisierung der konkreten WebDAV-Site
- `include/webdav_materialize_runtime.inc.php` – WebDAV-Quellauflösung und On-Demand-Materialisierung einschließlich `fileid` und Originalmaßen
- `include/webdav_image_runtime.inc.php` – vorhandene WebDAV-Bildzuordnung/Originalauslieferung
- `webdav-derivative.php` – On-Demand-Gate, Nextcloud-Preview/Original-Fallback und Piwigo-Derivaterzeugung
- `webdav-image.php` – serverseitige WebDAV-Originalauslieferung
- `runtime/reconcile-webdav.php` – WebDAV-Runtime-Reconcile, einschließlich gültigem leerem Root
- `runtime/repair-webdav-orphans.php` – Reparatur vorhandener Orphan-Zustände
- `runtime/cleanup-webdav-piwigo.php` – Bereinigung verwaister WebDAV-Piwigo-Sites
- `runtime/sync-webdav.sh` – Ablauf einer WebDAV-Verbindung
- `runtime/run-all.sh` – gemeinsamer WebDAV-Runner
- `runtime/lib/build_webdav_placeholder_source.py` – rekursiver WebDAV-Scan, Bildmetadaten und physische Platzhalterquelle
- `runtime/lib/sync-webdav-metadata.php` – Übernahme der Originalabmessungen in Piwigo
- `runtime/lib/shadow_tree.py` – atomarer, Piwigo-kompatibler Shadow Tree
- `runtime/lib/piwigo-sync.php` – Piwigo-Sync mit API/Fallback
- `main-cache-build.php` – allgemeiner Piwigo-Derivat-/Cache-Builder
- `include/self_update.inc.php` – Self-Updater
- `include/album_shares.inc.php` – geschützte Albumfreigaben und private Albumzugriffe
- `include/public_selection.inc.php` – Fotoauswahl
- `include/batch_titles.inc.php` – fortlaufende Titel
- `include/watermark_*.inc.php` – Wasserzeichen-Engine
- `tools/` – administrative Einzelwerkzeuge
- `template/`, `js/`, `css/` – Oberfläche

## Sicherheit

- administrative Schreibaktionen verwenden Piwigos CSRF-Schutz;
- Connector-Zugangsdaten werden verschlüsselt gespeichert;
- Nextcloud-Zugangsdaten werden verbindungseigen gespeichert und nur serverseitig verwendet;
- Wizard-Geheimnisse werden nicht im Browser-Web-Storage persistiert;
- produktive Piwigo-API ist versionsgebunden;
- Originalbilder werden vom Connector nicht gelöscht;
- WebDAV-Originalbilder werden nur bei Bedarf temporär lokal materialisiert;
- kleine Derivate können stattdessen eine temporäre authentifizierte Nextcloud-Preview verwenden;
- temporäre Preview-/Originalquellen werden nach der Piwigo-Derivaterzeugung wieder entfernt;
- Update-Pakete werden an Commit und Hash gebunden.
