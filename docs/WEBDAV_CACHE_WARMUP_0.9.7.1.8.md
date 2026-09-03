# WebDAV-Cache-Warmup – Patch 0.9.7.1.8

## Ziel

Fehlende Piwigo-Derivate für neue Nextcloud-Inhalte im Hintergrund vorbereiten, ohne einen zweiten dauerhaften Bildbestand anzulegen und ohne Piwigo-Datenbankeinträge, Albumzuordnungen oder Bildpfade durch Bratonien Tools zu verändern.

## Zuständigkeiten

Bratonien Tools übernimmt ausschließlich Orchestrierung:

1. neue Alben bzw. neue/geänderte Bilder erkennen;
2. Originale in konfigurierbaren Batches temporär aus Nextcloud laden;
3. die bereits bestehende Piwigo-Quellzuordnung validieren;
4. die lokale Quelle für genau ein Bild atomar bereitstellen;
5. Piwigos eigenes `i.php` für die gewünschten, von Piwigo definierten Derivate aufrufen;
6. den ursprünglichen Placeholder exakt wiederherstellen und verifizieren;
7. temporäre Batch-Dateien löschen.

Piwigo bleibt allein zuständig für Derivatgrößen, Bildverarbeitung, Qualität, Format und Cache-Dateien.

## Trigger

- Neues Album: unmittelbar nach vollständig erfolgreichem Connector-Sync dieser Verbindung (`sync`-Modus).
- Einzelne neue/geänderte Bilder in bekannten Alben: periodische Eingangsprüfung, Standard 12 Stunden (`periodic`-Modus).
- Administration: `Jetzt auf neue Bilder prüfen` (`manual`-Modus).
- On-Demand bleibt parallel aktiv und wird nicht durch den Warmup ersetzt.

## Batches und Stufen

- Batchgröße ist konfigurierbar, Standard **10 Bilder**.
- Ein Batch wird zuerst vollständig aus Nextcloud geladen.
- Anschließend werden die Bilder einzeln am bereits bestehenden Piwigo-Pfad bereitgestellt und von Piwigo verarbeitet.
- Stufe 1: von Piwigo definierte Standard-/Custom-Derivate bis einschließlich 1920 px längster Kante.
- Erst wenn Stufe 1 abgearbeitet ist, folgt Stufe 2 mit den übrigen von Piwigo definierten Derivaten.

## Schutz produktiver Daten

Der Warmup darf keine Piwigo-Bild-/Albumdatensätze schreiben oder neue Piwigo-Sites/Zuordnungen anlegen.

Vor jedem Lauf wird die bestehende Pfadkette von Piwigo aus validiert:

`Piwigo image.id -> images.path -> realpath() -> nc-webdav-source/connection-ID/root-fileid -> webdav-map.json`

Unsichere oder widersprüchliche Zuordnungen führen zum Abbruch, nicht zu einer Rekonstruktion auf Verdacht.

Zusätzliche Sicherungen:

- vorhandener per-image Lock `upload/bratonien-webdav-materialize/image-ID.lock` wird auch vom Warmup benutzt;
- `webdav-sync.lock` wird für den gesamten Warmup-Lauf geteilt gehalten; der Connector-Sync benötigt denselben Lock exklusiv;
- zwei Piwigo-IDs auf derselben physischen Connector-Quelle führen zum Sicherheitsabbruch;
- Placeholder wird nie in-place überschrieben;
- Swap erfolgt über Dateien im selben Verzeichnis und `rename()`;
- Größe und SHA-1 des Placeholders werden vor dem Swap erfasst;
- nach jedem Restore müssen Größe und SHA-1 exakt dem Ausgangszustand entsprechen;
- Restore-Fehler beendet den Worker sofort als fatal;
- bestehende produktive Daten werden beim ersten Lauf nur als Baseline erfasst und nicht automatisch neu aufgebaut.

## Patchphase

Die automatische Warmup-Funktion ist in 0.9.7.1.8 standardmäßig **deaktiviert**. Vor Aktivierung sind vorgesehen:

1. Plugin aktualisieren;
2. schreibgeschützten `Produktive Pfade prüfen`-Audit ausführen;
3. ersten manuellen Lauf durchführen, der den vorhandenen Bestand nur als Baseline erfasst;
4. gezielten Test mit neuem Testalbum / neuen Bildern;
5. erst nach bestätigtem Restore- und Piwigo-Cache-Verhalten Automatik aktivieren.

Die Entwicklung bleibt bis zum vollständig validierten Endstand in Patch-Versionen. Die geplante Finalversion ist **0.10.1**.
