<section class="bratonien-section" id="nc-connector">
  <h3>NC Connector</h3>
  <p class="bratonien-section__intro">Bratonien Tools übernimmt die bestehende Nextcloud-Anbindung schrittweise in eine eigene Connection-Verwaltung. Der produktive Legacy-Sync bleibt während Migration, Verifikation und Übergabevorbereitung unverändert aktiv.</p>

  <div class="bratonien-grid">
    <div class="bratonien-card">
      <h4>Migrationsstatus</h4>
      <div class="bratonien-form-grid">
        <span class="bratonien-label">Phase</span><strong>{$NC_CONNECTOR.phase|escape:html}</strong>
        <span class="bratonien-label">Legacy-Verbindung vorhanden</span><strong>{if $NC_CONNECTOR.legacy_present}Ja{else}Nein{/if}</strong>
        <span class="bratonien-label">Legacy-Konfiguration für PHP lesbar</span><strong>{if $NC_CONNECTOR.config_readable}Ja{else}Nein{/if}</strong>
        <span class="bratonien-label">Connector-Verbindungen</span><strong>{$NC_CONNECTOR.connection_count|escape:html}</strong>
        <span class="bratonien-label">Davon verifiziert</span><strong>{$NC_CONNECTOR.verified_count|escape:html}</strong>
        <span class="bratonien-label">Migrationspaket bereit</span><strong>{if $NC_CONNECTOR.migration_bundle_available}Ja{else}Nein{/if}</strong>
      </div>

      {if $NC_CONNECTOR.legacy_present && !$NC_CONNECTOR.config_readable}
        <p class="bratonien-base-note">Die bestehende Konfiguration ist absichtlich nur für root lesbar. Sie wird nicht für den Webserver geöffnet.</p>
      {/if}
    </div>

    <div class="bratonien-card">
      <h4>Bestehende Verbindung übernehmen</h4>
      {if $NC_CONNECTOR.connection_count == 0}
        <p>Führe im <strong>Piwigo-LXC</strong> einmalig folgenden Befehl als root aus:</p>
        <p><code>{$NC_CONNECTOR.migration_command|escape:html}</code></p>
        <p class="bratonien-base-note">Der Helfer liest die bisherige <code>/etc/piwigo-sync/piwigo.conf</code>, die zugehörige Passwortdatei und <code>storages.tsv</code>. Er verändert weder Nextcloud noch den laufenden Sync-Dienst oder den Shadow Tree.</p>

        {if $NC_CONNECTOR.migration_bundle_available}
          <form method="post" class="bratonien-actions">
            <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
            <button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_import_legacy">Bestehende Verbindung importieren</button>
          </form>
          <p class="bratonien-main-cache__warning">Der Import legt die Verbindung nur im NC Connector an. Sie bleibt deaktiviert; der bisherige Sync bleibt Produktionsverbindung.</p>
        {else}
          <p class="bratonien-main-cache__warning">Noch kein Migrationspaket vorhanden. Nach Ausführen des Befehls diese Seite neu laden.</p>
        {/if}
      {else}
        <p>Die bestehende Verbindung wurde bereits in die Connector-Verwaltung übernommen. Verifizierte Verbindungen können nun für eine spätere kontrollierte Übergabe vorbereitet werden.</p>
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
                    <p class="bratonien-base-note">Die Verbindung ist technisch verifiziert und für die spätere kontrollierte Übergabe vorgemerkt. Der Connector ist weiterhin deaktiviert und der Legacy-Sync bleibt Produktionsverbindung.</p>
                  {elseif $connection.verified_ok}
                    <p class="bratonien-base-note">Die Connector-Kopie ist technisch verifiziert. Sie bleibt deaktiviert; der Legacy-Sync ist weiterhin die Produktionsverbindung.</p>
                  {else}
                    <p class="bratonien-main-cache__warning">Die Verifikation ist noch nicht vollständig erfolgreich. Es wurde nichts umgeschaltet oder verändert.</p>
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
      <h4>Legacy-Sync</h4>
      <div class="bratonien-form-grid">
        <span class="bratonien-label">Konfiguration</span><strong>{if $NC_CONNECTOR.config_readable}Lesbar{elseif $NC_CONNECTOR.config_exists}Vorhanden, root-geschützt{else}Nicht gefunden{/if}</strong>
        <span class="bratonien-label">Piwigo-Sync laut lesbarer Konfiguration</span><strong>{if $NC_CONNECTOR.config_readable}{if $NC_CONNECTOR.sync_enabled}Ja{else}Nein{/if}{else}Nicht auslesbar{/if}</strong>
        <span class="bratonien-label">Letzter Status</span><strong>{if $NC_CONNECTOR.sync_status.available}{$NC_CONNECTOR.sync_status.state|escape:html}{else}Nicht verfügbar{/if}</strong>
      </div>
      {if $NC_CONNECTOR.sync_status.message}
        <p class="bratonien-base-note">{$NC_CONNECTOR.sync_status.message|escape:html}</p>
      {/if}
    </div>

    <div class="bratonien-card">
      <h4>Migrationsschutz</h4>
      <ul>
        <li>kein Stoppen oder Ändern des bestehenden <code>piwigo-sync</code></li>
        <li>keine Änderung an PostgreSQL, Views, <code>pg_hba.conf</code> oder Reader-Rechten</li>
        <li>keine Änderung an bestehenden Mounts</li>
        <li>kein Neuaufbau des Gallery-/Shadow-Trees</li>
        <li><code>ready</code> ist nur eine interne Übergabevormerkung und aktiviert noch keinen Connector-Sync</li>
        <li>die Übergabevorbereitung kann jederzeit wieder auf <code>verified</code> zurückgesetzt werden</li>
      </ul>
    </div>
  </div>
</section>
