<section class="bratonien-section" id="bilddateien">
  <h3>Bilddateien & Pfade</h3>
  <p class="bratonien-section__intro">Zentrale Ablage fuer Logos, Hintergruende und andere Bilder, die Piwigo oder Plugins als Pfad relativ zur Installation erwarten.</p>

  <div class="bratonien-grid">
    <div class="bratonien-card">
      <h4>Pfadinformation</h4>
      <div class="bratonien-form-grid">
        <span class="bratonien-label">Piwigo-Installationspfad</span><code>{$ASSET_ENV.root|escape:html}</code>
        <span class="bratonien-label">Relativer Asset-Pfad</span><code>{$ASSET_ENV.relative_dir|escape:html}</code>
        <span class="bratonien-label">Serverpfad der Ablage</span><code>{$ASSET_ENV.absolute_dir|escape:html}</code>
        <span class="bratonien-label">Beschreibbar</span><strong>{if $ASSET_ENV.writable}Ja{elseif $ASSET_ENV.exists}Nein{else}Wird beim ersten Upload angelegt{/if}</strong>
        <span class="bratonien-label">PHP Upload-Limit</span><span>{$ASSET_ENV.upload_max|escape:html} (POST: {$ASSET_ENV.post_max|escape:html})</span>
      </div>
      <p class="bratonien-base-note">Wenn eine Einstellung einen Pfad „relativ zum Piwigo-Installationsverzeichnis“ verlangt, kopierst du den angezeigten relativen Dateipfad unveraendert.</p>
    </div>

    <div class="bratonien-card">
      <h4>Bild hochladen</h4>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
        <input type="file" name="asset_upload" accept="image/jpeg,image/png,image/gif,image/webp" required>
        <div class="bratonien-actions">
          <button class="buttonLike" type="submit" name="bratonien_tool" value="asset_upload">Bild hochladen</button>
        </div>
      </form>
      <p class="bratonien-base-note">Unterstuetzt: JPG, JPEG, PNG, GIF und WEBP. Dateien werden dauerhaft unter <code>{$ASSET_ENV.relative_dir|escape:html}</code> abgelegt und bleiben bei Plugin-Updates erhalten.</p>
    </div>
  </div>

  <div class="bratonien-card" style="margin-top:16px;">
    <h4>Upload-Limits</h4>
    {if $ASSET_ENV.upload_limits_configurable}
      <form method="post">
        <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
        <div class="bratonien-form-grid">
          <label class="bratonien-label" for="upload_max_mb">Maximale Dateigroesse</label>
          <div><input id="upload_max_mb" type="number" name="upload_max_mb" min="1" max="1024" step="1" value="{$ASSET_ENV.upload_max_mb|escape:html}" style="width:110px;"> MB</div>
          <label class="bratonien-label" for="post_max_mb">Maximale POST-Groesse</label>
          <div><input id="post_max_mb" type="number" name="post_max_mb" min="1" max="2048" step="1" value="{$ASSET_ENV.post_max_mb|escape:html}" style="width:110px;"> MB</div>
        </div>
        <div class="bratonien-actions">
          <button class="buttonLike" type="submit" name="bratonien_tool" value="asset_upload_limits">Upload-Limits speichern</button>
        </div>
      </form>
      <p class="bratonien-base-note">Die Werte werden in <code>{$ASSET_ENV.user_ini_path|escape:html}</code> verwaltet. Das POST-Limit muss mindestens so gross wie das Datei-Limit sein. PHP kann neue Werte wegen seines User-INI-Caches erst nach bis zu {$ASSET_ENV.user_ini_cache_ttl|escape:html} Sekunden anzeigen.</p>
    {else}
      <p class="bratonien-muted">Diese PHP-Konfiguration erlaubt keine automatische Anpassung ueber eine beschreibbare <code>.user.ini</code>. Die Limits muessen dann serverseitig gesetzt werden.</p>
    {/if}
  </div>

  <div class="bratonien-card" style="margin-top:16px;">
    <h4>Vorhandene Bilddateien</h4>
    {if $BRATONIEN_ASSETS|@count}
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;">
        {foreach from=$BRATONIEN_ASSETS item=asset}
          <div style="border:1px solid rgba(255,255,255,.12);border-radius:4px;padding:12px;min-width:0;">
            <div style="height:150px;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.15);overflow:hidden;margin-bottom:10px;">
              <img src="{$asset.url|escape:html}" alt="{$asset.file|escape:html}" style="max-width:100%;max-height:100%;object-fit:contain;">
            </div>
            <strong style="display:block;overflow-wrap:anywhere;">{$asset.file|escape:html}</strong>
            <div class="bratonien-muted" style="margin:5px 0 8px;">{if $asset.width}{$asset.width} × {$asset.height} px · {/if}{math equation="x/1024" x=$asset.bytes format="%.1f"} KB</div>
            <label style="display:block;margin-bottom:5px;">Relativer Pfad</label>
            <div class="bratonien-inline" style="align-items:stretch;">
              <input type="text" readonly value="{$asset.relative|escape:html}" style="min-width:0;flex:1;" data-asset-path>
              <button class="buttonLike" type="button" data-copy-asset>Pfad kopieren</button>
            </div>
            <form method="post" style="margin-top:10px;">
              <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
              <input type="hidden" name="asset_file" value="{$asset.file|escape:html}">
              <button class="buttonLike bratonien-delete-button" type="submit" name="bratonien_tool" value="asset_delete" onclick="return confirm('Diese Bilddatei wirklich dauerhaft loeschen?');">Datei loeschen</button>
            </form>
          </div>
        {/foreach}
      </div>
    {else}
      <p class="bratonien-muted">Noch keine Bilddateien in der zentralen Bratonien-Ablage.</p>
    {/if}
  </div>
</section>

<script>
document.addEventListener('click', function (event) {
  var button = event.target.closest('[data-copy-asset]');
  if (!button) return;
  var input = button.parentNode.querySelector('[data-asset-path]');
  if (!input) return;
  var value = input.value;
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(value).then(function () {
      var old = button.textContent;
      button.textContent = 'Kopiert';
      setTimeout(function () { button.textContent = old; }, 1200);
    });
  } else {
    input.select();
    document.execCommand('copy');
  }
});
</script>
