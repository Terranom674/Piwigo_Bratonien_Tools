<section class="bratonien-section" id="nc-connector">
  <h3>NC Connector</h3>
  <p class="bratonien-section__intro">Bratonien Tools verwaltet die Nextcloud-Anbindung und den regelmäßigen Piwigo-Abgleich.</p>

  {assign var=nc_system_available value=isset($NC_CONNECTOR.system)}

  <div class="bratonien-grid">
    <div class="bratonien-card">
      <h4>Connector-Status</h4>
      <div class="bratonien-form-grid">
        <span class="bratonien-label">Phase</span><strong>{$NC_CONNECTOR.phase|escape:html}</strong>
        <span class="bratonien-label">Connector-Verbindungen</span><strong>{$NC_CONNECTOR.connection_count|escape:html}</strong>
        <span class="bratonien-label">Timer aktiv</span><strong>{if $nc_system_available && $NC_CONNECTOR.system.timer_active}Ja{else}Nein{/if}</strong>
        <span class="bratonien-label">Timer aktiviert</span><strong>{if $nc_system_available && $NC_CONNECTOR.system.timer_enabled}Ja{else}Nein{/if}</strong>
        <span class="bratonien-label">Letzter Lauf</span><strong>{if $nc_system_available}{$NC_CONNECTOR.system.last_run_label|escape:html}{else}Nicht verfügbar{/if}</strong>
        <span class="bratonien-label">Nächster Lauf</span><strong>{if $nc_system_available}{$NC_CONNECTOR.system.next_run_label|escape:html}{else}Nicht verfügbar{/if}</strong>
      </div>
      {if $nc_system_available && $NC_CONNECTOR.system.last_run_message}
        <p class="bratonien-base-note">Letztes Ergebnis: <strong>{$NC_CONNECTOR.system.last_run_message|escape:html}</strong></p>
      {/if}
    </div>

    <div class="bratonien-card">
      <h4>Runtime</h4>
      <p class="bratonien-base-note">Neue Verbindungen verwenden direkt die Plugin-Runtime und einen eigenen State unter <code>/var/lib/bratonien-tools/nc-connector/connection-ID</code>.</p>
      {if $nc_system_available && !$NC_CONNECTOR.system.legacy_runtime_exists && !$NC_CONNECTOR.system.legacy_config_exists && !$NC_CONNECTOR.system.legacy_service_exists && !$NC_CONNECTOR.system.legacy_timer_exists}
        <p class="bratonien-base-note">Legacy-Bestand: <strong>vollständig entfernt</strong>.</p>
      {/if}
    </div>

    <div class="bratonien-card" style="grid-column:1/-1">
      <h4>Piwigo API prüfen</h4>
      <p class="bratonien-base-note">Diese Diagnose prüft ausschließlich, ob ein Piwigo-API-Key funktioniert, zu welchem Benutzer er gehört, welche Rolle dieser Benutzer hat und ob die Web-API mögliche Sync-/Site-Methoden anbietet. Der Key wird nicht gespeichert und es wird keine Synchronisation ausgelöst.</p>
      <form method="post">
        <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
        <div class="bratonien-form-grid">
          <label class="bratonien-label" for="nc_piwigo_api_key">API-Key</label>
          <input id="nc_piwigo_api_key" name="nc_piwigo_api_key" type="password" autocomplete="off" required>
        </div>
        <p><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_piwigo_api_test">API prüfen</button></p>
      </form>

      {if isset($NC_CONNECTOR.piwigo_api_test) && $NC_CONNECTOR.piwigo_api_test}
        <hr>
        <h5>Prüfergebnis</h5>
        <div class="bratonien-form-grid">
          <span class="bratonien-label">Benutzer</span><strong>{$NC_CONNECTOR.piwigo_api_test.username|escape:html}</strong>
          <span class="bratonien-label">Piwigo-Status</span><strong>{$NC_CONNECTOR.piwigo_api_test.status|escape:html}</strong>
          <span class="bratonien-label">Administrator/Webmaster</span><strong>{if $NC_CONNECTOR.piwigo_api_test.admin}Ja{else}Nein{/if}</strong>
          <span class="bratonien-label">Sichtbare API-Methoden</span><strong>{$NC_CONNECTOR.piwigo_api_test.method_count|escape:html}</strong>
          <span class="bratonien-label">Mögliche Sync-/Site-Methoden</span><strong>{if $NC_CONNECTOR.piwigo_api_test.sync_api_detected}Ja{else}Nein{/if}</strong>
        </div>
        {if $NC_CONNECTOR.piwigo_api_test.sync_candidates|@count > 0}
          <p><strong>Gefundene Kandidaten:</strong></p>
          <ul>
            {foreach from=$NC_CONNECTOR.piwigo_api_test.sync_candidates item=method}
              <li><code>{$method|escape:html}</code></li>
            {/foreach}
          </ul>
        {/if}
        <p class="bratonien-base-note"><strong>Bewertung:</strong> {$NC_CONNECTOR.piwigo_api_test.conclusion|escape:html}</p>
      {/if}
    </div>

    <div class="bratonien-card" style="grid-column:1/-1">
      <h4>Neue lokale Verbindung</h4>
      <form method="post">
        <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
        <div class="bratonien-form-grid">
          <label class="bratonien-label" for="nc_name">Name</label><input id="nc_name" name="nc_name" type="text" required>
          <label class="bratonien-label" for="nc_host">PostgreSQL-Host</label><input id="nc_host" name="nc_host" type="text" required>
          <label class="bratonien-label" for="nc_port">PostgreSQL-Port</label><input id="nc_port" name="nc_port" type="number" min="1" max="65535" value="5432" required>
          <label class="bratonien-label" for="nc_database">Datenbank</label><input id="nc_database" name="nc_database" type="text" value="nextcloud" required>
          <label class="bratonien-label" for="nc_user">Reader-Benutzer</label><input id="nc_user" name="nc_user" type="text" required>
          <label class="bratonien-label" for="nc_db_password">Reader-Passwort</label><input id="nc_db_password" name="nc_db_password" type="password" autocomplete="new-password" required>
          <label class="bratonien-label" for="nc_source_view">Source-View</label><input id="nc_source_view" name="nc_source_view" type="text" value="piwigo_showcase_sources" required>
          <label class="bratonien-label" for="nc_activity_view">Activity-View</label><input id="nc_activity_view" name="nc_activity_view" type="text" value="piwigo_showcase_activity" required>
          <label class="bratonien-label" for="nc_gallery_root">Piwigo-Galeriepfad</label><input id="nc_gallery_root" name="nc_gallery_root" type="text" placeholder="/var/www/piwigo/galleries/nextcloud" required>
          <label class="bratonien-label" for="nc_piwigo_user">Piwigo-Sync-Benutzer</label><input id="nc_piwigo_user" name="nc_piwigo_user" type="text" required>
          <label class="bratonien-label" for="nc_piwigo_password">Piwigo-Sync-Passwort</label><input id="nc_piwigo_password" name="nc_piwigo_password" type="password" autocomplete="new-password" required>
          <label class="bratonien-label" for="nc_quiet_seconds">Ruhezeit</label><input id="nc_quiet_seconds" name="nc_quiet_seconds" type="number" min="0" value="120">
          <label class="bratonien-label" for="nc_max_wait_seconds">Maximale Wartezeit</label><input id="nc_max_wait_seconds" name="nc_max_wait_seconds" type="number" min="60" value="900">
          <label class="bratonien-label" for="nc_full_sync_seconds">Vollprüfung nach</label><input id="nc_full_sync_seconds" name="nc_full_sync_seconds" type="number" min="300" value="86400">
        </div>
        <p><label for="nc_storages"><strong>Storage-Zuordnungen</strong></label></p>
        <p class="bratonien-base-note">Eine Zeile pro Storage: <code>storage_id | source_prefix | /lokaler/mount</code></p>
        <textarea id="nc_storages" name="nc_storages" rows="4" style="width:100%" required></textarea>
        <p><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_create_local">Verbindung anlegen</button></p>
      </form>
    </div>

    <div class="bratonien-card" style="grid-column:1/-1">
      <h4>Connector-Verbindungen</h4>
      {if $NC_CONNECTOR.connection_count > 0}
        <table class="table2">
          <thead>
            <tr>
              <th>Name</th>
              <th>Adapter</th>
              <th>Host</th>
              <th>Quelle</th>
              <th>Storages</th>
              <th>Status</th>
              <th>Aktion</th>
            </tr>
          </thead>
          <tbody>
          {foreach from=$NC_CONNECTOR.connections item=connection}
            <tr>
              <td>{$connection.name|escape:html}</td>
              <td>{$connection.adapter|escape:html}</td>
              <td>{if $connection.host}{$connection.host|escape:html}{else}—{/if}</td>
              <td>{if $connection.source_view}{$connection.source_view|escape:html}{else}—{/if}</td>
              <td>{$connection.storage_count|escape:html}</td>
              <td>{$connection.takeover_state|escape:html}{if $connection.enabled} · aktiv{/if}</td>
              <td>
                {if $connection.takeover_state == 'active'}
                  <code>php /var/www/piwigo/plugins/bratonien_tools/nc-connector-disable.php {$connection.id|escape:html}</code>
                {elseif $connection.takeover_state == 'verified' && isset($connection.config.origin) && $connection.config.origin == 'native'}
                  <code>php /var/www/piwigo/plugins/bratonien_tools/nc-connector-install.php {$connection.id|escape:html}</code>
                  <form method="post" class="bratonien-actions">
                    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
                    <input type="hidden" name="connection_id" value="{$connection.id|escape:html}">
                    <button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_verify">Erneut prüfen</button>
                  </form>
                {elseif $connection.takeover_state == 'ready'}
                  <form method="post" class="bratonien-actions">
                    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
                    <input type="hidden" name="connection_id" value="{$connection.id|escape:html}">
                    <button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_cancel_takeover">Vorbereitung zurücknehmen</button>
                  </form>
                {elseif $connection.takeover_state == 'verified' && !$connection.enabled}
                  <form method="post" class="bratonien-actions">
                    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
                    <input type="hidden" name="connection_id" value="{$connection.id|escape:html}">
                    <button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_prepare_takeover">Übergabe vorbereiten</button>
                  </form>
                {elseif $connection.adapter == 'local' && !$connection.enabled}
                  <form method="post" class="bratonien-actions">
                    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
                    <input type="hidden" name="connection_id" value="{$connection.id|escape:html}">
                    <button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_verify">Verbindung prüfen</button>
                  </form>
                  <form method="post" class="bratonien-actions">
                    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
                    <input type="hidden" name="connection_id" value="{$connection.id|escape:html}">
                    <button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_delete">Löschen</button>
                  </form>
                {else}
                  —
                {/if}
              </td>
            </tr>
            {if $connection.verification_checks|@count > 0}
              <tr>
                <td colspan="7">
                  <strong>Letzte Verifikation{if $connection.verified_at} · {$connection.verified_at|escape:html}{/if}</strong>
                  <ul>
                    {foreach from=$connection.verification_checks item=check}
                      <li>{if $check.ok}✓{else}✗{/if} <strong>{$check.name|escape:html}:</strong> {$check.detail|escape:html}</li>
                    {/foreach}
                  </ul>
                  {if $connection.takeover_state == 'active'}
                    <p class="bratonien-base-note"><strong>Connector aktiv.</strong> State: <code>{$connection.config.state_dir|escape:html}</code></p>
                  {elseif $connection.takeover_state == 'verified' && isset($connection.config.origin) && $connection.config.origin == 'native'}
                    <p class="bratonien-base-note">Verifiziert. Der angezeigte Root-Befehl richtet Runtime-Konfiguration, State-Verzeichnis und gemeinsamen systemd-Timer ein und führt vor der Aktivierung einen Testlauf aus.</p>
                  {/if}
                </td>
              </tr>
            {/if}
          {/foreach}
          </tbody>
        </table>
      {else}
        <p>Noch keine Verbindung in der Connector-Verwaltung.</p>
      {/if}
    </div>

    <div class="bratonien-card">
      <h4>Sync-Zustand</h4>
      <div class="bratonien-form-grid">
        <span class="bratonien-label">Connector-Timer</span><strong>{if $nc_system_available && $NC_CONNECTOR.system.timer_active}Aktiv{else}Nicht aktiv{/if}</strong>
        <span class="bratonien-label">Letzter Lauf</span><strong>{if $nc_system_available}{$NC_CONNECTOR.system.last_run_label|escape:html}{else}Nicht verfügbar{/if}</strong>
        <span class="bratonien-label">Nächster Lauf</span><strong>{if $nc_system_available}{$NC_CONNECTOR.system.next_run_label|escape:html}{else}Nicht verfügbar{/if}</strong>
      </div>
      {if $nc_system_available && $NC_CONNECTOR.system.last_run_message}
        <p class="bratonien-base-note">{$NC_CONNECTOR.system.last_run_message|escape:html}</p>
      {/if}
    </div>

    <div class="bratonien-card">
      <h4>Neuinstallation</h4>
      <p class="bratonien-base-note">Eine frische Installation benötigt keinen Legacy-Sync. Ablauf: Verbindung anlegen → prüfen → Root-Aktivierung ausführen. Danach arbeitet ausschließlich die Plugin-Runtime.</p>
    </div>
  </div>
</section>