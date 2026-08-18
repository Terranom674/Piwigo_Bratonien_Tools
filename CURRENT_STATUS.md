# Aktueller Entwicklungsstand

Stand: 18.08.2026

## Plugin

- Aktuelle Plugin-Version: **0.9.5.6**
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

### 0.9.5.6 – Verbindungsschicht begonnen

Der Parallelweg ist jetzt erstmals als eigener Connection-Typ im Plugin angelegt.

Neu:

- `include/nc_connector_webdav.inc.php`
- Backend-Aktion `nc_connector_create_webdav_parallel`
- neue Verbindungen dieses Typs erhalten `source_mode=webdav-placeholder`;
- sie werden als `adapter=remote` und zunächst **deaktiviert** gespeichert;
- bestehende Verbindungen werden dabei nicht verändert;
- ausgewählte WebDAV-Wurzeln werden verbindungseigen gespeichert;
- Nextcloud-Basis-URL und Benutzer werden verbindungseigen gespeichert.

Das Secret-Format wurde rückwärtskompatibel auf Version 3 erweitert um:

- `nextcloud_user`
- `nextcloud_password`

Bestehende v1/v2-Inhalte bleiben lesbar. Neue lokale Wizard-Verbindungen können die Nextcloud-Zugangsdaten ebenfalls mitführen, ohne ihren bisherigen Quellenmodus zu ändern.

Die verbindungseigene Fallback-Verwaltung wurde so angepasst, dass vorhandene WebDAV-Zugangsdaten bei späteren Secret-Änderungen erhalten bleiben. Für `webdav-placeholder` ist kein `db_password` erforderlich.

## Bestehender produktiver Weg

Unverändert:

- PostgreSQL-/View-/Storage-Mapping-Logik;
- lokale Symlinks auf bereits vorhandene Originalpfade;
- `runtime/reconcile.php` für bestehende lokale Adapter;
- `runtime/sync.sh` mit `legacy-view`, `user-shares` und `selected-fileids`;
- API-first-Piwigo-Sync;
- gemeinsame Runtime für aktive bestehende Verbindungen.

Die neue WebDAV-Verbindung wird aktuell absichtlich noch nicht von dieser Runtime aktiviert.

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

## Nächste Bauphase

Der neue Verbindungstyp existiert jetzt parallel. Als Nächstes wird ausschließlich seine Runtime gebaut, ohne die bestehenden drei Modi umzuschreiben.

Reihenfolge:

1. `runtime/reconcile.php` um einen getrennten Zweig für `adapter=remote` + `source_mode=webdav-placeholder` erweitern.
2. Für diesen Zweig **keine** PostgreSQL- und Storage-Mapping-Prüfung durchführen.
3. Nextcloud-Zugang aus dem verschlüsselten Connection-Secret in eine verbindungseigene, nur für die Runtime lesbare Credential-Datei überführen.
4. WebDAV-Wurzeln mit `webdav_path`, `display_name` und optionaler `fileid` als eigene Runtime-Konfiguration schreiben.
5. `runtime/sync.sh` um einen strikt getrennten `webdav-placeholder`-Zweig erweitern.
6. Dort `build_webdav_placeholder_source.py` aufrufen.
7. Das erzeugte Manifest durch den bestehenden `shadow_tree.py` schicken.
8. Erst dann eine **neue** kleine WebDAV-Testverbindung aktivieren und prüfen, ob Piwigo Albumstruktur und Bilddatensätze korrekt registriert.
9. Bestehende Verbindungen laufen während dieses Tests unverändert weiter.

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
