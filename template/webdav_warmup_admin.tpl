<div id="bratonien-webdav-warmup-card" class="bratonien-card" style="margin-top:16px;">
  <h4>WebDAV-Quellenindex &amp; Cache-Warmup</h4>
  <p>Bratonien Tools vergleicht den aktuellen Nextcloud-/Connector-Bestand mit einem eigenen kompakten Quellenindex. Nur neue, geänderte oder noch nicht vollständig verarbeitete Quellen werden an Piwigo übergeben. Der Piwigo-Bildcache selbst entscheidet nicht, ob eine Quelle neu ist.</p>
  <p class="bratonien-main-cache__warning"><strong>Patchphase 0.9.7.1.13:</strong> Der Quellenindex speichert unter anderem File-ID, Pfad/Name, ETag, Größe, Format und Abmessungen. Die eigentlichen Derivate erzeugt ausschließlich Piwigo über seinen normalen <code>i.php</code>-Pfad.</p>

  <form method="post" class="bratonien-worker-settings">
    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
    <label><input type="checkbox" name="webdav_warmup_enabled" value="1" {if $CACHE_WORKERS.webdav_warmup.enabled}checked{/if}> Automatischen WebDAV-Warmup aktivieren</label>
    <label>Bilder pro Batch: <input type="number" name="webdav_warmup_batch_size" value="{$CACHE_WORKERS.webdav_warmup.batch_size}" min="1" max="50" step="1"></label>
    <label>Index-Abgleich: <input type="number" name="webdav_warmup_periodic_hours" value="{$CACHE_WORKERS.webdav_warmup.periodic_hours}" min="1" max="168" step="1"> Stunden</label>
    <button class="buttonLike" type="submit" name="bratonien_tool" value="image_cache_webdav_warmup_settings">Warmup-Einstellungen speichern</button>
  </form>

  <div class="bratonien-form-grid" style="margin-top:12px;">
    <span class="bratonien-label">Änderungserkennung</span><span>Connector-Bestand ↔ eigener Quellenindex; Piwigo-Placeholder und Cache-Dateien sind dafür nicht maßgeblich</span>
    <span class="bratonien-label">Stufe 1</span><span>Von Piwigo definierte Derivate bis einschließlich 1920 px längster Kante</span>
    <span class="bratonien-label">Stufe 2</span><span>Danach alle übrigen von Piwigo definierten Derivate</span>
    <span class="bratonien-label">Letzter Status</span><span><strong>{$CACHE_WORKERS.webdav_warmup.status.state|default:'idle'|escape:html}</strong>{if $CACHE_WORKERS.webdav_warmup.status.message} · {$CACHE_WORKERS.webdav_warmup.status.message|escape:html}{/if}</span>
  </div>

  <div class="bratonien-actions">
    <form method="post">
      <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
      <button class="buttonLike" type="submit" name="bratonien_tool" value="image_cache_webdav_warmup_manual">Quellenindex jetzt abgleichen</button>
    </form>
    <form method="post">
      <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
      <button class="buttonLike" type="submit" name="bratonien_tool" value="image_cache_webdav_warmup_audit">Produktive Pfade prüfen</button>
    </form>
  </div>
  <p class="bratonien-base-note">Der Pfadaudit ist schreibgeschützt. Warmup und On-Demand verwenden denselben Bild-Lock; während der produktiven Materialisierung wird zusätzlich der Connector-Sync-Lock geteilt gehalten, damit Source- und Shadow-Tree nicht ausgetauscht werden können.</p>
</div>

{literal}
<script>
(function(){
  function moveWarmupCard(){
    var card=document.getElementById('bratonien-webdav-warmup-card');
    var section=document.getElementById('wartung');
    if(!card||!section||card.parentNode===section)return;
    section.appendChild(card);
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',moveWarmupCard);else moveWarmupCard();
})();
</script>
{/literal}
