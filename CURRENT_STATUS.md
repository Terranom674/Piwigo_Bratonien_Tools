# Aktueller Entwicklungsstand

Stand: 19.08.2026

## Plugin

- Aktuelle Plugin-Version: **0.9.6.1**
- Aktueller Entwicklungsblock: **NC Connector – WebDAV-End-to-End-Pfad mit Piwigo-Registrierung, Preview-/Derivatstrecke und sauberem Verbindungs-Lebenszyklus**
- GitHub ist das führende Repository.
- Der bestehende lokale Connector bleibt vollständig erhalten und wird nicht automatisch migriert.

## Nicht verhandelbare Architekturregeln

- Nextcloud bleibt die Quelle der Originalbilder.
- Originalbilder werden **nicht dauerhaft nach Piwigo kopiert**.
- Der bestehende lokale Produktionsweg bleibt funktionsfähig.
- WebDAV benötigt nur Nextcloud-Adresse, Benutzer/App-Passwort und normale WebDAV-Berechtigungen des Benutzers.
- Kein Nextcloud-PostgreSQL-Zugriff, kein `occ`, keine Storage-IDs, keine Host-Mounts, kein FUSE, davfs oder rclone als Voraussetzung.
- WebDAV-Verbindungen werden voneinander isoliert verarbeitet.
- Fehler der WebDAV-Bildverarbeitung dürfen keinen normalen Piwigo-Seitenaufruf zum Absturz bringen.

## Bestehende Quellenmodi

Weiterhin vorhanden:

- `legacy-view`
- `user-shares`
- `selected-fileids`

Neuer WebDAV-Modus:

- `webdav-placeholder`

Es gibt keine automatische Migration bestehender Verbindungen auf WebDAV.

## WebDAV-Ablauf in 0.9.6.1

1. Der Assistent prüft Nextcloud über WebDAV und lässt sichtbare Verzeichnisse auswählen.
2. `runtime/reconcile-webdav.php` erzeugt die verbindungseigene Runtime-Konfiguration.
3. `runtime/lib/build_webdav_placeholder_source.py` liest die ausgewählten Verzeichnisse rekursiv per PROPFIND.
4. Ordner, Dateinamen, Nextcloud-Datei-ID, MIME-Typ, Größe, ETag und WebDAV-Pfad werden erfasst.
5. Eine lokale Platzhalterquelle wird erzeugt, ohne Originalbilder dauerhaft zu speichern.
6. `runtime/lib/shadow_tree.py` baut daraus den verbindungseigenen Shadow Tree.
7. Der WebDAV-Galeriebaum liegt unter `_data/bratonien-tools/nc-webdav-gallery/connection-ID` und wird als eigene physische Piwigo-Site registriert.
8. Dadurch erscheint die ausgewählte Nextcloud-Wurzel als normales Piwigo-Album; technische `bratonien-webdav-ID`-Wrapper werden nicht angezeigt.
9. Vorbereitete Arbeitsbilder liegen unter `_data/bratonien-tools/nc-webdav-preview/connection-ID`.
10. Arbeitsbilder werden als JPEG beziehungsweise PNG erzeugt und maximal auf 4096 px Kantenlänge begrenzt, damit Piwigos Bildbibliothek sie zuverlässig verarbeiten kann.
11. Der CLI-Derivat-Builder korrigiert die Piwigo-Bildabmessungen anhand des Arbeitsbilds und erzeugt die aktuell konfigurierten Standard- und Custom-Derivate.
12. Der normale Frontend-Aufruf erzeugt **keine** Derivate. Fehlt ein Derivat, wird das vorbereitete Bild als sicherer Fallback ausgeliefert.
13. Originalbilder werden bei echtem Originalabruf serverseitig über `webdav-image.php` aus Nextcloud gestreamt; Nextcloud-Zugangsdaten erscheinen nicht im Browser.

## Piwigo-Synchronisierung

WebDAV verwendet denselben Piwigo-Synchronisationspfad wie der bestehende Connector, aber mit einer verbindungseigenen physischen Site.

Nach der Dateisynchronisierung laufen die vorhandenen Piwigo-Nacharbeiten, darunter Metadaten-, Integritäts-, Kategorie-, Pfad-, Rang- und Cache-Aktualisierungen sowie der Orphan-Abgleich.

Die direkte produktive Synchronisierung ist derzeit ausdrücklich auf **Piwigo 16.4.0** abgestimmt.

## WebDAV-Bildausgabe und Derivate

Wichtige Dateien:

- `include/webdav_image_runtime.inc.php` – Zuordnung Piwigo-Bild ↔ WebDAV-Quelle, Preview-/Derivat-Helfer und URL-Filter.
- `webdav-image.php` – berechtigungsgeprüfter serverseitiger Original-/Preview-Abruf.
- `runtime/lib/precache-webdav-previews.php` – vorbereitete JPEG-/PNG-Arbeitsbilder ohne dauerhafte Originalkopie.
- `runtime/lib/build-webdav-derivatives.php` – CLI-Aufbau der konfigurierten Piwigo-Derivate.

Der Frontend-Hook führt keine Bildgenerierung mehr aus. Damit bleibt ein Fehler in GD/Imagick/External ImageMagick auf den Hintergrundlauf begrenzt.

## Löschen einer WebDAV-Verbindung seit 0.9.6.1

Eine gelöschte WebDAV-Verbindung darf keine weiterhin sichtbaren oder über alte Piwigo-Datensätze erreichbaren Bilder hinterlassen.

Beim Löschen über die Oberfläche:

- werden die zur Verbindung gehörende Piwigo-Site, ihre Alben und Bilddatensätze entfernt;
- werden zugehörige Piwigo-Derivate entfernt;
- bleiben die Nextcloud-Originaldateien unangetastet;
- werden Laufzeit- und Preview-Daten anschließend bereinigt.

Zusätzlich läuft `runtime/cleanup-webdav-piwigo.php` im gemeinsamen Runner. Er erkennt verwaiste WebDAV-Piwigo-Sites, für die keine Connector-Verbindung mehr existiert, und entfernt deren Piwigo-Inhalte sowie Gallery-, Source-, Preview-, State-, Status- und Runtime-Reste. Damit werden auch bereits vor 0.9.6.1 gelöschte Verbindungen nachträglich bereinigt.

## Gemeinsamer Runner

`runtime/run-all.sh` verarbeitet die Schritte in kontrollierter Reihenfolge:

1. lokale Verbindungen reconciliieren;
2. WebDAV-Verbindungen reconciliieren;
3. verwaiste WebDAV-Piwigo-Inhalte bereinigen;
4. verwaiste allgemeine Runtime-Dateien bereinigen;
5. aktive WebDAV-Verbindungen synchronisieren;
6. aktive lokale Verbindungen synchronisieren.

Der vorhandene systemd-Timer ruft direkt die Runtime aus dem installierten Plugin auf.

## Status- und UI-Regeln

- Hintergrund-Polling lädt die Admin-Seite nicht neu.
- Ein geöffneter Verbindungsassistent bleibt bei Statusaktualisierungen geöffnet.
- Fehlgeschlagene Fallback-/Credential-Prüfungen schließen den Assistenten nicht.
- Laufzeitfehler unterscheiden WebDAV-Lesen, Piwigo-Synchronisierung, Preview- und Derivatfehler.

## Sicherheit

- Connector-Zugangsdaten werden verschlüsselt gespeichert.
- WebDAV-Credentials werden nur serverseitig verwendet.
- Bildzugriffe über `webdav-image.php` werden gegen Piwigos Zugriffsrechte geprüft.
- Nextcloud-Originale werden weder beim Sync noch beim Löschen einer Verbindung verändert.
- Derivate und Arbeitsbilder sind lokale Cache-/Arbeitsdaten und dürfen entfernt beziehungsweise neu aufgebaut werden.

## Aktueller Prüfstand

Bereits umgesetzt:

- WebDAV-Verbindungsassistent;
- Verzeichnisauswahl;
- rekursiver WebDAV-Scan;
- Shadow Tree;
- separate Piwigo-Site je WebDAV-Verbindung;
- sichtbare Albumstruktur ohne technische Wrapper;
- echte Bildausgabe aus Nextcloud;
- lokale vorbereitete Arbeitsbilder;
- CLI-Derivatstrecke;
- Piwigo-Nacharbeiten und Orphan-Abgleich;
- ETag-basierte Wiederverwendung vorbereiteter Bilder;
- Löschen einer Verbindung einschließlich Piwigo-Inhalten;
- nachträgliche Bereinigung bereits verwaister WebDAV-Piwigo-Sites.

Weiter zu prüfen beziehungsweise unter realer Last zu härten:

- vollständiger Derivataufbau für alle in der Installation vorkommenden Standard- und Custom-Größen;
- Verhalten bei großen Bildmengen und vielen gleichzeitig noch nicht vorbereiteten Bildern;
- Fehlerfälle verschiedener Bildformate und Bild-Backends;
- langfristige Änderungserkennung und Performance im produktiven Einsatz.

## Versionsregel

Die aktuelle Versionslinie ist **0.9.6.x**. Weitere Sprünge auf eine neue Minor-/Major-Linie erfolgen nur bewusst und ausdrücklich.
