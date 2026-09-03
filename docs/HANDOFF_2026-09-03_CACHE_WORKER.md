# HANDOFF – Piwigo / WebDAV Cache-Worker – 2026-09-03

## Warum hier gestoppt wurde

Die aktuelle Cache-Worker-Entwicklung ist in einen Architektur-/Debugging-Kreis geraten. Der beobachtete produktive Zustand ist eindeutig falsch: Nach dem Leeren des Bildcaches meldet der manuelle Cache-Aufbau praktisch sofort "fertig", obwohl der eigentliche Piwigo-Bildcache nicht aufgebaut wurde. Weitere Patchversuche an Anzeige, Statusaggregation oder Legacy-Worker-Zählung würden den Fehler nur weiter überdecken.

Ab hier **nicht weiterpatchen**, bevor der reale Ausführungspfad von Button bis Piwigo-Derivaterzeugung vollständig nachvollzogen ist.

## Gesicherte Architekturvorgaben

- Nextcloud ist die autoritative Quelle der Originalbilder.
- Piwigo arbeitet produktiv mit einem Shadow-Tree und 1x1-Placeholdern.
- Der Worker darf Piwigo nicht nachbauen und selbst keine Derivate rendern.
- Richtiger Ablauf: Worker erkennt Arbeit -> Original temporär materialisieren -> Piwigo nativen Derivatpfad aufrufen -> Ergebnis prüfen -> Original wieder entfernen / Placeholder restaurieren.
- Piwigo allein bestimmt Dimensionen, Crop, Sharpening, Wasserzeichen, Qualität, Format und Cachepfad.
- On-Demand bleibt Fallback.
- Materialisierung erfolgt batchweise, Standard 10 Originale pro Batch.
- Stage 1 vor Stage 2; neue Sync-Arbeit darf Stage 2 zwischen Batches priorisieren.
- Connector/Shadow-Tree bleibt Wahrheit darüber, welche Quellen existieren.

## Neuer Quellenindex – Konzept weiterhin sinnvoll

Die Trennung des Worker-Entscheidungszustands vom Piwigo-Cache ist weiterhin sinnvoll:

- eigener kleiner Quellenindex je WebDAV-Verbindung
- Identität primär über `fileid`, Fallback Root/Pfad
- Vergleich über `etag`, Dateigröße, MIME/Format, Pfad, Breite/Höhe
- Quelle neu/geändert -> Worker darf Piwigo beauftragen
- Quelle unverändert -> normaler periodischer Lauf tut nichts
- gelöschte Quelle -> beim Abgleich aus Worker-Index entfernen; eigentliche Quellenentfernung bleibt Connector/Shadow-Tree-Aufgabe
- manueller Full-Rebuild darf den Quellenindex bewusst ignorieren und alle aktuellen Quellen erneut an Piwigo reichen

Datei dafür aktuell:
`include/webdav_source_index.inc.php`

## Aktueller problematischer Stand

### Version

Aktuell zuletzt auf `0.9.7.1.16` erhöht.

### Relevante Dateien

- `runtime/lib/webdav-cache-warmup.php`
- `runtime/webdav-warmup-dispatch.php`
- `include/webdav_source_index.inc.php`
- `include/webdav_warmup_settings.inc.php`
- `main-cache-build.php`
- `main-cache-status.php`
- `tools/image_cache.inc.php`
- `template/admin.tpl`
- `include/tool_registry.inc.php`

### Letzte Architekturänderungen

1. Legacy `main-cache-build.php` zählt WebDAV-Bilder nicht mehr als normale lokale Cachearbeit.
2. Eigener WebDAV-Quellenindex wurde eingeführt.
3. Warmup-Auswahl wurde auf diesen Quellenindex umgestellt.
4. Kombinierter Admin-Button startet lokalen Piwigo-Teil plus WebDAV-Rebuild.
5. Statusanzeige wurde zusammengeführt.
6. Bei reiner WebDAV-Installation sollte der lokale Legacy-Worker gar nicht mehr gestartet werden.

Trotzdem beobachtet der Nutzer nach Cache-Leeren weiterhin einen praktisch sofort fertigen Aufbau. Das beweist: **Der tatsächliche WebDAV-Rebuild-Pfad erzeugt die erwarteten Piwigo-Derivate nicht bzw. läuft nicht so, wie die Architektur es annimmt.**

## Wichtige letzte Commits

- `ac6353b050cdf30db79ab20716e66e6a6c841abb` – eigener WebDAV-Quellenindex
- `6783974c7dfab9d11a0f1e43419de84e92141fd5` – Warmup auf Quellenindex umgestellt
- `9f80c4aa974da01d606292c479487bce13b403fa` – Quelle erst nach beiden Stufen vollständig
- `030d0c1be7ef8cafdab3f3d46e01ec90d5fd3bdc` – kombinierte Statusanzeige
- `e831301650ee2a80c969e6c9b783d37a3c4f593d` – Syntaxkorrektur Status
- `479cdbc4734605db3861908286426c76468d12a3` – Quellenindex-Baseline blockiert Aktivierung nicht mehr
- `116b710d5d35311eeae261e5ae3cf405c5f51ce3` – Legacy-Worker bei reiner WebDAV-Installation nicht mehr starten
- `81e0a9e185a14c08230b4f8fa0f36809c5b2b9a7` – Version 0.9.7.1.16

## NICHT weiter tun

- Keine weiteren UI-/Statuspatches, bevor der echte Workerpfad bewiesen ist.
- Nicht wieder Cache-Dateiexistenz als Quelle für "neu/geändert" verwenden.
- Nicht den Placeholder selbst rendern.
- Nicht den Legacy-Full-Cache-Builder für WebDAV reaktivieren.
- Nicht weiter an Symptomen wie 45-Sekunden-Stall, "übersprungen" oder Prozentanzeige herumoptimieren.

## Nächster Einstieg – zwingend

Der nächste Chat/Entwickler soll **zuerst den tatsächlichen Rebuild-Ausführungspfad beweisen**:

1. Admin-Handler `image_cache_build` verfolgen.
2. `bratonien_tools_start_combined_image_cache_build()` verfolgen.
3. Start des `runtime/webdav-warmup-dispatch.php --mode=rebuild` beweisen.
4. Verifizieren, dass Dispatcher die aktive WebDAV-Verbindung findet.
5. Verifizieren, dass `runtime/lib/webdav-cache-warmup.php --mode=rebuild` wirklich gestartet wird.
6. Verifizieren, wie viele Quellen `bratonien_tools_cache_warmup_scan()` tatsächlich liefert.
7. Verifizieren, wie viele Varianten Stage 1 und Stage 2 pro Bild tatsächlich liefern.
8. Erst an **einem einzigen Bild** nachweisen:
   - Original wird aus Nextcloud heruntergeladen.
   - Placeholder wird korrekt gesichert.
   - Original wird am produktiven Quellpfad eingesetzt.
   - `piwigo-derivative-call.php` ruft wirklich Piwigos nativen `i.php`-Pfad auf.
   - Piwigo erzeugt tatsächlich eine konkrete Datei unter `_data/i`.
   - strikter Validator erkennt sie als gültig.
   - Placeholder wird exakt restauriert.
9. Erst wenn dieser Ein-Bild-Test nachweislich funktioniert, Batchbetrieb und UI wieder anfassen.

## Entscheidender Verdacht

Der derzeit wichtigste Verdacht ist **nicht mehr die Anzeige**, sondern dass der Rebuild intern sehr früh keine Arbeit findet oder Piwigo über den aufgerufenen `i.php`-Pfad keine Derivate erzeugt, obwohl der Worker dies erwartet. Genau diese Stelle muss mit realem Lauf/Log belegt werden.

## Produktionsvalidierung

Von ChatGPT wurde kein echter Serverlauf durchgeführt. Ohne reale Serverausgaben darf nicht behauptet werden, dass Pfadaudit, Materialisierung, Piwigo-Aufruf oder Cache-Erzeugung funktionieren.
