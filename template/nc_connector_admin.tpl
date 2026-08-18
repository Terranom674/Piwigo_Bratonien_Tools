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
      {if $nc_system_available && $NC_CONNECTOR.system.last_run_api_state == 'error'}
        <p class="bratonien-main-cache__warning"><strong>API:</strong> {$NC_CONNECTOR.system.last_run_api_message|escape:html}</p>
      {/if}
      {if $nc_system_available && $NC_CONNECTOR.system.last_run_fallback_state == 'ok'}
        <p class="bratonien-base-note"><strong>Fallback:</strong> erfolgreich übernommen.</p>
      {elseif $nc_system_available && $NC_CONNECTOR.system.last_run_fallback_state == 'error'}
        <p class="bratonien-main-cache__warning"><strong>Fallback:</strong> {$NC_CONNECTOR.system.last_run_fallback_message|escape:html}</p>
      {/if}
      {if $nc_system_available && $NC_CONNECTOR.system.last_run_error_detail}
        <p class="bratonien-main-cache__warning"><strong>Fehler:</strong> {$NC_CONNECTOR.system.last_run_error_detail|escape:html}</p>
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
      <h4>Neue Verbindung</h4>

      {if $NC_CONNECTOR.wizard.step == 1}
        <p class="bratonien-base-note">Der Assistent braucht zuerst nur die Nextcloud-Adresse und einen Benutzer, mit dem Nextcloud auf den Scan antworten darf.</p>
        <form method="post">
          <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
          <div class="bratonien-form-grid">
            <label class="bratonien-label" for="nc_wizard_host">Nextcloud-Host</label>
            <input id="nc_wizard_host" name="nc_wizard_host" type="text" placeholder="cloud.example.de" required>
            <label class="bratonien-label" for="nc_wizard_user">Nextcloud-Benutzer</label>
            <input id="nc_wizard_user" name="nc_wizard_user" type="text" autocomplete="username" required>
            <label class="bratonien-label" for="nc_wizard_password">Passwort</label>
            <input id="nc_wizard_password" name="nc_wizard_password" type="password" autocomplete="current-password" required>
          </div>
          <p><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_scan">Verbinden und scannen</button></p>
        </form>
      {elseif $NC_CONNECTOR.wizard.step == 2}
        <p class="bratonien-base-note"><strong>Scan erfolgreich.</strong> Erkannte Werte:</p>
        <div class="bratonien-form-grid">
          <span class="bratonien-label">Nextcloud</span><strong>{$NC_CONNECTOR.wizard.base_url|escape:html}</strong>
          <span class="bratonien-label">Produkt</span><strong>{$NC_CONNECTOR.wizard.product|escape:html}</strong>
          <span class="bratonien-label">Version</span><strong>{if $NC_CONNECTOR.wizard.version}{$NC_CONNECTOR.wizard.version|escape:html}{else}nicht gemeldet{/if}</strong>
          <span class="bratonien-label">Zugriff als</span><strong>{$NC_CONNECTOR.wizard.username|escape:html}{if $NC_CONNECTOR.wizard.display_name} · {$NC_CONNECTOR.wizard.display_name|escape:html}{/if}</strong>
        </div>

        <hr>
        <h5>Welcher Benutzer stellt die Bilder bereit?</h5>
        <p class="bratonien-base-note"><strong>Empfehlung:</strong> ein eigener Benutzer nur für die Showcase-Freigaben. So bleibt die Connector-Quelle klar und unabhängig von persönlichen Konten.</p>
        <form method="post">
          <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
          <div class="bratonien-form-grid">
            <label class="bratonien-label" for="nc_wizard_showcase_user">Showcase-Benutzer</label>
            {if $NC_CONNECTOR.wizard.can_list_users && $NC_CONNECTOR.wizard.users|@count > 0}
              <select id="nc_wizard_showcase_user" name="nc_wizard_showcase_user" required>
                {foreach from=$NC_CONNECTOR.wizard.users item=nc_user}
                  <option value="{$nc_user|escape:html}"{if $nc_user == 'showcase'} selected{/if}>{$nc_user|escape:html}{if $nc_user == 'showcase'} · empfohlen{/if}</option>
                {/foreach}
              </select>
            {else}
              <input id="nc_wizard_showcase_user" name="nc_wizard_showcase_user" type="text" value="showcase" required>
            {/if}
          </div>
          <div class="bratonien-actions">
            <button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_select_user">Weiter</button>
            <button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_reset" formnovalidate>Neu beginnen</button>
          </div>
        </form>
      {else}
        <p class="bratonien-base-note"><strong>Nextcloud verbunden.</strong> Showcase-Benutzer: <strong>{$NC_CONNECTOR.wizard.showcase_user|escape:html}</strong></p>
        <p class="bratonien-base-note">Der nächste Assistentenschritt ist die Piwigo-API-Prüfung. Die technische Verbindungserkennung bleibt davon getrennt und wird nicht als frei editierbare Pfadmaske in den Assistenten gezogen.</p>
        <form method="post">
          <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
          <button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_reset">Assistent neu beginnen</button>
        </form>
      {/if}

      <details style="margin-top:1.5rem">
        <summary><strong>Ohne Assistent anlegen</strong></summary>
        <p class="bratonien-main-cache__warning">Technische Einrichtung. Falsche Storage- oder Galeriepfade können die Synchronisierung auf den falschen Datenbestand richten.</p>
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
            <label class="bratonien-label" for="nc_piwigo_user">Piwigo-Fallback-Benutzer</label><input id="nc_piwigo_user" name="nc_piwigo_user" type="text">
            <label class="bratonien-label" for="nc_piwigo_password">Piwigo-Fallback-Passwort</label><input id="nc_piwigo_password" name="nc_piwigo_password" type="password" autocomplete="new-password">
            <label class="bratonien-label" for="nc_quiet_seconds">Ruhezeit</label><input id="nc_quiet_seconds" name="nc_quiet_seconds" type="number" min="0" value="120">
            <label class="bratonien-label" for="nc_max_wait_seconds">Maximale Wartezeit</label><input id="nc_max_wait_seconds" name="nc_max_wait_seconds" type="number" min="60" value="900">
            <label class="bratonien-label" for="nc_full_sync_seconds">Vollprüfung nach</label><input id="nc_full_sync_seconds" name="nc_full_sync_seconds" type="number" min="300" value="86400">
          </div>
          <p><label for="nc_storages"><strong>Storage-Zuordnungen</strong></label></p>
          <p class="bratonien-base-note">Eine Zeile pro Storage: <code>storage_id | source_prefix | /lokaler/mount</code></p>
          <textarea id="nc_storages" name="nc_storages" rows="4" style="width:100%" required></textarea>
          <p><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_create_local">Verbindung anlegen</button></p>
        </form>
      </details>
    </div>

    <div class="bratonien-card" style="grid-column:1/-1">
      <h4>Connector-Verbindungen</h4>
      {if $NC_CONNECTOR.connection_count > 0}
        <table class="table2">
          <thead>
            <tr><th>Name</th><th>Adapter</th><th>Host</th><th>Quelle</th><th>Storages</th><th>Status</th><th>Aktion</th></tr>
          </thead>
          <tbody>
          {foreach from=$NC_CONNECTOR.connections item=connection}
            <tr>
              <td>{$connection.display_name|escape:html}</td>
              <td>{$connection.adapter|escape:html}</td>
              <td>{if $connection.host}{$connection.host|escape:html}{else}—{/if}</td>
              <td>{if $connection.source_view}{$connection.source_view|escape:html}{else}—{/if}</td>
              <td>{$connection.storage_count|escape:html}</td>
              <td>{$connection.takeover_state|escape:html}{if $connection.enabled} · aktiv{/if}</td>
              <td>
                <details>
                  <summary>Verwalten</summary>
                  <form method="post" class="bratonien-actions" style="margin-top:.75rem">
                    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
                    <input type="hidden" name="connection_id" value="{$connection.id|escape:html}">
                    <input name="connection_name" type="text" value="{$connection.name|escape:html}" required>
                    <button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_update_name">Name speichern</button>
                  </form>

                  <div class="bratonien-actions">
                    {if $connection.adapter == 'local' && !$connection.enabled}
                      <form method="post">
                        <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
                        <input type="hidden" name="connection_id" value="{$connection.id|escape:html}">
                        <button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_verify">Verbindung prüfen</button>
                      </form>
                    {/if}
                    {if !$connection.enabled && $connection.takeover_state != 'active'}
                      <form method="post">
                        <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
                        <input type="hidden" name="connection_id" value="{$connection.id|escape:html}">
                        <button class="buttonLike bratonien-delete-button" type="submit" name="bratonien_tool" value="nc_connector_delete" onclick="return confirm('Verbindung wirklich löschen? Es werden nur die Connector-Einstellungen entfernt, keine Nextcloud- oder Piwigo-Bilder.');">Löschen</button>
                      </form>
                    {/if}
                  </div>

                  <details style="margin-top:.75rem">
                    <summary>Technische Einstellungen</summary>
                    {if $connection.enabled || $connection.takeover_state == 'active'}
                      <p class="bratonien-main-cache__warning">Eine aktive Verbindung muss vor technischen Änderungen deaktiviert werden.</p>
                    {else}
                      <p class="bratonien-main-cache__warning">Nur ändern, wenn die technische Zuordnung bekannt ist. Storage- und Galeriepfade sind sicherheitskritisch.</p>
                      <form method="post">
                        <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
                        <input type="hidden" name="connection_id" value="{$connection.id|escape:html}">
                        <div class="bratonien-form-grid">
                          <label class="bratonien-label">PostgreSQL-Host</label><input name="nc_host" type="text" value="{$connection.config.host|escape:html}" required>
                          <label class="bratonien-label">Port</label><input name="nc_port" type="number" min="1" max="65535" value="{$connection.config.port|escape:html}" required>
                          <label class="bratonien-label">Datenbank</label><input name="nc_database" type="text" value="{$connection.config.database|escape:html}" required>
                          <label class="bratonien-label">Reader-Benutzer</label><input name="nc_user" type="text" value="{$connection.config.user|escape:html}" required>
                          <label class="bratonien-label">Reader-Passwort</label><input name="nc_db_password" type="password" placeholder="leer = unverändert">
                          <label class="bratonien-label">Source-View</label><input name="nc_source_view" type="text" value="{$connection.config.source_view|escape:html}" required>
                          <label class="bratonien-label">Activity-View</label><input name="nc_activity_view" type="text" value="{$connection.config.activity_view|escape:html}" required>
                          <label class="bratonien-label">Piwigo-Galeriepfad</label><input name="nc_gallery_root" type="text" value="{$connection.config.gallery_root|escape:html}" required>
                          <label class="bratonien-label">Ruhezeit</label><input name="nc_quiet_seconds" type="number" min="0" value="{$connection.config.quiet_seconds|escape:html}">
                          <label class="bratonien-label">Maximale Wartezeit</label><input name="nc_max_wait_seconds" type="number" min="60" value="{$connection.config.max_wait_seconds|escape:html}">
                          <label class="bratonien-label">Vollprüfung nach</label><input name="nc_full_sync_seconds" type="number" min="300" value="{$connection.config.full_sync_seconds|escape:html}">
                        </div>
                        <p><strong>Storage-Zuordnungen</strong></p>
                        <textarea name="nc_storages" rows="4" style="width:100%" required>{$connection.storage_text|escape:html}</textarea>
                        <p><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_update_technical">Technische Einstellungen speichern</button></p>
                      </form>
                    {/if}
                  </details>
                </details>
              </td>
            </tr>
            {if $connection.verification_checks|@count > 0}
              <tr><td colspan="7"><strong>Letzte Verifikation{if $connection.verified_at} · {$connection.verified_at|escape:html}{/if}</strong><ul>{foreach from=$connection.verification_checks item=check}<li>{if $check.ok}✓{else}✗{/if} <strong>{$check.name|escape:html}:</strong> {$check.detail|escape:html}</li>{/foreach}</ul></td></tr>
            {/if}
            {if isset($connection.last_sync) && $connection.last_sync.timestamp > 0}
              <tr><td colspan="7"><strong>Letzter Sync · {$connection.last_sync.label|escape:html}</strong><p class="bratonien-base-note"><strong>Ergebnis:</strong> {$connection.last_sync.message|escape:html}</p>{if $connection.last_sync.api_state == 'error'}<p class="bratonien-main-cache__warning"><strong>API fehlgeschlagen:</strong> {$connection.last_sync.api_message|escape:html}</p>{/if}{if $connection.last_sync.fallback_state == 'error'}<p class="bratonien-main-cache__warning"><strong>Fallback fehlgeschlagen:</strong> {$connection.last_sync.fallback_message|escape:html}</p>{/if}{if $connection.last_sync.error_detail}<p class="bratonien-main-cache__warning"><strong>Technischer Fehler:</strong> {$connection.last_sync.error_detail|escape:html}</p>{/if}</td></tr>
            {/if}
          {/foreach}
          </tbody>
        </table>
      {else}
        <p>Noch keine Verbindung in der Connector-Verwaltung.</p>
      {/if}
    </div>

    <div class="bratonien-card" style="grid-column:1/-1">
      <h4>Piwigo API – bevorzugter Sync-Zugang</h4>
      <p class="bratonien-base-note">Die API-Verwaltung bleibt für bestehende Verbindungen verfügbar. Im fertigen Assistenten wird sie als eigener Prüfschritt eingebunden.</p>
      <form method="post">
        <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
        <div class="bratonien-form-grid">
          <label class="bratonien-label" for="nc_piwigo_api_key_id">API-Schlüssel-ID</label><input id="nc_piwigo_api_key_id" name="nc_piwigo_api_key_id" type="text" autocomplete="off" placeholder="pkid-..." required>
          <label class="bratonien-label" for="nc_piwigo_api_key_secret">API-Geheimnis</label><input id="nc_piwigo_api_key_secret" name="nc_piwigo_api_key_secret" type="password" autocomplete="off" required>
        </div>
        <div class="bratonien-actions">
          <button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_piwigo_api_test">API prüfen und speichern</button>
          <button class="buttonLike bratonien-delete-button" type="submit" name="bratonien_tool" value="nc_connector_piwigo_api_delete" formnovalidate onclick="return confirm('Gespeicherte Piwigo-API-Zugangsdaten wirklich löschen?');">Gespeicherte API löschen</button>
        </div>
      </form>
      {if isset($NC_CONNECTOR.piwigo_api_test) && $NC_CONNECTOR.piwigo_api_test}
        <p class="bratonien-base-note"><strong>Bewertung:</strong> {$NC_CONNECTOR.piwigo_api_test.conclusion|escape:html}</p>
      {/if}
    </div>

    <div class="bratonien-card" style="grid-column:1/-1">
      <h4>Benutzername/Passwort-Fallback</h4>
      {if $NC_CONNECTOR.connection_count > 0}
        <form method="post">
          <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
          <div class="bratonien-form-grid">
            <label class="bratonien-label" for="nc_fallback_connection">Verbindung</label><select id="nc_fallback_connection" name="connection_id" required>{foreach from=$NC_CONNECTOR.connections item=connection}<option value="{$connection.id|escape:html}">{$connection.name|escape:html}</option>{/foreach}</select>
            <label class="bratonien-label" for="nc_fallback_user">Piwigo-Benutzer</label><input id="nc_fallback_user" name="nc_fallback_user" type="text" autocomplete="username">
            <label class="bratonien-label" for="nc_fallback_password">Piwigo-Passwort</label><input id="nc_fallback_password" name="nc_fallback_password" type="password" autocomplete="current-password">
          </div>
          <div class="bratonien-actions">
            <button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_fallback_once">Einmalig verwenden</button>
            <button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_fallback_save">Fest speichern</button>
            <button class="buttonLike bratonien-delete-button" type="submit" name="bratonien_tool" value="nc_connector_fallback_delete" formnovalidate onclick="return confirm('Gespeicherten Benutzername/Passwort-Fallback wirklich löschen?');">Gespeicherten Fallback löschen</button>
          </div>
        </form>
      {else}<p class="bratonien-base-note">Noch keine Connector-Verbindung vorhanden.</p>{/if}
    </div>
  </div>
</section>