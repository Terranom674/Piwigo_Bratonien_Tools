<section class="bratonien-section" id="nc-connector">
  <h3>NC Connector</h3>
  <p class="bratonien-section__intro">Bratonien Tools verwaltet die Nextcloud-Anbindung und den regelmäßigen Piwigo-Abgleich.</p>

  <div class="bratonien-grid">
    <div class="bratonien-card">
      <h4>Connector-Status</h4>
      <div class="bratonien-form-grid">
        <span class="bratonien-label">Phase</span><strong>{$NC_CONNECTOR.phase|escape:html}</strong>
        <span class="bratonien-label">Connector-Verbindungen</span><strong>{$NC_CONNECTOR.connection_count|escape:html}</strong>
        <span class="bratonien-label">Timer aktiv</span><strong>{if $NC_CONNECTOR.system.timer_active}Ja{else}Nein{/if}</strong>
        <span class="bratonien-label">Timer aktiviert</span><strong>{if $NC_CONNECTOR.system.timer_enabled}Ja{else}Nein{/if}</strong>
        <span class="bratonien-label">Nächster Lauf</span><strong>{$NC_CONNECTOR.system.next_run_label|escape:html}</strong>
      </div>
    </div>

    <div class="bratonien-card">
      <h4>Legacy-Bestand</h4>
      <div class="bratonien-form-grid">
        <span class="bratonien-label"><code>/opt/piwigo-sync</code></span><strong>{if $NC_CONNECTOR.system.legacy_runtime_exists}Vorhanden{else}Entfernt{/if}</strong>
        <span class="bratonien-label"><code>/etc/piwigo-sync</code></span><strong>{if $NC_CONNECTOR.system.legacy_config_exists}Vorhanden{else}Entfernt{/if}</strong>
        <span class="bratonien-label">Legacy-Service</span><strong>{if $NC_CONNECTOR.system.legacy_service_exists}Vorhanden{else}Entfernt{/if}</strong>
        <span class="bratonien-label">Legacy-Timer</span><strong>{if $NC_CONNECTOR.system.legacy_timer_exists}Vorhanden{else}Entfernt{/if}</strong>
      </div>
      {if $NC_CONNECTOR.system.legacy_runtime_exists || $NC_CONNECTOR.system.legacy_config_exists || $NC_CONNECTOR.system.legacy_service_exists || $NC_CONNECTOR.system.legacy_timer_exists}
        <p class="bratonien-main-cache__warning">Die Verbindung läuft bereits mit der Plugin-eigenen Runtime. Die verbliebenen Legacy-Dateien können jetzt entfernt werden.</p>
        {foreach from=$NC_CONNECTOR.connections item=cleanup_connection}
          {if $cleanup_connection.takeover_state == 'active' && $cleanup_connection.enabled && isset($cleanup_connection.config.takeover.runtime) && $cleanup_connection.config.takeover.runtime == 'plugin-runtime'}
            <p><strong>Einmalig im Piwigo-LXC als root:</strong></p>
            <p><code>php /var/www/piwigo/plugins/bratonien_tools/nc-connector-legacy-cleanup.php {$cleanup_connection.id|escape:html}</code></p>
          {/if}
        {/foreach}
      {else}
        <p class="bratonien-base-note">Der alte Script-Bestand ist vollständig entfernt. Der NC Connector arbeitet ausschließlich mit seiner Plugin-eigenen Runtime.</p>
      {/if}
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
                {if $connection.takeover_state == 'ready'}
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
                  <form method="post" class="bratonien-actions">
                    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
                    <input type="hidden" name="connection_id" value="{$connection.id|escape:html}">
                    <button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_verify">Erneut prüfen</button>
                  </form>
                {elseif $connection.adapter == 'local' && !$connection.enabled}
                  <form method="post" class="bratonien-actions">
                    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
                    <input type="hidden" name="connection_id" value="{$connection.id|escape:html}">
                    <button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_verify">Verbindung prüfen</button>
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
                  {if $connection.takeover_state == 'ready'}
                    <p class="bratonien-base-note">Die Verbindung ist technisch verifiziert und für die kontrollierte Übergabe bereit. Der Connector ist noch deaktiviert und der Legacy-Sync bleibt Produktionsverbindung.</p>
                    <p><strong>Cutover im Piwigo-LXC als root:</strong></p>
                    <p><code>php /var/www/piwigo/plugins/bratonien_tools/nc-connector-cutover-v2.php {$connection.id|escape:html}</code></p>
                  {elseif $connection.takeover_state == 'active'}
                    <p class="bratonien-base-note"><strong>Connector aktiv.</strong> Der Connector-Timer übernimmt die regelmäßige Prüfung.</p>
                    <p class="bratonien-base-note">Nächster geplanter Lauf: <strong>{$NC_CONNECTOR.system.next_run_label|escape:html}</strong></p>
                    {if isset($connection.config.takeover.first_run.result)}
                      <p class="bratonien-base-note">Erster Connector-Lauf: <strong>{$connection.config.takeover.first_run.result|escape:html}</strong>{if isset($connection.config.takeover.first_run.checked_at)} · {$connection.config.takeover.first_run.checked_at|escape:html}{/if}</p>
                    {/if}
                    {if isset($connection.config.takeover.runtime) && $connection.config.takeover.runtime == 'plugin-runtime'}
                      <p class="bratonien-base-note">Runtime: <strong>Bratonien Tools</strong></p>
                    {/if}
                  {elseif $connection.verified_ok}
                    <p class="bratonien-base-note">Die Connector-Kopie ist technisch verifiziert. Sie bleibt deaktiviert.</p>
                  {else}
                    <p class="bratonien-main-cache__warning">Die Verifikation ist noch nicht vollständig erfolgreich.</p>
                  {/if}
                </td>
              </tr>
            {/if}
          {/foreach}
          </tbody>
        </table>
      {else}
        <p>Noch keine Verbindung in der eigenen Connector-Verwaltung.</p>
      {/if}
    </div>

    <div class="bratonien-card">
      <h4>Sync-Zustand</h4>
      <div class="bratonien-form-grid">
        <span class="bratonien-label">Connector-Timer</span><strong>{if $NC_CONNECTOR.system.timer_active}Aktiv{else}Nicht aktiv{/if}</strong>
        <span class="bratonien-label">Nächster Lauf</span><strong>{$NC_CONNECTOR.system.next_run_label|escape:html}</strong>
      </div>
    </div>

    <div class="bratonien-card">
      <h4>Laufzeitdaten</h4>
      <p class="bratonien-base-note"><code>/var/lib/piwigo-sync</code> bleibt aktuell bestehen. Dort liegen keine alten Scripts oder Zugangsdaten, sondern die vom aktiven Connector benötigte Name-Map, Activity-State, Manifest- und Statusdaten.</p>
    </div>
  </div>
</section>
