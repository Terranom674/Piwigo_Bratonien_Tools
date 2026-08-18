<section class="bratonien-section" id="nc-connector">
  <h3>NC Connector</h3>
  <p class="bratonien-section__intro">Verbindet Nextcloud mit Piwigo und hält freigegebene Bilder automatisch aktuell.</p>

  {assign var=nc_system_available value=isset($NC_CONNECTOR.system)}

  <div class="bratonien-grid">
    <div class="bratonien-card">
      <h4>Status</h4>
      <div class="bratonien-form-grid">
        <span class="bratonien-label">Verbindungen</span><strong>{$NC_CONNECTOR.connection_count|escape:html}</strong>
        <span class="bratonien-label">Automatischer Abgleich</span><strong>{if $nc_system_available && $NC_CONNECTOR.system.timer_active && $NC_CONNECTOR.system.timer_enabled}Aktiv{else}Nicht aktiv{/if}</strong>
        <span class="bratonien-label">Letzter Lauf</span><strong data-nc-last-run>{if $nc_system_available}{$NC_CONNECTOR.system.last_run_label|escape:html}{else}Nicht verfügbar{/if}</strong>
        <span class="bratonien-label">Nächster Lauf</span><strong data-nc-next-run>{if $nc_system_available}{$NC_CONNECTOR.system.next_run_label|escape:html}{else}Nicht verfügbar{/if}</strong>
      </div>
      {if $nc_system_available && $NC_CONNECTOR.system.last_run_message}<p class="bratonien-base-note">Letztes Ergebnis: <strong>{$NC_CONNECTOR.system.last_run_message|escape:html}</strong></p>{/if}
      {if $nc_system_available && $NC_CONNECTOR.system.last_run_api_state == 'error'}<p class="bratonien-main-cache__warning"><strong>API:</strong> {$NC_CONNECTOR.system.last_run_api_message|escape:html}</p>{/if}
      {if $nc_system_available && $NC_CONNECTOR.system.last_run_fallback_state == 'error'}<p class="bratonien-main-cache__warning"><strong>Fallback:</strong> {$NC_CONNECTOR.system.last_run_fallback_message|escape:html}</p>{/if}
      {if $nc_system_available && $NC_CONNECTOR.system.last_run_error_detail}<details><summary>Technische Fehlerdetails</summary><p class="bratonien-main-cache__warning">{$NC_CONNECTOR.system.last_run_error_detail|escape:html}</p></details>{/if}
    </div>

    <div class="bratonien-card">
      <h4>Neue Verbindung</h4>
      <p class="bratonien-base-note">Für die normale Einrichtung empfehlen wir den Assistenten.</p>
      <div class="bratonien-actions">
        <button class="buttonLike" type="button" id="bratonien-nc-wizard-open">Mit Assistent anlegen</button>
        <button class="buttonLike" type="button" id="bratonien-nc-technical-open">Ohne Assistent anlegen</button>
      </div>
    </div>
  </div>

  <dialog id="bratonien-nc-wizard-dialog" style="width:min(980px,calc(100vw - 3rem));max-height:88vh;overflow:auto;background:#444;color:inherit;border:1px solid #777;border-radius:4px;padding:0;box-shadow:0 18px 60px rgba(0,0,0,.55)">
    <div style="padding:1.25rem 1.5rem">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1rem">
        <div>
          <h4 style="margin:0">Neue Verbindung</h4>
          <p class="bratonien-base-note" style="margin:.35rem 0 0"><strong>
            {if $NC_CONNECTOR.wizard.step == 1}
              Anmeldung
            {elseif $NC_CONNECTOR.wizard.step == 2 && $NC_CONNECTOR.wizard.technical_stage == 'database_details'}
              Datenbank prüfen
            {elseif $NC_CONNECTOR.wizard.step == 2 && $NC_CONNECTOR.wizard.technical_stage == 'mounts' && !$NC_CONNECTOR.wizard.directory_selection_ready}
              Speicher zuordnen
            {elseif $NC_CONNECTOR.wizard.step == 2 && $NC_CONNECTOR.wizard.technical_stage == 'mounts' && $NC_CONNECTOR.wizard.directory_selection_ready}
              Verzeichnisse auswählen
            {elseif $NC_CONNECTOR.wizard.step == 2 && $NC_CONNECTOR.wizard.technical_complete}
              Verbindung benennen
            {elseif $NC_CONNECTOR.wizard.step == 3}
              Piwigo-API
            {elseif $NC_CONNECTOR.wizard.step == 4}
              Abschluss
            {else}
              Einrichtung
            {/if}
          </strong></p>
        </div>
        <button class="buttonLike" type="button" id="bratonien-nc-wizard-close">Schließen</button>
      </div>

      {if $NC_CONNECTOR.wizard.step == 1}
        <p class="bratonien-base-note">Adresse und Zugang reichen für den ersten Scan. Der Assistent prüft den erreichbaren Web-Zugang automatisch.</p>
        <form method="post" data-bratonien-wizard-form>
          <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
          <div class="bratonien-form-grid">
            <label class="bratonien-label" for="nc_wizard_host">Nextcloud-Adresse</label>
            <input id="nc_wizard_host" name="nc_wizard_host" type="text" placeholder="cloud.example.de" value="{$NC_CONNECTOR.wizard.host_input|escape:html}" required>
            <label class="bratonien-label" for="nc_wizard_user">Benutzer</label>
            <input id="nc_wizard_user" name="nc_wizard_user" type="text" autocomplete="username" value="{$NC_CONNECTOR.wizard.username|escape:html}" required>
            <label class="bratonien-label" for="nc_wizard_password">Passwort</label>
            <input id="nc_wizard_password" name="nc_wizard_password" type="password" autocomplete="current-password" value="{$NC_CONNECTOR.wizard._password|escape:html}" required>
          </div>
          <p><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_scan">Verbinden und scannen</button></p>
        </form>

      {elseif $NC_CONNECTOR.wizard.step == 2}
        <p class="bratonien-base-note"><strong>Nextcloud wurde gefunden.</strong> Für den Datenzugriff wird die bekannte Reader-Verbindung verwendet. Verzeichnisse werden ausschließlich mit dem angemeldeten Nextcloud-Benutzer gelesen.</p>
        <div class="bratonien-form-grid">
          <span class="bratonien-label">Adresse</span><strong>{$NC_CONNECTOR.wizard.base_url|escape:html}</strong>
          <span class="bratonien-label">Version</span><strong>{if $NC_CONNECTOR.wizard.version}{$NC_CONNECTOR.wizard.version|escape:html}{else}Nicht gemeldet{/if}</strong>
          <span class="bratonien-label">Angemeldet als</span><strong>{$NC_CONNECTOR.wizard.username|escape:html}{if $NC_CONNECTOR.wizard.display_name} · {$NC_CONNECTOR.wizard.display_name|escape:html}{/if}</strong>
        </div>

        {if $NC_CONNECTOR.wizard.technical_stage == 'database_details'}
          <hr>
          <h5>Datenbank-Adresse prüfen</h5>
          <p class="bratonien-base-note">Die bekannte Reader-Verbindung konnte mit der gespeicherten Adresse nicht bestätigt werden.</p>
          {if $NC_CONNECTOR.wizard.technical_error}<details><summary>Technische Details</summary><p class="bratonien-main-cache__warning">{$NC_CONNECTOR.wizard.technical_error|escape:html}</p></details>{/if}
          <form method="post" data-bratonien-wizard-form>
            <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
            <div class="bratonien-form-grid">
              <label class="bratonien-label">Datenbank-Adresse</label><input name="nc_wizard_db_host" type="text" value="{$NC_CONNECTOR.wizard.db_host|escape:html}" required>
              <label class="bratonien-label">Port</label><input name="nc_wizard_db_port" type="number" min="1" max="65535" value="{$NC_CONNECTOR.wizard.db_port|escape:html}" required>
              <label class="bratonien-label">Datenbank</label><input name="nc_wizard_db_database" type="text" value="{$NC_CONNECTOR.wizard.db_database|escape:html}" required>
            </div>
            <p><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_save_technical">Erneut prüfen</button></p>
          </form>

        {elseif $NC_CONNECTOR.wizard.technical_stage == 'mounts'}
          <hr>
          {if $NC_CONNECTOR.wizard.directory_selection_ready}
            <h5>Verzeichnisse auswählen</h5>
            <p class="bratonien-base-note">Es werden nur Verzeichnisse angezeigt, auf die <strong>{$NC_CONNECTOR.wizard.username|escape:html}</strong> in Nextcloud Zugriff hat. Mehrere Verzeichnisse können hinzugefügt werden. Bleibt die Auswahl leer, wird automatisch das Stammverzeichnis verwendet.</p>

            <div class="bratonien-form-grid">
              <span class="bratonien-label">Ausgewählt</span>
              <div>
                {if $NC_CONNECTOR.wizard.directory_selected|@count > 0}
                  {foreach from=$NC_CONNECTOR.wizard.directory_selected item=selected_path}
                    <form method="post" class="bratonien-actions" style="margin:.25rem 0" data-bratonien-wizard-form>
                      <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
                      <input type="hidden" name="nc_wizard_directory_remove" value="{$selected_path|escape:html}">
                      <strong style="flex:1">{if $selected_path}{$selected_path|escape:html}{else}Stammverzeichnis{/if}</strong>
                      <button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_directory_remove">Entfernen</button>
                    </form>
                  {/foreach}
                {else}
                  <span class="bratonien-base-note">Keine Auswahl – Stammverzeichnis wird verwendet.</span>
                {/if}
              </div>

              <span class="bratonien-label">Aktueller Ordner</span><strong>/{if $NC_CONNECTOR.wizard.directory_path}{$NC_CONNECTOR.wizard.directory_path|escape:html}{/if}</strong>
            </div>

            <div class="bratonien-actions" style="margin:.75rem 0">
              <form method="post" data-bratonien-wizard-form>
                <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
                <button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_directory_add">Diesen Ordner hinzufügen</button>
              </form>
              {if $NC_CONNECTOR.wizard.directory_path}
                <form method="post" data-bratonien-wizard-form>
                  <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
                  <input type="hidden" name="nc_wizard_directory_path" value="{$NC_CONNECTOR.wizard.directory_parent|escape:html}">
                  <button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_directory_browse">Eine Ebene hoch</button>
                </form>
              {/if}
            </div>

            <div style="margin:.75rem 0">
              {if $NC_CONNECTOR.wizard.directory_children|@count > 0}
                {foreach from=$NC_CONNECTOR.wizard.directory_children item=directory_name key=directory_path}
                  <form method="post" style="margin:.3rem 0" data-bratonien-wizard-form>
                    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
                    <input type="hidden" name="nc_wizard_directory_path" value="{$directory_path|escape:html}">
                    <button class="buttonLike" style="width:100%;text-align:left" type="submit" name="bratonien_tool" value="nc_connector_wizard_directory_browse">📁 {$directory_name|escape:html}</button>
                  </form>
                {/foreach}
              {else}
                <p class="bratonien-base-note">Keine Unterordner vorhanden.</p>
              {/if}
            </div>

            <form method="post" data-bratonien-wizard-form>
              <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
              {foreach from=$NC_CONNECTOR.wizard.storage_candidates item=storage key=storage_index}
                <input type="hidden" name="nc_wizard_storage_mount[{$storage_index|escape:html}]" value="{$storage.local_mount|escape:html}">
              {/foreach}
              <p><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_save_mounts">Verzeichnisse übernehmen</button></p>
            </form>
          {else}
            <h5>Speicherort bestätigen</h5>
            <p class="bratonien-base-note">Die Datenquelle wurde gefunden. Ein technischer Speicherort konnte nicht automatisch zugeordnet werden.</p>
            <form method="post" data-bratonien-wizard-form>
              <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
              <div class="bratonien-form-grid">
                {foreach from=$NC_CONNECTOR.wizard.storage_candidates item=storage key=storage_index}
                  <span class="bratonien-label">Speicher {$storage_index+1}</span>
                  {if $storage.local_mount}
                    <strong>Automatisch erkannt</strong>
                    <input type="hidden" name="nc_wizard_storage_mount[{$storage_index|escape:html}]" value="{$storage.local_mount|escape:html}">
                  {else}
                    <input name="nc_wizard_storage_mount[{$storage_index|escape:html}]" type="text" placeholder="Eingebundener Speicherpfad" required>
                  {/if}
                {/foreach}
              </div>
              <p><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_save_mounts">Speicher prüfen</button></p>
            </form>
          {/if}

        {elseif $NC_CONNECTOR.wizard.technical_complete}
          <hr>
          <h5>Verbindung benennen</h5>
          <p class="bratonien-base-note">Diese Verbindung verwendet ausschließlich den in Schritt 1 angemeldeten Nextcloud-Benutzer.</p>
          <form method="post" data-bratonien-wizard-form>
            <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
            <div class="bratonien-form-grid">
              <label class="bratonien-label" for="nc_wizard_connection_name">Name der Verbindung</label><input id="nc_wizard_connection_name" name="nc_wizard_connection_name" type="text" value="{$NC_CONNECTOR.wizard.connection_name|escape:html}" required>
              <span class="bratonien-label">Nextcloud-Benutzer</span><strong>{$NC_CONNECTOR.wizard.username|escape:html}</strong>
            </div>
            <p><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_select_user">Weiter</button></p>
          </form>
        {/if}

        <div class="bratonien-actions" style="margin-top:1rem">
          <form method="post" data-bratonien-wizard-form><input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}"><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_back">Zurück</button></form>
          <form method="post" data-bratonien-wizard-form><input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}"><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_reset" data-bratonien-wizard-end>Neu beginnen</button></form>
        </div>

      {elseif $NC_CONNECTOR.wizard.step == 3}
        <p class="bratonien-base-note"><strong>Nextcloud ist vorbereitet.</strong> Jetzt wird der bevorzugte Piwigo-Zugang geprüft.</p>
        {if $NC_CONNECTOR.wizard.api_error}<p class="bratonien-main-cache__warning"><strong>API-Test fehlgeschlagen:</strong> {$NC_CONNECTOR.wizard.api_error|escape:html}</p>{/if}
        <form method="post" data-bratonien-wizard-form>
          <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
          <div class="bratonien-form-grid">
            <label class="bratonien-label" for="nc_wizard_api_key_id">API-Schlüssel-ID</label><input id="nc_wizard_api_key_id" name="nc_wizard_api_key_id" type="text" autocomplete="off" value="{$NC_CONNECTOR.wizard._api_key_id|escape:html}" placeholder="leer = bereits gespeicherten Zugang testen">
            <label class="bratonien-label" for="nc_wizard_api_key_secret">API-Geheimnis</label><input id="nc_wizard_api_key_secret" name="nc_wizard_api_key_secret" type="password" autocomplete="off" value="{$NC_CONNECTOR.wizard._api_key_secret|escape:html}" placeholder="leer = bereits gespeicherten Zugang testen">
          </div>
          <div class="bratonien-actions"><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_api_test">API testen</button><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_api_skip" formnovalidate>Überspringen</button></div>
        </form>
        <form method="post" style="margin-top:1rem" data-bratonien-wizard-form><input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}"><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_back">Zurück</button></form>

      {elseif $NC_CONNECTOR.wizard.step == 4}
        <p class="bratonien-base-note"><strong>Fast fertig.</strong> Die Verbindung wurde noch nicht angelegt. Erst der letzte Button übernimmt die Einstellungen.</p>
        <div class="bratonien-form-grid">
          <span class="bratonien-label">Verbindung</span><strong>{$NC_CONNECTOR.wizard.connection_name|escape:html}</strong>
          <span class="bratonien-label">Nextcloud</span><strong>{$NC_CONNECTOR.wizard.base_url|escape:html}</strong>
          <span class="bratonien-label">Nextcloud-Benutzer</span><strong>{$NC_CONNECTOR.wizard.username|escape:html}</strong>
          <span class="bratonien-label">Piwigo-API</span><strong>{if $NC_CONNECTOR.wizard.api_status == 'ok'}Erfolgreich geprüft{else}Übersprungen{/if}</strong>
        </div>
        <hr>
        <h5>Fallback</h5>
        {if $NC_CONNECTOR.wizard.api_status == 'ok'}<p class="bratonien-base-note">Optional. Wird nur verwendet, falls die API später nicht verfügbar ist.</p>{else}<p class="bratonien-main-cache__warning">Ohne API ist ein Fallback-Zugang erforderlich.</p>{/if}
        <form method="post" data-bratonien-wizard-form>
          <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
          <div class="bratonien-form-grid">
            <label class="bratonien-label" for="nc_wizard_fallback_user">Piwigo-Benutzer</label><input id="nc_wizard_fallback_user" name="nc_wizard_fallback_user" type="text" autocomplete="username" value="{$NC_CONNECTOR.wizard._fallback_user|escape:html}"{if $NC_CONNECTOR.wizard.api_status != 'ok'} required{/if}>
            <label class="bratonien-label" for="nc_wizard_fallback_password">Piwigo-Passwort</label><input id="nc_wizard_fallback_password" name="nc_wizard_fallback_password" type="password" autocomplete="current-password" value="{$NC_CONNECTOR.wizard._fallback_password|escape:html}"{if $NC_CONNECTOR.wizard.api_status != 'ok'} required{/if}>
          </div>
          <div class="bratonien-actions"><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_finish" data-bratonien-wizard-end>Verbindung anlegen</button><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_reset" formnovalidate data-bratonien-wizard-end>Abbrechen</button></div>
        </form>
        <form method="post" style="margin-top:1rem" data-bratonien-wizard-form><input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}"><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_back">Zurück</button></form>
      {/if}
    </div>
  </dialog>

  <details id="bratonien-nc-technical-create" class="bratonien-card" style="margin-top:1.5rem">
    <summary style="display:none">Ohne Assistent anlegen</summary>
    <h4>Technische Einrichtung</h4>
    <p class="bratonien-main-cache__warning">Nur verwenden, wenn die technische Zuordnung bekannt ist. Falsche Pfade können die Synchronisierung auf den falschen Datenbestand richten.</p>
    <form method="post">
      <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
      <div class="bratonien-form-grid">
        <label class="bratonien-label">Name</label><input name="nc_name" type="text" required>
        <label class="bratonien-label">PostgreSQL-Host</label><input name="nc_host" type="text" required>
        <label class="bratonien-label">PostgreSQL-Port</label><input name="nc_port" type="number" min="1" max="65535" value="5432" required>
        <label class="bratonien-label">Datenbank</label><input name="nc_database" type="text" value="nextcloud" required>
        <label class="bratonien-label">Reader-Benutzer</label><input name="nc_user" type="text" required>
        <label class="bratonien-label">Reader-Passwort</label><input name="nc_db_password" type="password" autocomplete="new-password" required>
        <label class="bratonien-label">Source-View</label><input name="nc_source_view" type="text" value="piwigo_showcase_sources" required>
        <label class="bratonien-label">Activity-View</label><input name="nc_activity_view" type="text" value="piwigo_showcase_activity" required>
        <label class="bratonien-label">Piwigo-Galeriepfad</label><input name="nc_gallery_root" type="text" placeholder="/var/www/piwigo/galleries/nextcloud" required>
        <label class="bratonien-label">Fallback-Benutzer</label><input name="nc_piwigo_user" type="text">
        <label class="bratonien-label">Fallback-Passwort</label><input name="nc_piwigo_password" type="password" autocomplete="new-password">
        <label class="bratonien-label">Ruhezeit</label><input name="nc_quiet_seconds" type="number" min="0" value="120">
        <label class="bratonien-label">Maximale Wartezeit</label><input name="nc_max_wait_seconds" type="number" min="60" value="900">
        <label class="bratonien-label">Vollprüfung nach</label><input name="nc_full_sync_seconds" type="number" min="300" value="86400">
      </div>
      <p><strong>Storage-Zuordnungen</strong></p><p class="bratonien-base-note">Format: Storage-ID | optionales Quellpräfix | lokaler Speicherpfad</p><textarea name="nc_storages" rows="4" style="width:100%" required></textarea>
      <p><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_create_local">Verbindung anlegen</button></p>
    </form>
  </details>

  <div class="bratonien-card" style="margin-top:1.5rem">
    <h4>Bestehende Verbindungen</h4>
    {if $NC_CONNECTOR.connection_count > 0}
      {foreach from=$NC_CONNECTOR.connections item=connection}
        <details style="margin:.6rem 0">
          <summary><strong>{$connection.display_name|escape:html}</strong> · {if $connection.enabled}aktiv{else}{$connection.takeover_state|escape:html}{/if}</summary>
          <div style="padding:.75rem 0">
            <form method="post" class="bratonien-actions"><input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}"><input type="hidden" name="connection_id" value="{$connection.id|escape:html}"><input name="connection_name" type="text" value="{$connection.name|escape:html}" required><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_update_name">Name speichern</button></form>
            <div class="bratonien-actions" style="margin-top:.6rem">
              {if $connection.adapter == 'local' && !$connection.enabled}<form method="post"><input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}"><input type="hidden" name="connection_id" value="{$connection.id|escape:html}"><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_verify">Verbindung prüfen</button></form>{/if}
              {if !$connection.enabled && $connection.takeover_state != 'active'}<form method="post"><input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}"><input type="hidden" name="connection_id" value="{$connection.id|escape:html}"><button class="buttonLike bratonien-delete-button" type="submit" name="bratonien_tool" value="nc_connector_delete" onclick="return confirm('Verbindung wirklich löschen? Bilder in Nextcloud oder Piwigo werden nicht gelöscht.');">Löschen</button></form>{/if}
            </div>
            {if isset($connection.last_sync) && $connection.last_sync.timestamp > 0}<p class="bratonien-base-note"><strong>Letzter Abgleich:</strong> {$connection.last_sync.label|escape:html} · {$connection.last_sync.message|escape:html}</p>{/if}
            {if $connection.verification_checks|@count > 0}<details><summary>Letzte Prüfung</summary><ul>{foreach from=$connection.verification_checks item=check}<li>{if $check.ok}✓{else}✗{/if} {$check.name|escape:html}: {$check.detail|escape:html}</li>{/foreach}</ul></details>{/if}
            <details style="margin-top:.75rem"><summary>Technische Einstellungen</summary>
              {if $connection.enabled || $connection.takeover_state == 'active'}<p class="bratonien-main-cache__warning">Aktive Verbindungen können technisch nicht geändert werden.</p>{else}
                <p class="bratonien-main-cache__warning">Nur ändern, wenn die technische Zuordnung bekannt ist.</p>
                <form method="post"><input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}"><input type="hidden" name="connection_id" value="{$connection.id|escape:html}">
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
                  <p><strong>Storage-Zuordnungen</strong></p><p class="bratonien-base-note">Format: Storage-ID | optionales Quellpräfix | lokaler Speicherpfad</p><textarea name="nc_storages" rows="4" style="width:100%" required>{$connection.storage_text|escape:html}</textarea>
                  <p><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_update_technical">Technische Einstellungen speichern</button></p>
                </form>
              {/if}
            </details>
          </div>
        </details>
      {/foreach}
    {else}<p class="bratonien-base-note">Noch keine Verbindung vorhanden.</p>{/if}
  </div>

  <details class="bratonien-card" style="margin-top:1.5rem"><summary>Erweiterte Piwigo-Zugänge</summary>
    <h4>Piwigo API</h4>
    <form method="post"><input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}"><div class="bratonien-form-grid"><label class="bratonien-label">API-Schlüssel-ID</label><input name="nc_piwigo_api_key_id" type="text" autocomplete="off" required><label class="bratonien-label">API-Geheimnis</label><input name="nc_piwigo_api_key_secret" type="password" autocomplete="off" required></div><div class="bratonien-actions"><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_piwigo_api_test">API prüfen und speichern</button><button class="buttonLike bratonien-delete-button" type="submit" name="bratonien_tool" value="nc_connector_piwigo_api_delete" formnovalidate>Gespeicherte API löschen</button></div></form>
    <h4 style="margin-top:1rem">Fallback speichern</h4>
    {if $NC_CONNECTOR.connection_count > 0}<form method="post"><input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}"><div class="bratonien-form-grid"><label class="bratonien-label">Verbindung</label><select name="connection_id" required>{foreach from=$NC_CONNECTOR.connections item=connection}<option value="{$connection.id|escape:html}">{$connection.name|escape:html}</option>{/foreach}</select><label class="bratonien-label">Piwigo-Benutzer</label><input name="nc_fallback_user" type="text" autocomplete="username"><label class="bratonien-label">Piwigo-Passwort</label><input name="nc_fallback_password" type="password" autocomplete="current-password"></div><div class="bratonien-actions"><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_fallback_save">Fallback speichern</button><button class="buttonLike bratonien-delete-button" type="submit" name="bratonien_tool" value="nc_connector_fallback_delete" formnovalidate>Fallback löschen</button></div></form>{/if}
    <h4 style="margin-top:1rem">Einmaliger Fallback-Test</h4>
    <p class="bratonien-base-note">Verwendet die eingegebenen Piwigo-Zugangsdaten einmalig und speichert sie nicht.</p>
    <form method="post"><input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}"><div class="bratonien-form-grid"><label class="bratonien-label">Piwigo-Benutzer</label><input name="nc_fallback_user" type="text" required><label class="bratonien-label">Piwigo-Passwort</label><input name="nc_fallback_password" type="password" required></div><p><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_fallback_once">Einmalig testen</button></p></form>
  </details>

{literal}
<script>
(function(){
  var dialog=document.getElementById('bratonien-nc-wizard-dialog');
  var openButton=document.getElementById('bratonien-nc-wizard-open');
  var closeButton=document.getElementById('bratonien-nc-wizard-close');
  var technicalButton=document.getElementById('bratonien-nc-technical-open');
  var technical=document.getElementById('bratonien-nc-technical-create');
  var key='bratonienNcWizardOpen';
  function openWizard(){try{sessionStorage.setItem(key,'1');}catch(e){} if(typeof dialog.showModal==='function'&&!dialog.open)dialog.showModal();else dialog.setAttribute('open','open');}
  function closeWizard(){try{sessionStorage.removeItem(key);}catch(e){} if(typeof dialog.close==='function')dialog.close();else dialog.removeAttribute('open');}
  if(openButton&&dialog)openButton.addEventListener('click',openWizard);
  if(closeButton&&dialog)closeButton.addEventListener('click',closeWizard);
  if(dialog){
    dialog.addEventListener('cancel',function(){try{sessionStorage.removeItem(key);}catch(e){}});
    dialog.addEventListener('click',function(e){if(e.target===dialog)closeWizard();});
    dialog.querySelectorAll('form[data-bratonien-wizard-form]').forEach(function(form){form.addEventListener('submit',function(e){var s=e.submitter;try{if(s&&s.hasAttribute('data-bratonien-wizard-end'))sessionStorage.removeItem(key);else sessionStorage.setItem(key,'1');}catch(x){}});});
    try{if(sessionStorage.getItem(key)==='1')openWizard();}catch(e){}
  }
  if(technicalButton&&technical)technicalButton.addEventListener('click',function(){technical.open=!technical.open;if(technical.open)technical.scrollIntoView(true);});
})();
</script>
{/literal}
</section>