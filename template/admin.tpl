<div class="titrePage"><h2>Bratonien Tools</h2></div>

{foreach from=$BRATONIEN_MESSAGES item=message}<div class="infos"><p>{$message|escape:html}</p></div>{/foreach}
{foreach from=$BRATONIEN_ERRORS item=error}<div class="errors"><p>{$error|escape:html}</p></div>{/foreach}

<style>
.titrePage h2 { color:#e6e6e6; }
.bratonien-admin { max-width:1180px; margin:0 auto; }
.bratonien-nav { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 18px; }
.bratonien-nav a { display:inline-block; padding:8px 12px; border:1px solid rgba(255,255,255,.18); border-radius:4px; color:#d7d7d7; text-decoration:none; transition:color .15s ease,border-color .15s ease,background .15s ease; }
.bratonien-nav a:hover,.bratonien-nav a:focus { color:#f0a646; border-color:rgba(240,166,70,.65); background:rgba(240,166,70,.06); }
.bratonien-section { margin:0 0 22px; padding:18px; border:1px solid rgba(255,255,255,.14); border-radius:5px; background:rgba(255,255,255,.025); }
.bratonien-section h3 { margin:0 0 4px; color:#e6e6e6; font-size:18px; font-weight:700; }
.bratonien-section__intro { margin:0 0 16px; color:#a9a9a9; }
.bratonien-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
.bratonien-card { padding:15px; border:1px solid rgba(255,255,255,.12); border-radius:4px; background:rgba(0,0,0,.08); }
.bratonien-card h4 { margin:0 0 12px; color:#d7d7d7; font-size:15px; font-weight:700; }
.bratonien-status { display:flex; align-items:center; gap:10px; margin-bottom:12px; }
.bratonien-status__dot { width:11px; height:11px; border-radius:50%; background:#777; }
.bratonien-status__dot.is-active { background:#66a845; }
.bratonien-form-grid { display:grid; grid-template-columns:150px minmax(0,1fr); gap:10px 14px; align-items:center; }
.bratonien-form-grid > label,.bratonien-label { font-weight:600; }
.bratonien-inline { display:flex; flex-wrap:wrap; gap:8px 12px; align-items:center; }
.bratonien-actions { display:flex; flex-wrap:wrap; gap:8px; margin-top:14px; align-items:center; }
.bratonien-profile-list { display:grid; gap:10px; }
.bratonien-profile { border:1px solid rgba(255,255,255,.12); border-radius:4px; background:rgba(0,0,0,.07); }
.bratonien-profile summary { cursor:pointer; padding:12px 14px; color:#d7d7d7; font-weight:600; }
.bratonien-profile summary:hover { color:#f0a646; }
.bratonien-profile__body { padding:0 14px 14px; }
.bratonien-profile__summary { margin-left:8px; color:#a9a9a9; font-weight:normal; }
.bratonien-scale-editor { margin-top:16px; padding-top:16px; border-top:1px solid rgba(255,255,255,.1); }
.bratonien-scale-editor__title { margin:0 0 10px; color:#d7d7d7; font-weight:700; }
.bratonien-scale-grid { display:grid; grid-template-columns:minmax(300px,1fr) minmax(280px,380px); gap:18px; align-items:start; }
.bratonien-scale-fields { display:grid; grid-template-columns:150px minmax(0,1fr); gap:10px 14px; align-items:center; }
.bratonien-scale-fields label { font-weight:600; }
.bratonien-scale-fields input[type=number] { width:95px; }
.bratonien-scale-preview__stage { width:100%; height:250px; display:flex; align-items:center; justify-content:center; padding:10px; border:1px solid rgba(255,255,255,.14); border-radius:4px; background:linear-gradient(45deg,rgba(255,255,255,.09) 25%,transparent 25%),linear-gradient(-45deg,rgba(255,255,255,.09) 25%,transparent 25%),linear-gradient(45deg,transparent 75%,rgba(255,255,255,.09) 75%),linear-gradient(-45deg,transparent 75%,rgba(255,255,255,.09) 75%); background-size:20px 20px; background-position:0 0,0 10px,10px -10px,-10px 0; box-sizing:border-box; overflow:hidden; }
.bratonien-scale-preview__stage img { display:block; max-width:none; max-height:none; object-fit:contain; }
.bratonien-scale-preview__empty { color:#a9a9a9; text-align:center; }
.bratonien-scale-preview__info { margin-top:8px; color:#a9a9a9; line-height:1.5; }
.bratonien-lock { color:#a9a9a9; }
.bratonien-base-note { margin:12px 0 0; color:#a9a9a9; font-size:12px; line-height:1.5; }
.bratonien-delete-button { border-color:rgba(210,80,80,.7)!important; }
.bratonien-delete-button:hover,.bratonien-delete-button:focus { border-color:#d65a5a!important; }
.bratonien-rule-table { width:100%; border-collapse:collapse; }
.bratonien-rule-table th,.bratonien-rule-table td { padding:9px 8px; text-align:left; vertical-align:middle; border-bottom:1px solid rgba(255,255,255,.09); }
.bratonien-rule-table th { color:#d7d7d7; }
.bratonien-effective { white-space:nowrap; }
.bratonien-muted { color:#a9a9a9; }
.bratonien-main-cache { margin-top:16px; padding-top:14px; border-top:1px solid rgba(255,255,255,.1); }
.bratonien-main-cache__warning { margin:10px 0; color:#d6ae62; font-size:12px; line-height:1.45; }
.bratonien-main-cache__controls { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.bratonien-main-cache__cancel { display:none; }
.bratonien-worker-settings { display:flex; flex-wrap:wrap; gap:10px 16px; align-items:center; margin:12px 0; padding:10px 12px; border:1px solid rgba(255,255,255,.1); border-radius:4px; background:rgba(0,0,0,.08); }
.bratonien-worker-settings input[type=number] { width:70px; }
.bratonien-worker-settings__state { color:#a9a9a9; }
.bratonien-main-cache__progress { display:none; margin-top:14px; }
.bratonien-main-cache__head { display:flex; justify-content:space-between; gap:12px; margin-bottom:7px; }
.bratonien-main-cache__track { height:18px; overflow:hidden; border:1px solid rgba(255,255,255,.16); border-radius:4px; background:rgba(0,0,0,.25); }
.bratonien-main-cache__bar { width:0; height:100%; background:#66a845; transition:width .25s ease; }
.bratonien-main-cache__progress.is-error .bratonien-main-cache__bar { background:#c95b5b; }
.bratonien-main-cache__progress.is-queued .bratonien-main-cache__bar { background:#a7834d; }
.bratonien-main-cache__progress.is-cancelling .bratonien-main-cache__bar { background:#d6ae62; }
.bratonien-main-cache__details { margin-top:8px; color:#a9a9a9; line-height:1.45; }
.bratonien-main-cache__current { margin-top:4px; font-size:12px; color:#8f8f8f; overflow-wrap:anywhere; }
@media (max-width:850px) {
  .bratonien-grid,.bratonien-scale-grid { grid-template-columns:1fr; }
  .bratonien-form-grid,.bratonien-scale-fields { grid-template-columns:1fr; }
  .bratonien-rule-table { display:block; overflow-x:auto; }
}
</style>

<div class="bratonien-admin">
  <nav class="bratonien-nav" aria-label="Bratonien Tools Navigation">
    <a href="#uebersicht">Übersicht</a><a href="#wasserzeichen">Wasserzeichen</a><a href="#regeln">Regeln</a><a href="#wartung">Wartung</a>
  </nav>

  <section class="bratonien-section" id="uebersicht">
    <h3>Übersicht</h3><p class="bratonien-section__intro">Aktueller Zustand der Bratonien-Wasserzeichenverwaltung.</p>
    <div class="bratonien-grid">
      <div class="bratonien-card"><h4>Engine</h4><div class="bratonien-status"><span class="bratonien-status__dot {if $WATERMARK_ENGINE.enabled}is-active{/if}"></span><strong>{if $WATERMARK_ENGINE.enabled}Aktiv{else}Inaktiv{/if}</strong></div><form method="post"><input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}"><label><input type="checkbox" name="engine_enabled" value="1" {if $WATERMARK_ENGINE.enabled}checked{/if}> Bratonien-Wasserzeichenverwaltung aktivieren</label><div class="bratonien-actions"><button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_engine">Status speichern</button></div></form></div>
      <div class="bratonien-card"><h4>Standardregeln</h4><div class="bratonien-form-grid"><span class="bratonien-label">Öffentliche Alben</span><strong>{if $WATERMARK_DEFAULTS.public_profile}{foreach from=$WATERMARK_PROFILES item=profile}{if $profile.id == $WATERMARK_DEFAULTS.public_profile}{$profile.name|escape:html}{/if}{/foreach}{else}Kein Wasserzeichen{/if}</strong><span class="bratonien-label">Private Alben</span><strong>{if $WATERMARK_DEFAULTS.private_profile}{foreach from=$WATERMARK_PROFILES item=profile}{if $profile.id == $WATERMARK_DEFAULTS.private_profile}{$profile.name|escape:html}{/if}{/foreach}{else}Kein Wasserzeichen{/if}</strong><span class="bratonien-label">Profile</span><span>{$WATERMARK_PROFILES|@count} vorhanden</span></div></div>
    </div>
  </section>

  <section class="bratonien-section" id="wasserzeichen">
    <h3>Wasserzeichen</h3><p class="bratonien-section__intro">Basis-Wasserzeichen direkt vorbereiten und anschließend bei Bedarf pro Profil abweichend konfigurieren.</p>
    <form method="post" enctype="multipart/form-data" data-watermark-editor>
      <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
      <div class="bratonien-grid">
        <div class="bratonien-card"><h4>Vorschau des Basis-Wasserzeichens</h4><div class="bratonien-scale-preview__stage" data-preview-stage>{if $WATERMARK.preview_url}<img src="{$WATERMARK.preview_url|escape:html}" alt="Vorschau" data-preview-image>{else}<span class="bratonien-scale-preview__empty" data-preview-empty>Keine Wasserzeichendatei gewählt</span><img src="" alt="Vorschau" data-preview-image style="display:none">{/if}</div><div class="bratonien-scale-preview__info" data-preview-info></div><p class="bratonien-base-note">Diese Größe ist die Basis für neue Profile. Bereits vorhandene Profile behalten ihre eigene Skalierung.</p></div>
        <div class="bratonien-card"><h4>Datei und Basisgröße</h4><div class="bratonien-form-grid">
          <label for="watermark_file">Vorhandene Datei</label><select id="watermark_file" name="watermark_file" data-watermark-file>{foreach from=$WATERMARK_OPTIONS item=option}<option value="{$option.file|escape:html}" data-width="{$option.width}" data-height="{$option.height}" data-url="{$option.url|escape:html}" {if $option.file == $WATERMARK.file}selected{/if}>{$option.name|escape:html}</option>{/foreach}</select>
          <label for="watermark_upload">Neues PNG</label><input id="watermark_upload" type="file" name="watermark_upload" accept="image/png">
          <span class="bratonien-label">Originalgröße</span><span data-original-size>{if $WATERMARK.original_width}{$WATERMARK.original_width} × {$WATERMARK.original_height} px{else}Keine Datei gewählt{/if}</span>
          <label for="watermark_scale_percent">Skalierung</label><span><input id="watermark_scale_percent" type="number" name="watermark_scale_percent" value="{$WATERMARK.scale_percent}" min="1" max="1000" step="0.1" data-scale-percent> %</span>
          <label>Breite</label><span><input type="number" min="1" step="1" data-scale-width> px</span><label>Höhe</label><span><input type="number" min="1" step="1" data-scale-height> px</span>
          <span class="bratonien-label">Seitenverhältnis</span><span class="bratonien-lock">🔒 gesperrt</span>
          <span class="bratonien-label">Position</span><span class="bratonien-inline">X <input type="number" name="watermark_xpos" value="{$WATERMARK.xpos}" min="0" max="100" size="4"> Y <input type="number" name="watermark_ypos" value="{$WATERMARK.ypos}" min="0" max="100" size="4"></span>
          <label for="watermark_opacity">Deckkraft</label><span><input id="watermark_opacity" type="number" name="watermark_opacity" value="{$WATERMARK.opacity}" min="1" max="100" size="4" data-watermark-opacity> %</span>
          <span class="bratonien-label">Mindestgröße</span><span class="bratonien-inline"><input type="number" name="watermark_minw" value="{$WATERMARK.minw}" min="0" size="5"> × <input type="number" name="watermark_minh" value="{$WATERMARK.minh}" min="0" size="5"></span>
        </div><div class="bratonien-actions"><label><input type="checkbox" name="watermark_clear_cache" value="1"> Bildcache danach leeren</label><button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_save">Basis-Wasserzeichen speichern</button><button class="buttonLike bratonien-delete-button" type="submit" name="bratonien_tool" value="watermark_file_delete" onclick="return confirm('Die aktuell ausgewählte Wasserzeichendatei wirklich dauerhaft löschen?');">Ausgewählte Datei löschen</button></div></div>
      </div>
    </form>

    <div class="bratonien-card" style="margin-top:16px;"><h4>Profile</h4><p class="bratonien-muted">Profile können die Basisgröße bewusst überschreiben. Prozent, Breite und Höhe bleiben dabei immer miteinander gekoppelt.</p><div class="bratonien-profile-list">
      {foreach from=$WATERMARK_PROFILES item=profile}
      <details class="bratonien-profile"><summary>{$profile.name|escape:html}<span class="bratonien-profile__summary">{if $profile.active}aktiv{else}inaktiv{/if} · {$profile.opacity}% · {$profile.scale_percent}% Größe</span></summary><div class="bratonien-profile__body"><form method="post" data-watermark-editor><input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}"><input type="hidden" name="profile_id" value="{$profile.id}"><div class="bratonien-form-grid">
        <label>Name</label><span class="bratonien-inline"><input name="profile_name" value="{$profile.name|escape:html}" size="28"> <label><input type="checkbox" name="profile_active" value="1" {if $profile.active}checked{/if}> aktiv</label></span>
        <label>Datei</label><select name="profile_file" data-watermark-file><option value="" data-width="0" data-height="0" data-url="">Keine Datei</option>{foreach from=$WATERMARK_OPTIONS item=option}<option value="{$option.file|escape:html}" data-width="{$option.width}" data-height="{$option.height}" data-url="{$option.url|escape:html}" {if $option.file == $profile.watermark_file}selected{/if}>{$option.name|escape:html}</option>{/foreach}</select>
        <span class="bratonien-label">Position</span><span class="bratonien-inline">X <input type="number" name="profile_xpos" value="{$profile.xpos}" min="0" max="100" size="3"> Y <input type="number" name="profile_ypos" value="{$profile.ypos}" min="0" max="100" size="3"></span>
        <span class="bratonien-label">Wiederholen</span><span class="bratonien-inline">X <input type="number" name="profile_xrepeat" value="{$profile.xrepeat}" min="0" max="20" size="3"> Y <input type="number" name="profile_yrepeat" value="{$profile.yrepeat}" min="0" max="20" size="3"></span>
        <label>Deckkraft</label><span><input type="number" name="profile_opacity" value="{$profile.opacity}" min="1" max="100" size="3" data-watermark-opacity> %</span><span class="bratonien-label">Mindestgröße</span><span class="bratonien-inline"><input type="number" name="profile_min_width" value="{$profile.min_width}" min="0" size="5"> × <input type="number" name="profile_min_height" value="{$profile.min_height}" min="0" size="5"></span>
      </div><div class="bratonien-scale-editor"><div class="bratonien-scale-editor__title">Größe & Vorschau</div><div class="bratonien-scale-grid"><div class="bratonien-scale-fields"><span class="bratonien-label">Originalgröße</span><span data-original-size>{if $profile.original_width}{$profile.original_width} × {$profile.original_height} px{else}Keine Datei gewählt{/if}</span><label>Skalierung</label><span><input type="number" name="profile_scale_percent" value="{$profile.scale_percent}" min="1" max="1000" step="0.1" data-scale-percent> %</span><label>Breite</label><span><input type="number" min="1" step="1" data-scale-width> px</span><label>Höhe</label><span><input type="number" min="1" step="1" data-scale-height> px</span><span class="bratonien-label">Seitenverhältnis</span><span class="bratonien-lock">🔒 gesperrt</span></div><div><div class="bratonien-scale-preview__stage" data-preview-stage>{if $profile.preview_url}<img src="{$profile.preview_url|escape:html}" alt="Vorschau" data-preview-image>{else}<span class="bratonien-scale-preview__empty" data-preview-empty>Keine Wasserzeichendatei gewählt</span><img src="" alt="Vorschau" data-preview-image style="display:none">{/if}</div><div class="bratonien-scale-preview__info" data-preview-info></div></div></div></div><div class="bratonien-actions"><button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_profile_save">Speichern</button><button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_profile_duplicate">Duplizieren</button><button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_profile_delete" onclick="return confirm('Profil wirklich löschen?');">Löschen</button></div></form></div></details>
      {/foreach}
      <details class="bratonien-profile"><summary>+ Neues Profil</summary><div class="bratonien-profile__body"><form method="post" data-watermark-editor><input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}"><div class="bratonien-form-grid"><label>Name</label><span class="bratonien-inline"><input name="profile_name" size="28"> <label><input type="checkbox" name="profile_active" value="1" checked> aktiv</label></span><label>Datei</label><select name="profile_file" data-watermark-file><option value="" data-width="0" data-height="0" data-url="">Keine Datei</option>{foreach from=$WATERMARK_OPTIONS item=option}<option value="{$option.file|escape:html}" data-width="{$option.width}" data-height="{$option.height}" data-url="{$option.url|escape:html}">{$option.name|escape:html}</option>{/foreach}</select><span class="bratonien-label">Position</span><span class="bratonien-inline">X <input type="number" name="profile_xpos" value="90" min="0" max="100" size="3"> Y <input type="number" name="profile_ypos" value="90" min="0" max="100" size="3"></span><span class="bratonien-label">Wiederholen</span><span class="bratonien-inline">X <input type="number" name="profile_xrepeat" value="0" min="0" max="20" size="3"> Y <input type="number" name="profile_yrepeat" value="0" min="0" max="20" size="3"></span><label>Deckkraft</label><span><input type="number" name="profile_opacity" value="35" min="1" max="100" size="3" data-watermark-opacity> %</span><span class="bratonien-label">Mindestgröße</span><span class="bratonien-inline"><input type="number" name="profile_min_width" value="10" min="0" size="5"> × <input type="number" name="profile_min_height" value="10" min="0" size="5"></span></div><div class="bratonien-scale-editor"><div class="bratonien-scale-editor__title">Größe & Vorschau</div><div class="bratonien-scale-grid"><div class="bratonien-scale-fields"><span class="bratonien-label">Originalgröße</span><span data-original-size>Keine Datei gewählt</span><label>Skalierung</label><span><input type="number" name="profile_scale_percent" value="{$WATERMARK.scale_percent}" min="1" max="1000" step="0.1" data-scale-percent> %</span><label>Breite</label><span><input type="number" min="1" step="1" data-scale-width> px</span><label>Höhe</label><span><input type="number" min="1" step="1" data-scale-height> px</span><span class="bratonien-label">Seitenverhältnis</span><span class="bratonien-lock">🔒 gesperrt</span></div><div><div class="bratonien-scale-preview__stage" data-preview-stage><span class="bratonien-scale-preview__empty" data-preview-empty>Keine Wasserzeichendatei gewählt</span><img src="" alt="Vorschau" data-preview-image style="display:none"></div><div class="bratonien-scale-preview__info" data-preview-info></div></div></div></div><div class="bratonien-actions"><button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_profile_save">Profil anlegen</button></div></form></div></details>
    </div></div>
  </section>

  <section class="bratonien-section" id="regeln"><h3>Regeln</h3><p class="bratonien-section__intro">Standardregeln und Album-Ausnahmen gemeinsam verwalten.</p><div class="bratonien-card"><h4>Globale Standardregeln</h4><form method="post"><input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}"><div class="bratonien-form-grid"><label>Öffentliche Alben</label><select name="public_profile"><option value="">Kein Wasserzeichen</option>{foreach from=$WATERMARK_PROFILES item=profile}{if $profile.active}<option value="{$profile.id}" {if $WATERMARK_DEFAULTS.public_profile == $profile.id}selected{/if}>{$profile.name|escape:html}</option>{/if}{/foreach}</select><label>Private Alben</label><select name="private_profile"><option value="">Kein Wasserzeichen</option>{foreach from=$WATERMARK_PROFILES item=profile}{if $profile.active}<option value="{$profile.id}" {if $WATERMARK_DEFAULTS.private_profile == $profile.id}selected{/if}>{$profile.name|escape:html}</option>{/if}{/foreach}</select></div><div class="bratonien-actions"><button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_defaults">Standardregeln speichern</button></div></form></div><div class="bratonien-card" style="margin-top:16px;"><h4>Album-Ausnahmen</h4><p class="bratonien-muted">Erben verwendet die nächste explizite Regel eines Elternalbums und danach den globalen Standard.</p><table class="bratonien-rule-table"><thead><tr><th>Album</th><th>Sichtbarkeit</th><th>Regel</th><th>Profil</th><th>Wirksam</th><th></th></tr></thead><tbody>{foreach from=$WATERMARK_CATEGORIES item=category}<tr><form method="post"><input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}"><input type="hidden" name="category_id" value="{$category.id}"><td><strong>{$category.display_name|escape:html}</strong></td><td>{$category.status|escape:html}</td><td><select name="rule_mode"><option value="inherit" {if $category.rule.mode == 'inherit'}selected{/if}>Erben</option><option value="disabled" {if $category.rule.mode == 'disabled'}selected{/if}>Kein Wasserzeichen</option><option value="profile" {if $category.rule.mode == 'profile'}selected{/if}>Profil verwenden</option></select></td><td><select name="rule_profile"><option value="">Profil wählen</option>{foreach from=$WATERMARK_PROFILES item=profile}{if $profile.active}<option value="{$profile.id}" {if $category.rule.profile_id == $profile.id}selected{/if}>{$profile.name|escape:html}</option>{/if}{/foreach}</select></td><td class="bratonien-effective"><strong>{$category.effective_label|escape:html}</strong> <span class="bratonien-muted">({$category.effective.source|escape:html})</span></td><td><button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_rule">Speichern</button></td></form></tr>{/foreach}</tbody></table></div></section>

  <section class="bratonien-section" id="wartung">
    <h3>Wartung</h3><p class="bratonien-section__intro">Werkzeuge, die nicht zur täglichen Konfiguration gehören.</p>
    <div class="bratonien-card">
      <h4>Bildcache</h4>
      <p>Löscht alle erzeugten Piwigo- und Bratonien-Bildderivate. Originalbilder bleiben erhalten. Ein laufender manueller Cache-Aufbau wird vorher beendet.</p>
      <form method="post"><input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}"><button class="buttonLike" type="submit" name="bratonien_tool" value="image_cache_clear" onclick="return confirm('Wirklich den gesamten Bildcache leeren? Ein laufender Cache-Aufbau wird vorher abgebrochen.');">Bildcache leeren</button></form>
      <div class="bratonien-main-cache">
        <h4>Piwigo-Bildcache vorbereiten</h4>
        <p>Erzeugt die normalen Piwigo-Bildgrößen vorab. Bratonien-Wasserzeichen werden weiterhin nur bei Bedarf erzeugt.</p>
        <p class="bratonien-main-cache__warning"><strong>Experimentell:</strong> Die Automatik nutzt bewusst nur einen Worker pro tatsächlich verfügbarem CPU-Kern. Aktuell erkannt: {$CACHE_WORKERS.cpu_count} CPU(s) → {$CACHE_WORKERS.auto_workers} Worker. Maximal 32 Worker.</p>
        <form method="post" class="bratonien-worker-settings">
          <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
          <label><input type="checkbox" name="cache_workers_auto" value="1" {if $CACHE_WORKERS.auto}checked{/if}> Worker automatisch nach verfügbaren CPU-Kernen wählen (1:1)</label>
          <span class="bratonien-worker-settings__state">Erkannt: {$CACHE_WORKERS.cpu_count} CPU(s) · Auto: {$CACHE_WORKERS.auto_workers} · aktuell verwendet: {$CACHE_WORKERS.worker_count}</span>
          <label>Manuell: <input type="number" name="cache_workers_manual" value="{$CACHE_WORKERS.manual_workers}" min="1" max="32" step="1"> Worker</label>
          <button class="buttonLike" type="submit" name="bratonien_tool" value="image_cache_worker_settings">Worker-Einstellung speichern</button>
        </form>
        <div class="bratonien-main-cache__controls">
          <form method="post"><input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}"><button class="buttonLike" type="submit" name="bratonien_tool" value="image_cache_build">Piwigo-Bildcache aufbauen</button></form>
          <form method="post" class="bratonien-main-cache__cancel" data-cache-cancel-form><input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}"><button class="buttonLike bratonien-delete-button" type="submit" name="bratonien_tool" value="image_cache_cancel">Cache-Aufbau abbrechen</button></form>
        </div>
        <div class="bratonien-main-cache__progress" data-main-cache-progress data-status-url="{$MAIN_CACHE_STATUS_URL|escape:html}">
          <div class="bratonien-main-cache__head"><strong data-cache-title>Cache-Aufbau</strong><strong data-cache-percent>0 %</strong></div>
          <div class="bratonien-main-cache__track" role="progressbar" aria-label="Piwigo-Bildcache" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-cache-track><div class="bratonien-main-cache__bar" data-cache-bar></div></div>
          <div class="bratonien-main-cache__details" data-cache-details></div><div class="bratonien-main-cache__current" data-cache-current></div>
        </div>
      </div>
    </div>
  </section>
</div>

<script>
{literal}
(function(){
'use strict';
function clamp(v,min,max){return Math.min(max,Math.max(min,v));}
function round(v,d){var f=Math.pow(10,d||0);return Math.round(v*f)/f;}
function initWatermarkEditor(form){
  var fileSelect=form.querySelector('[data-watermark-file]'),percentInput=form.querySelector('[data-scale-percent]'),widthInput=form.querySelector('[data-scale-width]'),heightInput=form.querySelector('[data-scale-height]'),originalLabel=form.querySelector('[data-original-size]'),previewImage=form.querySelector('[data-preview-image]'),previewEmpty=form.querySelector('[data-preview-empty]'),previewInfo=form.querySelector('[data-preview-info]'),opacityInput=form.querySelector('[data-watermark-opacity]'),previewStage=form.querySelector('[data-preview-stage]');
  if(!fileSelect||!percentInput||!widthInput||!heightInput)return;
  function selectedMeta(){var option=fileSelect.options[fileSelect.selectedIndex];return{width:parseFloat(option?option.getAttribute('data-width'):0)||0,height:parseFloat(option?option.getAttribute('data-height'):0)||0,url:option?(option.getAttribute('data-url')||''):''};}
  function updatePreview(width,height,percent){var meta=selectedMeta();if(!meta.width||!meta.height||!meta.url){if(previewImage)previewImage.style.display='none';if(previewEmpty)previewEmpty.style.display='';if(previewInfo)previewInfo.textContent='';return;}if(previewEmpty)previewEmpty.style.display='none';if(previewImage){previewImage.src=meta.url;previewImage.style.display='block';previewImage.style.opacity=opacityInput?String(clamp((parseFloat(opacityInput.value)||100)/100,.01,1)):'1';var aw=Math.max(1,(previewStage?previewStage.clientWidth:360)-20),ah=Math.max(1,(previewStage?previewStage.clientHeight:250)-20),vs=Math.min(1,aw/width,ah/height);previewImage.style.width=Math.max(1,Math.round(width*vs))+'px';previewImage.style.height=Math.max(1,Math.round(height*vs))+'px';}if(previewInfo){var aW=Math.max(1,(previewStage?previewStage.clientWidth:360)-20),aH=Math.max(1,(previewStage?previewStage.clientHeight:250)-20),fitted=width>aW||height>aH;previewInfo.textContent='Original: '+Math.round(meta.width)+' × '+Math.round(meta.height)+' px · Ziel: '+Math.round(width)+' × '+Math.round(height)+' px · '+round(percent,1)+' %'+(fitted?' · Vorschau verkleinert':'');}}
  function applyPercent(raw){var meta=selectedMeta();if(!meta.width||!meta.height){widthInput.value='';heightInput.value='';if(originalLabel)originalLabel.textContent='Keine Datei gewählt';updatePreview(1,1,100);return;}var p=parseFloat(raw);if(!isFinite(p))p=100;p=clamp(p,1,1000);var w=Math.max(1,Math.round(meta.width*p/100)),h=Math.max(1,Math.round(meta.height*p/100));percentInput.value=round(p,2);widthInput.value=w;heightInput.value=h;if(originalLabel)originalLabel.textContent=Math.round(meta.width)+' × '+Math.round(meta.height)+' px';updatePreview(w,h,p);}
  function applyWidth(){var meta=selectedMeta();if(!meta.width||!meta.height)return;var w=parseFloat(widthInput.value);if(!isFinite(w)||w<1)w=meta.width;applyPercent(clamp(w/meta.width*100,1,1000));}
  function applyHeight(){var meta=selectedMeta();if(!meta.width||!meta.height)return;var h=parseFloat(heightInput.value);if(!isFinite(h)||h<1)h=meta.height;applyPercent(clamp(h/meta.height*100,1,1000));}
  fileSelect.addEventListener('change',function(){applyPercent(percentInput.value||100);});percentInput.addEventListener('input',function(){applyPercent(percentInput.value);});widthInput.addEventListener('input',applyWidth);heightInput.addEventListener('input',applyHeight);if(opacityInput)opacityInput.addEventListener('input',function(){applyPercent(percentInput.value);});applyPercent(percentInput.value||100);
}
document.querySelectorAll('[data-watermark-editor]').forEach(initWatermarkEditor);

function initMainCacheProgress(){
  var box=document.querySelector('[data-main-cache-progress]');if(!box)return;
  var url=box.getAttribute('data-status-url'),title=box.querySelector('[data-cache-title]'),percentEl=box.querySelector('[data-cache-percent]'),details=box.querySelector('[data-cache-details]'),current=box.querySelector('[data-cache-current]'),bar=box.querySelector('[data-cache-bar]'),track=box.querySelector('[data-cache-track]'),cancelForm=document.querySelector('[data-cache-cancel-form]'),timer=null,hideTimer=null;
  function schedule(ms){if(timer)clearTimeout(timer);timer=setTimeout(load,ms);}
  function render(data){
    var state=data.state||'idle',total=Math.max(0,parseInt(data.total,10)||0),completed=Math.max(0,parseInt(data.completed,10)||0),percent=total>0?Math.min(100,Math.round(completed/total*100)):(state==='complete'?100:0);
    box.classList.toggle('is-error',state==='error');box.classList.toggle('is-queued',state==='queued');box.classList.toggle('is-cancelling',state==='cancelling');bar.style.width=percent+'%';track.setAttribute('aria-valuenow',String(percent));percentEl.textContent=percent+' %';
    var labels={queued:'Cache-Aufbau wartet',running:'Cache-Aufbau läuft',cancelling:'Cache-Aufbau wird abgebrochen',cancelled:'Cache-Aufbau abgebrochen',complete:'Cache-Aufbau fertig',error:'Cache-Aufbau mit Fehlern'};title.textContent=labels[state]||'Cache-Aufbau';
    details.textContent=(data.message||'')+(total>0?' · '+completed+' / '+total+' Varianten · '+(parseInt(data.generated,10)||0)+' neu · '+(parseInt(data.cached,10)||0)+' vorhanden · '+(parseInt(data.skipped,10)||0)+' übersprungen · '+(parseInt(data.errors,10)||0)+' Fehler':'');current.textContent=data.current?('Aktuell: '+data.current):'';
    if(cancelForm)cancelForm.style.display=(state==='queued'||state==='running'||state==='cancelling')?'block':'none';
    if(state==='queued'||state==='running'||state==='cancelling'||state==='error'){if(hideTimer){clearTimeout(hideTimer);hideTimer=null;}box.style.display='block';}
    if(state==='queued'||state==='running'||state==='cancelling'){schedule(1000);}else if(state==='complete'||state==='cancelled'){box.style.display='block';if(hideTimer)clearTimeout(hideTimer);hideTimer=setTimeout(function(){box.style.display='none';},5000);}else if(state==='idle'){box.style.display='none';}
  }
  function load(){fetch(url+(url.indexOf('?')===-1?'?':'&')+'_='+(Date.now()),{credentials:'same-origin',cache:'no-store'}).then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.json();}).then(render).catch(function(){box.style.display='block';box.classList.add('is-error');details.textContent='Cache-Status konnte nicht geladen werden.';});}
  load();
}
initMainCacheProgress();
})();
{/literal}
</script>
