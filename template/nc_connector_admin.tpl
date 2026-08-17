<section class="bratonien-section" id="nc-connector">
  <h3>NC Connector</h3>
  <p class="bratonien-section__intro">Die bestehende Nextcloud-Piwigo-Anbindung wird in dieser ersten Phase nur erkannt und angezeigt. Es werden keine Zugangsdaten, PostgreSQL-Views, Sync-Dienste oder Galeriepfade verändert.</p>

  <div class="bratonien-grid">
    <div class="bratonien-card">
      <h4>Verbindungsstatus</h4>
      <div class="bratonien-form-grid">
        <span class="bratonien-label">Phase</span><strong>{$NC_CONNECTOR.phase|escape:html}</strong>
        <span class="bratonien-label">Bestehende Verbindung erkannt</span><strong>{if $NC_CONNECTOR.detected}Ja{else}Nein{/if}</strong>
        <span class="bratonien-label">Konfiguration</span><strong>{if $NC_CONNECTOR.config_readable}Lesbar{elseif $NC_CONNECTOR.config_exists}Vorhanden, aber nicht lesbar{else}Nicht gefunden{/if}</strong>
        <span class="bratonien-label">Piwigo-Sync aktiviert</span><strong>{if $NC_CONNECTOR.sync_enabled}Ja{else}Nein{/if}</strong>
        <span class="bratonien-label">Letzter Sync-Status</span><strong>{if $NC_CONNECTOR.sync_status.available}{$NC_CONNECTOR.sync_status.state|escape:html}{else}Nicht verfügbar{/if}</strong>
        {if $NC_CONNECTOR.sync_status.time_label}
          <span class="bratonien-label">Letzte Statusmeldung</span><strong>{$NC_CONNECTOR.sync_status.time_label|escape:html}</strong>
        {/if}
      </div>

      {if $NC_CONNECTOR.sync_status.message}
        <p class="bratonien-base-note">{$NC_CONNECTOR.sync_status.message|escape:html}</p>
      {/if}

      {if !$NC_CONNECTOR.detected}
        <p class="bratonien-main-cache__warning">Die bestehende Verbindung konnte noch nicht vollständig erkannt werden. In dieser Phase wird nichts automatisch eingerichtet oder verändert.</p>
      {/if}
    </div>

    <div class="bratonien-card">
      <h4>Erkannte Nextcloud-Verbindung</h4>
      <div class="bratonien-form-grid">
        <span class="bratonien-label">Host</span><strong>{if $NC_CONNECTOR.host}{$NC_CONNECTOR.host|escape:html}{else}—{/if}</strong>
        <span class="bratonien-label">Port</span><strong>{if $NC_CONNECTOR.port}{$NC_CONNECTOR.port|escape:html}{else}—{/if}</strong>
        <span class="bratonien-label">Datenbank</span><strong>{if $NC_CONNECTOR.database}{$NC_CONNECTOR.database|escape:html}{else}—{/if}</strong>
        <span class="bratonien-label">Reader</span><strong>{if $NC_CONNECTOR.user}{$NC_CONNECTOR.user|escape:html}{else}—{/if}</strong>
        <span class="bratonien-label">Quell-View</span><strong>{if $NC_CONNECTOR.view}{$NC_CONNECTOR.view|escape:html}{else}—{/if}</strong>
        <span class="bratonien-label">Passwortdatei</span><strong>{if $NC_CONNECTOR.password_file_exists}{if $NC_CONNECTOR.password_file_readable}Vorhanden und lesbar{else}Vorhanden, aber nicht lesbar{/if}{else}Nicht gefunden{/if}</strong>
      </div>
      <p class="bratonien-base-note">Das Passwort selbst wird von Bratonien Tools nicht gelesen oder angezeigt.</p>
    </div>

    <div class="bratonien-card">
      <h4>Bestehender Sync</h4>
      <div class="bratonien-form-grid">
        <span class="bratonien-label">Galeriepfad</span><strong>{if $NC_CONNECTOR.gallery_root}{$NC_CONNECTOR.gallery_root|escape:html}{else}—{/if}</strong>
        <span class="bratonien-label">Statusverzeichnis</span><strong>{if $NC_CONNECTOR.state_dir}{$NC_CONNECTOR.state_dir|escape:html}{else}—{/if}</strong>
        <span class="bratonien-label">Quiet Time</span><strong>{if $NC_CONNECTOR.quiet_seconds}{$NC_CONNECTOR.quiet_seconds|escape:html} s{else}—{/if}</strong>
        <span class="bratonien-label">Max. Wartezeit</span><strong>{if $NC_CONNECTOR.max_wait_seconds}{$NC_CONNECTOR.max_wait_seconds|escape:html} s{else}—{/if}</strong>
        <span class="bratonien-label">Full Sync</span><strong>{if $NC_CONNECTOR.full_sync_seconds}{$NC_CONNECTOR.full_sync_seconds|escape:html} s{else}—{/if}</strong>
      </div>
    </div>

    <div class="bratonien-card">
      <h4>Migrationsschutz</h4>
      <p>Der vorhandene Sync bleibt die aktive Produktionsverbindung. Dieses Modul übernimmt aktuell noch keine Steuerung und besitzt keine Schreibaktion.</p>
      <ul>
        <li>kein Ändern der Nextcloud-Datenbank</li>
        <li>kein Ändern von <code>pg_hba.conf</code> oder Reader-Zugangsdaten</li>
        <li>kein Starten oder Stoppen des bestehenden Sync-Dienstes</li>
        <li>kein Neuaufbau des Gallery-/Shadow-Trees</li>
      </ul>
    </div>
  </div>
</section>
