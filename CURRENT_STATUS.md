# Aktueller Entwicklungsstand

Stand: 18.08.2026

## Plugin

- Aktuelle Plugin-Version: **0.9.5.7**
- Aktueller Entwicklungsblock: **NC Connector – WebDAV-basierter Parallelweg bei vollständigem Erhalt des bestehenden produktiven Wegs**
- GitHub ist das führende Repository.
- Das private Gitea-System bleibt Mirror/Fallback.

## Nicht verhandelbare Migrationsregel

Der bestehende lokale NC Connector bleibt vollständig erhalten, bis der neue WebDAV-Weg End-to-End funktioniert.

Bestehende Quellenmodi:

- `legacy-view`
- `user-shares`
- `selected-fileids`

Sie werden nicht automatisch migriert, deaktiviert oder auf den neuen Weg umgestellt.

Der neue Weg entsteht zusätzlich als eigener Modus:

- `webdav-placeholder`

## Ziel des neuen Wegs

Benötigt werden sollen nur:

- normale Piwigo-Installation;
- bereits vorhandene Linux-/PHP-Umgebung;
- Nextcloud-Adresse;
- Nextcloud-Benutzer bzw. App-Passwort;
- WebDAV-Zugriff auf die Inhalte dieses Benutzers.

Nicht vorausgesetzt werden dürfen:

- PostgreSQL-Zugriff auf Nextcloud;
- `occ`-Adminzugriff;
- Rootzugriff des Betreibers;
- Storage-IDs oder Backend-Pfade;
- zusätzliche Host-Mounts;
- FUSE;
- davfs;
- rclone;
- zusätzliche Connector-Systempakete.

Originalbilder werden nicht dauerhaft nach Piwigo kopiert.

## Architektur des WebDAV-Parallelwegs

1. Ausgewählte Nextcloud-Verzeichnisse werden über WebDAV/PROPFIND gelesen.
2. Das Plugin erfasst Ordner, Dateinamen, Datei-ID, MIME-Typ, Größe, ETag und WebDAV-Pfad.
3. Für jedes Bild wird nur ein winziger lokaler Platzhalter bereitgestellt.
4. Der bestehende Shadow Tree bleibt die physische Piwigo-Quelle.
5. Der Shadow Tree bildet die reale Ordner- und Dateinamensstruktur ab, seine Bildziele zeigen jedoch auf Platzhalter statt auf Originale.
6. Ein separates Mapping verbindet Shadow-Tree-Pfad, Connection-ID, Nextcloud-Datei-ID und WebDAV-Pfad.
7. Piwigo soll Album und Bild über diese Struktur registrieren.
8. Wenn echte Bilddaten benötigt werden, wird das Original bei Bedarf über WebDAV gelesen.
9. Piwigo-Derivate werden normal lokal unter `_data/i/` gecacht.
10. Das Original bleibt ausschließlich in Nextcloud.

## Bereits vorhandene Bausteine

### `runtime/lib/build_webdav_placeholder_source.py`

- liest WebDAV rekursiv;
- lädt keine Originalbilder herunter;
- erzeugt eine lokale Platzhalterquelle;
- erzeugt Manifest und WebDAV-Mapping;
- verwendet einen nur wenige Dutzend Byte großen Platzhalter.

### 0.9.5.6 – Verbindungsschicht

Der Parallelweg wurde als eigener Connection-Typ im Plugin angelegt.

- `include/nc_connector_webdav.inc.php`
- Backend-Aktion `nc_connector_create_webdav_parallel`
- `source_mode=webdav-placeholder`
- `adapter=remote`
- ausgewählte WebDAV-Wurzeln werden verbindungseigen gespeichert;
- Nextcloud-Basis-URL und Benutzer werden verbindungseigen gespeichert;
- Secret v3 enthält `nextcloud_user` und `nextcloud_password` rückwärtskompatibel zu älteren Secrets.

## 0.9.5.7 – parallele Runtime angelegt

Der neue Weg besitzt jetzt eine eigene Runtime neben dem bestehenden produktiven Connector.

Neu:

- `runtime/reconcile-webdav.php`
- `runtime/sync-webdav.sh`
- `runtime/run-all.sh` verarbeitet lokale und WebDAV-Verbindungen getrennt.

Wesentliche Regeln:

- `runtime/reconcile.php` für die bestehenden lokalen Modi bleibt unverändert.
- `runtime/sync.sh` für die bestehenden lokalen Modi bleibt unverändert.
- WebDAV-Verbindungen erhalten eigene Runtime-Dateien `webdav-connection-ID.*`.
- Es werden keine PostgreSQL-/View-/Storage-Mapping-Werte für WebDAV verlangt.
- Nextcloud-Zugang wird aus dem verschlüsselten Connection-Secret in eine Runtime-Passwortdatei mit restriktiven Rechten geschrieben.
- WebDAV-Wurzeln werden getrennt als Runtime-Konfiguration gespeichert.
- Der Platzhalter-Builder erzeugt die lokale Platzhalterquelle, Manifest und WebDAV-Mapping.
- Das Manifest wird anschließend durch den bestehenden `shadow_tree.py` verarbeitet.
- Der parallele Shadow Tree liegt absichtlich unter `galleries/bratonien-webdav-ID` und kann deshalb den bestehenden Galeriebaum nicht ersetzen oder überschreiben.
- Die Platzhalterquelle liegt unter Piwigo `_data/bratonien-tools/nc-webdav-source/connection-ID`, damit Piwigo den Symlink-Zielen später folgen kann.
- **Piwigo-Synchronisierung ist in dieser Stufe hart auf `PIWIGO_SYNC_ENABLED=0` gesetzt.** Die Runtime darf aktuell nur den parallelen Shadow Tree bauen. Die Registrierung in Piwigo wird erst nach Sichtprüfung bewusst freigeschaltet.

Damit existieren lokaler Produktivweg und WebDAV-Testweg jetzt gleichzeitig, ohne dass ein Umzug stattgefunden hat.

## Bestehender produktiver Weg

Unverändert:

- PostgreSQL-/View-/Storage-Mapping-Logik;
- lokale Symlinks auf bereits vorhandene Originalpfade;
- `runtime/reconcile.php` für bestehende lokale Adapter;
- `runtime/sync.sh` mit `legacy-view`, `user-shares` und `selected-fileids`;
- API-first-Piwigo-Sync;
- gemeinsame Runtime für aktive bestehende Verbindungen.

## Gemessene WebDAV-Performance

Testbild: 16.091.204 Byte.

Intern:

- 1,829 s
- 8.796.915 Byte/s

Extern:

- 1,865 s
- 8.627.524 Byte/s

Die externe Verbindung war im Test nur ungefähr 2 % langsamer. Der Ansatz bleibt deshalb für bedarfsweisen Originalzugriff geeignet, solange Piwigo-Derivate lokal gecacht werden.

## Löschverhalten seit 0.9.5.5

Das Löschen einer Connector-Verbindung darf nicht an Dateirechten Root-eigener Runtime-Dateien scheitern.

- Datenbankeintrag wird entfernt;
- verwaiste Runtime-Dateien werden vor einem späteren Sync bereinigt;
- andere Verbindungen bleiben unangetastet;
- Nextcloud-Originale und vorhandene Piwigo-Bilder werden nicht gelöscht.

## Nächster Testschritt

Die parallele Runtime ist jetzt vorbereitet. Der nächste Schritt ist bewusst klein und kontrolliert:

1. Plugin auf 0.9.5.7 aktualisieren.
2. Eine kleine neue WebDAV-Testverbindung mit einem überschaubaren Verzeichnis anlegen.
3. Gemeinsamen Runner einmal ausführen lassen.
4. Prüfen, ob unter `galleries/bratonien-webdav-ID` ausschließlich die erwartete Ordnerstruktur und Platzhalter-Symlinks entstehen.
5. WebDAV-Mapping und Manifest im Connection-State prüfen.
6. Bestehende produktive Galerie und bestehende Verbindungen dabei auf Unverändertheit prüfen.

Erst wenn dieser Test sauber ist, wird `PIWIGO_SYNC_ENABLED` für WebDAV separat freigeschaltet und die Piwigo-Registrierung getestet.

## Danach – noch nicht umsetzen

Erst wenn die Registrierung mit Platzhaltern funktioniert:

- echte Bilddaten über WebDAV anfordern;
- Piwigo-Derivate aus echten Originalen erzeugen;
- normalen Derivat-Cache weiterverwenden;
- ETag-basierte Änderungserkennung ergänzen;
- Fehlerfälle und Parallelität testen.

## Voraussetzung vor irgendeinem Umzug

Erfolgreich getestet sein müssen mindestens:

- Verbindungsanlage;
- WebDAV-Verzeichnisauswahl;
- Shadow Tree;
- Piwigo-Registrierung;
- echte Bildausgabe;
- Derivat-Cache;
- Änderungserkennung;
- Löschen/Deaktivieren;
- Fehlerbehandlung;
- Verhalten bei vielen noch ungecachten Bildern.

Erst danach kann eine freiwillige Migration bestehender Verbindungen überhaupt diskutiert werden.
