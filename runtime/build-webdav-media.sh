#!/usr/bin/env bash
set -Eeuo pipefail

# Seit 0.9.7.20 greift der NC Connector nicht mehr in Piwigos Bild-/Derivatlogik ein.
# Der Shadowtree dient nur der Piwigo-Synchronisation; die Bild-URL wird zur Laufzeit
# ueber den bestehenden WebDAV-URL-Hook auf Nextcloud aufgeloest.
exit 0
