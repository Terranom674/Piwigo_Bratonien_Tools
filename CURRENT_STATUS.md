# Aktueller Entwicklungsstand

Stand: 18.08.2026

## Plugin

- Aktuelle Plugin-Version: **0.9.5.5**
- Aktueller Entwicklungsblock: **NC Connector – WebDAV-basierter Quellenweg bei vollständigem Erhalt des bestehenden produktiven Wegs**
- GitHub ist das führende Repository.
- Das private Gitea-System bleibt Mirror/Fallback.

## Produktiver Stand

Der bestehende lokale NC Connector bleibt vollständig erhalten und ist weiterhin der produktive Referenzweg.

Aktuell vorhandene Quellenmodi:

- `legacy-view`
- `user-shares`
- `selected-fileids`

Diese Modi dürfen durch die neue WebDAV-Entwicklung weder automatisch migriert noch deaktiviert, überschrieben oder in ihrer Laufzeitlogik verändert werden.

Der bestehende Connector kann weiterhin:

- Nextcloud-Quellen in einen Shadow Tree überführen;
- Ordner als physische Piwigo-Alben synchronisieren;
- Root-Dateien als Orphans registrieren;
- entfernte Quellen aus Piwigo entfernen, ohne Nextcloud-Originale zu löschen;
- neue Connector-Alben privat anlegen;
- Piwigo API-first synchronisieren und bei Bedarf auf einen verbindungseigenen Login-Fallback zurückfallen;
- mehrere aktive Verbindungen über die gemeinsame Runtime verarbeiten.

## 0.9.5.4

- Neuer experimenteller Builder `runtime/lib/build_webdav_placeholder_source.py` hinzugefügt.
- Der Builder liest eine Nextcloud-Quelle rekursiv über WebDAV und erzeugt eine lokale Platzhalterstruktur sowie ein Mapping.
- Es werden keine Originalbilder heruntergeladen.
- Platzhalter sind extrem klein; der derzeitige Builder verwendet ein 1x1-GIF mit wenigen Dutzend Byte.
- Der bestehende produktive Sync wurde dafür nicht umgeschaltet.

## 0.9.5.5

Das Löschen von Connector-Verbindungen wurde gegen unterschiedliche Dateibesitzer zwischen Webserver und Runtime abgesichert.

Vorher konnte das Löschen scheitern, wenn PHP keinen Tombstone im Connector-Statusverzeichnis anlegen durfte. Jetzt gilt:

- das Löschen der Verbindung darf nicht von Schreibrechten auf Runtime-Dateien abhängen;
- die Verbindung wird aus der Piwigo-Datenbank entfernt;
- verwaiste Runtime-Dateien werden beim nächsten Connector-Lauf vor der Synchronisierung bereinigt;
- andere bestehende Verbindungen bleiben unangetastet.

## Architektur – unverändert produktiver Weg

- Nextcloud ist Quelle der Originalbilder.
- Piwigo verwendet einen Shadow Tree unter dem Galeriepfad.
- Der bisherige Weg arbeitet mit lokalen, bereits vorhandenen Speicherpfaden und Symlinks auf die Originale.
- PostgreSQL-/View-/Storage-Mapping-Logik bleibt für bestehende Verbindungen erhalten.
- Runtime-Konfigurationen: `/etc/bratonien-tools/nc-connector/connection-*.conf`
- State: `/var/lib/bratonien-tools/nc-connector/connection-ID`
- Öffentlicher Admin-Status: Piwigo `_data/bratonien-tools/nc-connector-status/`
- Runner: `runtime/run-all.sh`
- Einzelverbindung: `runtime/sync.sh`
- Shadow Tree: `runtime/lib/shadow_tree.py`

## Neuer WebDAV-Weg – Zielbild

Der neue Weg soll als zusätzlicher eigener Quellenmodus entstehen, zum Beispiel:

- `webdav-placeholder`

Er wird ausdrücklich **neben** den bestehenden Modi entwickelt.

### Rahmenbedingungen

Der neue Weg darf nur Dinge voraussetzen, die bei einer normalen Piwigo-Installation und der bereits erforderlichen Linux-/PHP-Umgebung vorhanden sind.

Nicht als Voraussetzung zulässig:

- Rootzugriff des Betreibers;
- zusätzliche Host-Mounts;
- FUSE;
- davfs;
- rclone;
- zusätzliche Systempakete nur für den Connector;
- PostgreSQL-Zugriff auf die Nextcloud-Datenbank;
- `occ`-Adminzugriff;
- Wissen über Nextcloud-Storage-IDs oder Backend-Pfade.

Benötigt werden sollen nur:

- Nextcloud-Adresse;
- normaler Nextcloud-Benutzer bzw. App-Passwort;
- WebDAV-Zugriff auf genau die Inhalte, die dieser Benutzer sehen darf;
- Piwigo selbst und das Plugin.

### Grundidee

WebDAV wird nicht in einen Linux-Dateipfad umgewandelt.

Stattdessen:

1. Das Plugin liest die ausgewählten Nextcloud-Verzeichnisse per WebDAV/PROPFIND.
2. Es erfasst Ordner, Dateinamen, Datei-ID, MIME-Typ, Größe, ETag und WebDAV-Pfad.
3. Für jedes Bild wird nur ein winziger lokaler Platzhalter bereitgestellt.
4. Der Shadow Tree behält seine Rolle als Piwigo-Quelle und enthält die reale Ordner-/Dateinamensstruktur.
5. Die Shadow-Tree-Einträge verweisen auf Platzhalter statt auf das Nextcloud-Original.
6. Ein separates Mapping verknüpft Shadow-Tree-Pfad mit Nextcloud-Verbindung, Datei-ID und WebDAV-Pfad.
7. Piwigo soll die Bilder dadurch zunächst als vorhandene physische Einträge erkennen können.
8. Für echte Bilddaten muss das Plugin bei Bedarf das Original über WebDAV lesen.

### Anzeige und Derivate

Ziel ist nicht, Originale dauerhaft nach Piwigo zu kopieren.

Vorgesehener Ablauf:

- Piwigo kennt Album und Bild über Shadow Tree/Platzhalter.
- Wenn ein Piwigo-Derivat noch fehlt, wird das Original einmal über WebDAV gelesen.
- Piwigo erzeugt daraus sein normales lokales Derivat in `_data/i/`.
- Weitere Aufrufe verwenden den vorhandenen Piwigo-Derivat-Cache.
- Das Nextcloud-Original bleibt ausschließlich in Nextcloud.
- Beim tatsächlichen Abruf eines Originals muss die Quelle erneut über WebDAV gelesen werden.

## Gemessene WebDAV-Performance

Realer Test vom Piwigo-System auf ein Nextcloud-Bild mit 16.091.204 Byte:

Interne Nextcloud-Adresse:

- Zeit: **1,829 s**
- Geschwindigkeit: **8.796.915 Byte/s**

Externe Nextcloud-Adresse:

- Zeit: **1,865 s**
- Geschwindigkeit: **8.627.524 Byte/s**

Die externe Verbindung war damit in diesem Test nur ungefähr 2 % langsamer als die interne Verbindung.

Folgerung für den Plan:

- WebDAV ist für bedarfsweisen Zugriff auf Originale performant genug, um den Ansatz weiterzuverfolgen.
- Entscheidend ist, dass Piwigo-Derivate normal lokal gecacht werden und WebDAV nicht bei jedem Thumbnail-Aufruf erneut angesprochen wird.

## Bereits vorhandener WebDAV-Baustein

`runtime/lib/build_webdav_placeholder_source.py`

Aufgabe:

- WebDAV rekursiv lesen;
- keine Originalbilder herunterladen;
- lokale Platzhalterquelle erzeugen;
- Manifest und WebDAV-Mapping erzeugen.

Dieser Builder ist aktuell nur ein Baustein. Er ist noch nicht als vollständiger Connection-Modus in Wizard, Secret-Speicherung, Reconcile und Runtime integriert.

## Noch notwendige Umsetzung für `webdav-placeholder`

### 1. Verbindungstyp rückwärtskompatibel ergänzen

- neuer eigener `SOURCE_MODE`;
- bestehende drei Modi bleiben unverändert;
- keine automatische Migration alter Verbindungen.

### 2. Nextcloud-Zugang verbindungseigen speichern

Der aktuelle Wizard benutzt Nextcloud-Zugangsdaten bereits für WebDAV-Verzeichnisabfragen, speichert sie aber nicht in einer Form, die die spätere Runtime für WebDAV verwenden kann.

Das Secret-Format muss rückwärtskompatibel erweitert werden um:

- `nextcloud_user`
- `nextcloud_password`

Bestehende Secrets müssen weiterhin lesbar bleiben.

### 3. Wizard-Abschluss für WebDAV-Verbindungen

Eine neue WebDAV-Verbindung darf keine PostgreSQL- oder Storage-Mount-Pflicht haben.

Gespeichert werden müssen mindestens:

- Connection-Name;
- Nextcloud-Basis-URL;
- Nextcloud-Benutzer;
- verschlüsseltes Nextcloud-Passwort/App-Passwort;
- ausgewählte WebDAV-Wurzeln;
- Piwigo-Galeriepfad;
- Piwigo-Authentifizierung für den Sync, sofern benötigt;
- `source_mode=webdav-placeholder`.

### 4. Reconcile und Runtime

`runtime/reconcile.php` muss WebDAV-Verbindungen gesondert behandeln:

- keine PostgreSQL-Konfiguration verlangen;
- keine Storage-Mappings verlangen;
- WebDAV-Zugang sicher in eine verbindungseigene Runtime-Datei überführen;
- bestehende lokale Verbindungstypen unverändert lassen.

`runtime/sync.sh` muss einen eigenen Zweig für `webdav-placeholder` erhalten.

### 5. Shadow Tree

Der bestehende Shadow Tree bleibt erhalten.

Der neue Builder muss nur eine Quelle erzeugen, die vom bestehenden `shadow_tree.py` verarbeitet werden kann. Ein kompletter Umbau des Shadow Trees ist nicht vorgesehen.

### 6. Piwigo-Erkennung testen

Erster PoC:

- kleine neue WebDAV-Testverbindung anlegen;
- einen kleinen ausgewählten Ordner verwenden;
- Platzhalterquelle bauen;
- bestehenden Shadow Tree erzeugen;
- Piwigo synchronisieren;
- prüfen, ob Albumstruktur und Bilder korrekt registriert werden.

Der bestehende produktive Connector bleibt währenddessen aktiv und unverändert.

### 7. Echte Bilddaten statt Platzhalter ausliefern

Erst wenn Piwigo die Platzhalterstruktur korrekt registriert, wird der zweite Teil umgesetzt:

- Piwigo-Anforderung einem WebDAV-Mapping zuordnen;
- echtes Original bei Bedarf über WebDAV lesen;
- Piwigo-Derivate aus der echten Quelle erzeugen;
- normale Piwigo-Derivate weiterverwenden;
- keine dauerhafte Originalkopie in Piwigo.

### 8. Lastverhalten absichern

Vor einer produktiven Umstellung muss geprüft werden:

- Verhalten bei vielen gleichzeitig noch ungecachten Bildern;
- parallele WebDAV-Abfragen;
- Derivat-Erzeugung;
- Fehlerfall bei nicht erreichbarer Nextcloud;
- geänderte oder gelöschte Remote-Dateien;
- Cache-Invalidierung bei geändertem ETag.

## Migrationsregel

Der bisherige Weg bleibt so lange vollständig erhalten, bis der WebDAV-Weg funktional vollständig ist.

Es gibt bis dahin keine automatische Migration bestehender Verbindungen.

Erst nach erfolgreichem End-to-End-Test von:

- Verbindungsanlage;
- Verzeichnisauswahl;
- Shadow Tree;
- Piwigo-Registrierung;
- echter Bildausgabe;
- Derivat-Cache;
- Änderungserkennung;
- Löschen/Deaktivieren;
- Fehlerbehandlung

kann über eine freiwillige Migration bestehender Verbindungen entschieden werden.

## Sicherheit und Datenhaltung

- Nextcloud-Originale werden nicht gelöscht.
- Nextcloud-Originale werden im neuen WebDAV-Weg nicht dauerhaft nach Piwigo kopiert.
- Lokale Piwigo-Derivate sind ausdrücklich erlaubt und Teil des normalen Piwigo-Caches.
- Connector-Zugangsdaten werden verschlüsselt gespeichert.
- Wizard-Secrets bleiben serverseitig.
- Bestehende Verbindungen bleiben während der Entwicklung unangetastet.
- Mutierende Admin-Aktionen verwenden Post/Redirect/Get.
- Self-Updates sind an einen konkreten Commit und SHA-256 gebunden.

## Nächster konkreter Entwicklungsschritt

Rückwärtskompatible Verbindungsschicht für `webdav-placeholder` bauen:

1. Secret-Format um verbindungseigenen Nextcloud-Zugang erweitern.
2. neuen Source-Modus in Datenmodell/Reconcile/Runtime ergänzen.
3. Wizard so erweitern, dass eine WebDAV-Verbindung ohne PostgreSQL und ohne Storage-Mapping abgeschlossen werden kann.
4. danach eine neue Testverbindung anlegen und den Platzhalter-PoC durch den bestehenden Shadow Tree und Piwigo-Sync schicken.
