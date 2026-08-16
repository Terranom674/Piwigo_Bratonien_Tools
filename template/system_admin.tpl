<section class="bratonien-section" id="system">
  <h3>System & Updates</h3>
  <p class="bratonien-section__intro">Verwaltung der Bratonien-Tools-Version direkt aus Piwigo.</p>

  <div class="bratonien-grid">
    <div class="bratonien-card">
      <h4>Plugin-Version</h4>
      <div class="bratonien-form-grid">
        <span class="bratonien-label">Installiert</span><strong>{$SELF_UPDATE.current|escape:html}</strong>
        <span class="bratonien-label">Auf GitHub</span><strong>{if $SELF_UPDATE.remote}{$SELF_UPDATE.remote|escape:html}{else}Nicht ermittelt{/if}</strong>
        <span class="bratonien-label">Status</span>
        <strong>{if $SELF_UPDATE.error}Prüfung fehlgeschlagen{elseif $SELF_UPDATE.update_available}Update verfügbar{else}Aktuell{/if}</strong>
      </div>

      {if $SELF_UPDATE.error}
        <p class="bratonien-main-cache__warning">{$SELF_UPDATE.error|escape:html}</p>
      {/if}

      <form method="post" class="bratonien-actions">
        <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
        <button class="buttonLike" type="submit" name="bratonien_tool" value="self_update_check">Nach Update suchen</button>
        <button class="buttonLike" type="submit" name="bratonien_tool" value="self_update_run" {if !$SELF_UPDATE.update_available}disabled{/if} onclick="return confirm('Bratonien Tools jetzt direkt von GitHub aktualisieren? Vorher wird automatisch ein Backup der aktuellen Plugin-Version erstellt.');">Jetzt aktualisieren</button>
      </form>
      <p class="bratonien-base-note">Das Update wird direkt aus dem GitHub-Repository geladen. Vor dem Austausch wird die aktuelle Plugin-Version unter <code>_data/bratonien-plugin-backups/</code> gesichert. Dafür muss der Piwigo-Pluginordner für den Webserver beschreibbar sein.</p>
    </div>

    <div class="bratonien-card">
      <h4>Update-Voraussetzungen</h4>
      <div class="bratonien-form-grid">
        <span class="bratonien-label">Webmaster</span><strong>{if $SELF_UPDATE_ENV.webmaster}Ja{else}Nein{/if}</strong>
        <span class="bratonien-label">Pluginordner beschreibbar</span><strong>{if $SELF_UPDATE_ENV.plugins_writable}Ja{else}Nein{/if}</strong>
        <span class="bratonien-label">ZipArchive</span><strong>{if $SELF_UPDATE_ENV.zip}Verfügbar{else}Fehlt{/if}</strong>
      </div>
      {if !$SELF_UPDATE_ENV.webmaster || !$SELF_UPDATE_ENV.plugins_writable || !$SELF_UPDATE_ENV.zip}
        <p class="bratonien-main-cache__warning">Mindestens eine Voraussetzung für automatische Updates ist noch nicht erfüllt.</p>
      {/if}
    </div>
  </div>
</section>
