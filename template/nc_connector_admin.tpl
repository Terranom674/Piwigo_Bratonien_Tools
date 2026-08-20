<section class="bratonien-section" id="nc-connector">
  <h3>NC Connector</h3>
  <p class="bratonien-section__intro">Verbindet Nextcloud per WebDAV mit Piwigo und hält die ausgewählten Bilder automatisch aktuell.</p>

  {assign var=nc_system_available value=isset($NC_CONNECTOR.system)}

  <div class="bratonien-grid">
    <div class="bratonien-card">
      <h4>Status</h4>
      <div class="bratonien-form-grid">
        <span class="bratonien-label">WebDAV-Verbindungen</span><strong>{$NC_CONNECTOR.connection_count|escape:html}</strong>
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
      <p class="bratonien-base-note">Nextcloud wird ausschließlich per WebDAV angebunden.</p>
      <div class="bratonien-actions">
        <button class="buttonLike" type="button" id="bratonien-nc-wizard-open">WebDAV-Verbindung anlegen</button>
      </div>
    </div>
  </div>

  <dialog id="bratonien-nc-wizard-dialog" style="width:min(980px,calc(100vw - 3rem));max-height:88vh;overflow:auto;background:#444;color:inherit;border:1px solid #777;border-radius:4px;padding:0;box-shadow:0 18px 60px rgba(0,0,0,.55)">
    <div style="padding:1.25rem 1.5rem">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1rem">
        <div>
          <h4 style="margin:0">Neue WebDAV-Verbindung</h4>
          <p class="bratonien-base-note" style="margin:.35rem 0 0"><strong>
            {if $NC_CONNECTOR.wizard.step == 1}Anmeldung
            {elseif $NC_CONNECTOR.wizard.step == 2 && $NC_CONNECTOR.wizard.technical_stage == 'mounts'}Verzeichnisse auswählen
            {elseif $NC_CONNECTOR.wizard.step == 2 && $NC_CONNECTOR.wizard.technical_complete}Verbindung benennen
            {elseif $NC_CONNECTOR.wizard.step == 3}Piwigo-API
            {elseif $NC_CONNECTOR.wizard.step == 4}Abschluss
            {else}Einrichtung{/if}
          </strong></p>
        </div>
        <button class="buttonLike" type="button" id="bratonien-nc-wizard-close">Schließen</button>
      </div>

      {if $NC_CONNECTOR.wizard.step == 1}
        <p class="bratonien-base-note">Adresse und Nextcloud-Zugang genügen. Der Assistent prüft HTTP/HTTPS und anschließend den WebDAV-Zugriff des angemeldeten Benutzers.</p>
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
        <p class="bratonien-base-note"><strong>Nextcloud wurde gefunden.</strong> Angezeigt werden ausschließlich Verzeichnisse des angemeldeten Nextcloud-Benutzers.</p>
        <div class="bratonien-form-grid">
          <span class="bratonien-label">Adresse</span><strong>{$NC_CONNECTOR.wizard.base_url|escape:html}</strong>
          <span class="bratonien-label">Version</span><strong>{if $NC_CONNECTOR.wizard.version}{$NC_CONNECTOR.wizard.version|escape:html}{else}Nicht gemeldet{/if}</strong>
          <span class="bratonien-label">Angemeldet als</span><strong>{$NC_CONNECTOR.wizard.username|escape:html}{if $NC_CONNECTOR.wizard.display_name} · {$NC_CONNECTOR.wizard.display_name|escape:html}{/if}</strong>
        </div>

        {if $NC_CONNECTOR.wizard.technical_stage == 'mounts' && $NC_CONNECTOR.wizard.directory_selection_ready}
          <hr>
          <h5>Verzeichnisse auswählen</h5>
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
                <span class="bratonien-base-note">Noch kein Verzeichnis ausgewählt.</span>
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
            <p><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_save_mounts">Verzeichnisse übernehmen</button></p>
          </form>
        {elseif $NC_CONNECTOR.wizard.technical_complete}
          <hr>
          <h5>Verbindung benennen</h5>
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
        <p class="bratonien-base-note"><strong>WebDAV ist vorbereitet.</strong> Jetzt wird der Piwigo-Zugang dieser Verbindung geprüft.</p>
        {if $NC_CONNECTOR.wizard.api_error}<p class="bratonien-main-cache__warning"><strong>API-Test fehlgeschlagen:</strong> {$NC_CONNECTOR.wizard.api_error|escape:html}</p>{/if}
        <form method="post" data-bratonien-wizard-form>
          <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
          <div class="bratonien-form-grid">
            <label class="bratonien-label" for="nc_wizard_api_key_id">API-Schlüssel-ID</label><input id="nc_wizard_api_key_id" name="nc_wizard_api_key_id" type="text" autocomplete="off" value="{$NC_CONNECTOR.wizard._api_key_id|escape:html}">
            <label class="bratonien-label" for="nc_wizard_api_key_secret">API-Geheimnis</label><input id="nc_wizard_api_key_secret" name="nc_wizard_api_key_secret" type="password" autocomplete="off" value="{$NC_CONNECTOR.wizard._api_key_secret|escape:html}">
          </div>
          <div class="bratonien-actions"><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_api_test">API testen</button><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_api_skip" formnovalidate>Überspringen</button></div>
        </form>
        <form method="post" style="margin-top:1rem" data-bratonien-wizard-form><input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}"><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_wizard_back">Zurück</button></form>

      {elseif $NC_CONNECTOR.wizard.step == 4}
        <p class="bratonien-base-note"><strong>Fast fertig.</strong> Erst der letzte Button legt die WebDAV-Verbindung an.</p>
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

  <div class="bratonien-card" style="margin-top:1.5rem">
    <h4>Bestehende WebDAV-Verbindungen</h4>
    {if $NC_CONNECTOR.connection_count > 0}
      {foreach from=$NC_CONNECTOR.connections item=connection}
        <details style="margin:.6rem 0">
          <summary><strong>{$connection.display_name|escape:html}</strong> · aktiv{if isset($connection.last_sync) && ($connection.last_sync.state == 'error' || $connection.last_sync.state == 'warning')} · Laufzeitproblem{/if}</summary>
          <div style="padding:.75rem 0">
            <form method="post" class="bratonien-actions"><input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}"><input type="hidden" name="connection_id" value="{$connection.id|escape:html}"><input name="connection_name" type="text" value="{$connection.name|escape:html}" required><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_update_name">Name speichern</button></form>
            <div class="bratonien-actions" style="margin-top:.6rem">
              <form method="post"><input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}"><input type="hidden" name="connection_id" value="{$connection.id|escape:html}"><button class="buttonLike bratonien-delete-button" type="submit" name="bratonien_tool" value="nc_connector_delete" onclick="return confirm('WebDAV-Verbindung wirklich löschen? Die zugehörigen Piwigo-Inhalte dieser Verbindung werden entfernt; Nextcloud-Dateien bleiben unverändert.');">Löschen</button></form>
            </div>
            {if isset($connection.last_sync) && $connection.last_sync.timestamp > 0}
              <p class="bratonien-base-note"><strong>Letzter Abgleich:</strong> {$connection.last_sync.label|escape:html} · {$connection.last_sync.message|escape:html}</p>
              {if $connection.last_sync.state == 'error'}<p class="bratonien-main-cache__warning"><strong>Laufzeitfehler:</strong> {$connection.last_sync.message|escape:html}</p>{/if}
              {if $connection.last_sync.state == 'warning'}<p class="bratonien-main-cache__warning"><strong>Laufzeitwarnung:</strong> {$connection.last_sync.message|escape:html}</p>{/if}
              {if $connection.last_sync.api_state == 'error'}<p class="bratonien-main-cache__warning"><strong>API:</strong> {$connection.last_sync.api_message|escape:html}</p>{/if}
              {if $connection.last_sync.fallback_state == 'error'}<p class="bratonien-main-cache__warning"><strong>Fallback:</strong> {$connection.last_sync.fallback_message|escape:html}</p>{/if}
              {if $connection.last_sync.error_detail}<details><summary>Technische Laufzeitdetails</summary><p class="bratonien-main-cache__warning">{$connection.last_sync.error_detail|escape:html}</p></details>{/if}
            {else}
              <p class="bratonien-base-note"><strong>Laufzeit:</strong> Noch kein Laufstatus für diese Verbindung vorhanden.</p>
            {/if}
            <p class="bratonien-base-note"><strong>Nextcloud:</strong> {$connection.config.nextcloud_url|escape:html}</p>
            <p class="bratonien-base-note"><strong>Nextcloud-Benutzer:</strong> {$connection.user|escape:html}</p>
            <p class="bratonien-base-note"><strong>Ausgewählte Wurzeln:</strong> {$connection.storage_count|escape:html}</p>
            <p class="bratonien-base-note"><strong>Piwigo-Zugang:</strong> {if $connection.config.api_enabled}eigene API{elseif $connection.fallback_stored}Fallback{else}kein Zugang gespeichert{/if}</p>
          </div>
        </details>
      {/foreach}
    {else}<p class="bratonien-base-note">Noch keine WebDAV-Verbindung vorhanden.</p>{/if}
  </div>

  {if $NC_CONNECTOR.connection_count > 0}
  <details class="bratonien-card" style="margin-top:1.5rem"><summary>Fallback-Zugang einer Verbindung ändern</summary>
    <form method="post"><input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}"><div class="bratonien-form-grid"><label class="bratonien-label">Verbindung</label><select name="connection_id" required>{foreach from=$NC_CONNECTOR.connections item=connection}<option value="{$connection.id|escape:html}">{$connection.name|escape:html}</option>{/foreach}</select><label class="bratonien-label">Piwigo-Benutzer</label><input name="nc_fallback_user" type="text" autocomplete="username"><label class="bratonien-label">Piwigo-Passwort</label><input name="nc_fallback_password" type="password" autocomplete="current-password"></div><div class="bratonien-actions"><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_fallback_save">Fallback speichern</button><button class="buttonLike bratonien-delete-button" type="submit" name="bratonien_tool" value="nc_connector_fallback_delete" formnovalidate>Fallback löschen</button></div></form>
  </details>
  {/if}

{literal}
<script>
(function(){
  var dialog=document.getElementById('bratonien-nc-wizard-dialog');
  var openButton=document.getElementById('bratonien-nc-wizard-open');
  var closeButton=document.getElementById('bratonien-nc-wizard-close');
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
})();
</script>
{/literal}
</section>
