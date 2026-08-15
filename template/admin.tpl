<div class="titrePage"><h2>Bratonien Tools</h2></div>

{foreach from=$BRATONIEN_MESSAGES item=message}<div class="infos"><p>{$message|escape:html}</p></div>{/foreach}
{foreach from=$BRATONIEN_ERRORS item=error}<div class="errors"><p>{$error|escape:html}</p></div>{/foreach}

<style>
.bratonien-watermark-preview {
  display: flex;
  align-items: center;
  gap: 24px;
  margin: 18px 0;
  padding: 16px;
  border: 1px solid #666;
  background: rgba(255,255,255,.03);
}
.bratonien-watermark-preview__image {
  width: 260px;
  min-height: 120px;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 12px;
  background:
    linear-gradient(45deg, #555 25%, transparent 25%),
    linear-gradient(-45deg, #555 25%, transparent 25%),
    linear-gradient(45deg, transparent 75%, #555 75%),
    linear-gradient(-45deg, transparent 75%, #555 75%);
  background-size: 20px 20px;
  background-position: 0 0, 0 10px, 10px -10px, -10px 0px;
  box-sizing: border-box;
}
.bratonien-watermark-preview__image img {
  max-width: 100%;
  max-height: 160px;
  object-fit: contain;
}
.bratonien-watermark-preview__empty {
  color: #aaa;
  text-align: center;
}
.bratonien-watermark-preview__meta {
  line-height: 1.6;
}
</style>

<fieldset>
  <legend>Bildcache</legend>
  <p>Loescht alle erzeugten Piwigo- und Bratonien-Bildderivate. Originalbilder bleiben erhalten.</p>
  <form method="post">
    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
    <button class="buttonLike" type="submit" name="bratonien_tool" value="image_cache_clear" onclick="return confirm('Wirklich den gesamten Bildcache leeren?');">Bildcache leeren</button>
  </form>
</fieldset>

<fieldset>
  <legend>Bratonien-Wasserzeichenverwaltung</legend>
  <p>Bei Aktivierung wird Piwigos bisherige Wasserzeichen-Nutzung gesichert und unterdrueckt. Beim Deaktivieren wird der gesicherte Zustand wiederhergestellt.</p>
  <form method="post">
    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
    <label><input type="checkbox" name="engine_enabled" value="1" {if $WATERMARK_ENGINE.enabled}checked{/if}> Bratonien-Wasserzeichenverwaltung aktivieren</label>
    <p><button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_engine">Status speichern</button></p>
  </form>
</fieldset>

<fieldset>
  <legend>Wasserzeichendateien</legend>
  <p>PNG-Dateien liegen im normalen Piwigo-Wasserzeichenordner und koennen sowohl von Piwigo als auch von Bratonien-Profilen verwendet werden.</p>

  <div class="bratonien-watermark-preview">
    <div class="bratonien-watermark-preview__image">
      {if $WATERMARK.preview_url}
        <img src="{$WATERMARK.preview_url|escape:html}" alt="Aktuelles Wasserzeichen">
      {else}
        <div class="bratonien-watermark-preview__empty">Noch kein Wasserzeichen ausgewählt</div>
      {/if}
    </div>
    <div class="bratonien-watermark-preview__meta">
      <strong>Aktuelles Wasserzeichen</strong><br>
      {if $WATERMARK.file}
        {$WATERMARK.file|escape:html}<br>
        Position: {$WATERMARK.xpos} / {$WATERMARK.ypos}<br>
        Deckkraft: {$WATERMARK.opacity}%<br>
        Mindestgröße: {$WATERMARK.minw} × {$WATERMARK.minh}
      {else}
        Keine Datei ausgewählt
      {/if}
    </div>
  </div>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
    <p><label>Datei <select name="watermark_file">{foreach from=$WATERMARK.files key=file item=name}<option value="{$file|escape:html}" {if $file == $WATERMARK.file}selected{/if}>{$name|escape:html}</option>{/foreach}</select></label> <label>Neues PNG <input type="file" name="watermark_upload" accept="image/png"></label></p>
    <p>X <input type="number" name="watermark_xpos" value="{$WATERMARK.xpos}" min="0" max="100" size="4"> Y <input type="number" name="watermark_ypos" value="{$WATERMARK.ypos}" min="0" max="100" size="4"> Deckkraft <input type="number" name="watermark_opacity" value="{$WATERMARK.opacity}" min="1" max="100" size="4">%</p>
    <p>Mindestgroesse <input type="number" name="watermark_minw" value="{$WATERMARK.minw}" min="0" size="5"> x <input type="number" name="watermark_minh" value="{$WATERMARK.minh}" min="0" size="5"> <label><input type="checkbox" name="watermark_clear_cache" value="1"> Bildcache danach leeren</label></p>
    <button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_save">Wasserzeichendatei speichern</button>
  </form>
</fieldset>

<fieldset>
  <legend>Wasserzeichenprofile</legend>
  <p>Profile definieren Datei, Position, Wiederholung, Deckkraft und Mindestgroesse. Inaktive Profile bleiben gespeichert, werden aber nicht angewendet.</p>
  <table class="table2">
    <thead><tr><th>Profil</th></tr></thead>
    <tbody>
    {foreach from=$WATERMARK_PROFILES item=profile}
      <tr><td>
        <form method="post">
          <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
          <input type="hidden" name="profile_id" value="{$profile.id}">
          <p><strong>Name</strong> <input name="profile_name" value="{$profile.name|escape:html}" size="24"> <label><input type="checkbox" name="profile_active" value="1" {if $profile.active}checked{/if}> aktiv</label></p>
          <p><strong>Datei</strong> <select name="profile_file"><option value="">Keine Datei</option>{foreach from=$WATERMARK.files key=file item=name}<option value="{$file|escape:html}" {if $file == $profile.watermark_file}selected{/if}>{$name|escape:html}</option>{/foreach}</select></p>
          <p><strong>Position</strong> X <input type="number" name="profile_xpos" value="{$profile.xpos}" min="0" max="100" size="3"> Y <input type="number" name="profile_ypos" value="{$profile.ypos}" min="0" max="100" size="3"> &nbsp; <strong>Wiederholen</strong> X <input type="number" name="profile_xrepeat" value="{$profile.xrepeat}" min="0" max="20" size="3"> Y <input type="number" name="profile_yrepeat" value="{$profile.yrepeat}" min="0" max="20" size="3"></p>
          <p><strong>Deckkraft</strong> <input type="number" name="profile_opacity" value="{$profile.opacity}" min="1" max="100" size="3">% &nbsp; <strong>Mindestgroesse</strong> <input type="number" name="profile_min_width" value="{$profile.min_width}" min="0" size="4"> x <input type="number" name="profile_min_height" value="{$profile.min_height}" min="0" size="4"></p>
          <button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_profile_save">Speichern</button>
          <button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_profile_duplicate">Duplizieren</button>
          <button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_profile_delete" onclick="return confirm('Profil wirklich loeschen?');">Loeschen</button>
        </form>
      </td></tr>
    {/foreach}
    </tbody>
  </table>

  <h3>Neues Profil</h3>
  <form method="post">
    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
    <p><strong>Name</strong> <input name="profile_name" size="24"> <label><input type="checkbox" name="profile_active" value="1" checked> aktiv</label></p>
    <p><strong>Datei</strong> <select name="profile_file"><option value="">Keine Datei</option>{foreach from=$WATERMARK.files key=file item=name}<option value="{$file|escape:html}">{$name|escape:html}</option>{/foreach}</select></p>
    <p><strong>Position</strong> X <input type="number" name="profile_xpos" value="90" min="0" max="100" size="3"> Y <input type="number" name="profile_ypos" value="90" min="0" max="100" size="3"> &nbsp; <strong>Wiederholen</strong> X <input type="number" name="profile_xrepeat" value="0" min="0" max="20" size="3"> Y <input type="number" name="profile_yrepeat" value="0" min="0" max="20" size="3"></p>
    <p><strong>Deckkraft</strong> <input type="number" name="profile_opacity" value="35" min="1" max="100" size="3">% &nbsp; <strong>Mindestgroesse</strong> <input type="number" name="profile_min_width" value="10" min="0" size="4"> x <input type="number" name="profile_min_height" value="10" min="0" size="4"></p>
    <button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_profile_save">Profil anlegen</button>
  </form>
</fieldset>

<fieldset>
  <legend>Globale Standardregeln</legend>
  <p>Sie greifen, wenn weder das Album noch ein uebergeordnetes Album eine eigene Regel besitzt. Kein ausgewaehltes Profil bedeutet kein Wasserzeichen.</p>
  <form method="post">
    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
    <p>Oeffentliche Alben <select name="public_profile"><option value="">Kein Wasserzeichen</option>{foreach from=$WATERMARK_PROFILES item=profile}{if $profile.active}<option value="{$profile.id}" {if $WATERMARK_DEFAULTS.public_profile == $profile.id}selected{/if}>{$profile.name|escape:html}</option>{/if}{/foreach}</select></p>
    <p>Private Alben <select name="private_profile"><option value="">Kein Wasserzeichen</option>{foreach from=$WATERMARK_PROFILES item=profile}{if $profile.active}<option value="{$profile.id}" {if $WATERMARK_DEFAULTS.private_profile == $profile.id}selected{/if}>{$profile.name|escape:html}</option>{/if}{/foreach}</select></p>
    <button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_defaults">Globale Regeln speichern</button>
  </form>
</fieldset>

<fieldset>
  <legend>Albumregeln</legend>
  <p>Erben verwendet die naechste explizite Regel eines Elternalbums und danach den globalen Standard. Ein Album kann stattdessen ein Profil erzwingen oder Wasserzeichen komplett deaktivieren.</p>
  <table class="table2">
    <thead><tr><th>Albumregeln</th></tr></thead>
    <tbody>
    {foreach from=$WATERMARK_CATEGORIES item=category}
      <tr><td>
        <form method="post">
          <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
          <input type="hidden" name="category_id" value="{$category.id}">
          <strong>{$category.display_name|escape:html}</strong> <small>({$category.status|escape:html})</small><br>
          <select name="rule_mode"><option value="inherit" {if $category.rule.mode == 'inherit'}selected{/if}>Erben</option><option value="disabled" {if $category.rule.mode == 'disabled'}selected{/if}>Kein Wasserzeichen</option><option value="profile" {if $category.rule.mode == 'profile'}selected{/if}>Profil verwenden</option></select>
          <select name="rule_profile"><option value="">Profil waehlen</option>{foreach from=$WATERMARK_PROFILES item=profile}{if $profile.active}<option value="{$profile.id}" {if $category.rule.profile_id == $profile.id}selected{/if}>{$profile.name|escape:html}</option>{/if}{/foreach}</select>
          <span>Wirksam: <strong>{$category.effective_label|escape:html}</strong> ({$category.effective.source|escape:html})</span>
          <button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_rule">Speichern</button>
        </form>
      </td></tr>
    {/foreach}
    </tbody>
  </table>
</fieldset>
