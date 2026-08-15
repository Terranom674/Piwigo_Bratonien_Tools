<div class="titrePage"><h2>Bratonien Tools</h2></div>

{foreach from=$BRATONIEN_MESSAGES item=message}<div class="infos"><p>{$message|escape:html}</p></div>{/foreach}
{foreach from=$BRATONIEN_ERRORS item=error}<div class="errors"><p>{$error|escape:html}</p></div>{/foreach}

<style>
.titrePage h2 {
  color: #e6e6e6;
}
.bratonien-admin {
  max-width: 1180px;
  margin: 0 auto;
}
.bratonien-nav {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 0 0 18px;
}
.bratonien-nav a {
  display: inline-block;
  padding: 8px 12px;
  border: 1px solid rgba(255,255,255,.18);
  border-radius: 4px;
  color: #d7d7d7;
  text-decoration: none;
  transition: color .15s ease, border-color .15s ease, background .15s ease;
}
.bratonien-nav a:hover,
.bratonien-nav a:focus {
  color: #f0a646;
  border-color: rgba(240,166,70,.65);
  background: rgba(240,166,70,.06);
}
.bratonien-section {
  margin: 0 0 22px;
  padding: 18px;
  border: 1px solid rgba(255,255,255,.14);
  border-radius: 5px;
  background: rgba(255,255,255,.025);
}
.bratonien-section h3 {
  margin: 0 0 4px;
  color: #e6e6e6;
  font-size: 18px;
  font-weight: 700;
}
.bratonien-section__intro {
  margin: 0 0 16px;
  color: #a9a9a9;
  opacity: 1;
}
.bratonien-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}
.bratonien-card {
  padding: 15px;
  border: 1px solid rgba(255,255,255,.12);
  border-radius: 4px;
  background: rgba(0,0,0,.08);
}
.bratonien-card h4 {
  margin: 0 0 12px;
  color: #d7d7d7;
  font-size: 15px;
  font-weight: 700;
}
.bratonien-status {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
}
.bratonien-status__dot {
  width: 11px;
  height: 11px;
  border-radius: 50%;
  background: #777;
}
.bratonien-status__dot.is-active { background: #66a845; }
.bratonien-form-grid {
  display: grid;
  grid-template-columns: 150px minmax(0, 1fr);
  gap: 10px 14px;
  align-items: center;
}
.bratonien-form-grid > label,
.bratonien-label {
  font-weight: 600;
}
.bratonien-inline {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 12px;
  align-items: center;
}
.bratonien-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 14px;
}
.bratonien-watermark-preview {
  display: grid;
  grid-template-columns: minmax(230px, 320px) 1fr;
  gap: 18px;
  align-items: center;
}
.bratonien-watermark-preview__image {
  min-height: 150px;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 14px;
  border: 1px solid rgba(255,255,255,.14);
  border-radius: 4px;
  background:
    linear-gradient(45deg, rgba(255,255,255,.09) 25%, transparent 25%),
    linear-gradient(-45deg, rgba(255,255,255,.09) 25%, transparent 25%),
    linear-gradient(45deg, transparent 75%, rgba(255,255,255,.09) 75%),
    linear-gradient(-45deg, transparent 75%, rgba(255,255,255,.09) 75%);
  background-size: 20px 20px;
  background-position: 0 0, 0 10px, 10px -10px, -10px 0;
  box-sizing: border-box;
}
.bratonien-watermark-preview__image img {
  max-width: 100%;
  max-height: 190px;
  object-fit: contain;
}
.bratonien-watermark-preview__empty {
  color: #a9a9a9;
  text-align: center;
}
.bratonien-watermark-preview__meta {
  line-height: 1.65;
}
.bratonien-profile-list {
  display: grid;
  gap: 10px;
}
.bratonien-profile {
  border: 1px solid rgba(255,255,255,.12);
  border-radius: 4px;
  background: rgba(0,0,0,.07);
}
.bratonien-profile summary {
  cursor: pointer;
  padding: 12px 14px;
  color: #d7d7d7;
  font-weight: 600;
}
.bratonien-profile summary:hover {
  color: #f0a646;
}
.bratonien-profile__body {
  padding: 0 14px 14px;
}
.bratonien-profile__summary {
  margin-left: 8px;
  color: #a9a9a9;
  font-weight: normal;
}
.bratonien-rule-table {
  width: 100%;
  border-collapse: collapse;
}
.bratonien-rule-table th,
.bratonien-rule-table td {
  padding: 9px 8px;
  text-align: left;
  vertical-align: middle;
  border-bottom: 1px solid rgba(255,255,255,.09);
}
.bratonien-rule-table th {
  color: #d7d7d7;
  opacity: 1;
}
.bratonien-effective {
  white-space: nowrap;
}
.bratonien-muted {
  color: #a9a9a9;
  opacity: 1;
}
@media (max-width: 850px) {
  .bratonien-grid,
  .bratonien-watermark-preview { grid-template-columns: 1fr; }
  .bratonien-form-grid { grid-template-columns: 1fr; }
  .bratonien-rule-table { display: block; overflow-x: auto; }
}
</style>

<div class="bratonien-admin">
  <nav class="bratonien-nav" aria-label="Bratonien Tools Navigation">
    <a href="#uebersicht">Übersicht</a>
    <a href="#wasserzeichen">Wasserzeichen</a>
    <a href="#regeln">Regeln</a>
    <a href="#wartung">Wartung</a>
  </nav>

  <section class="bratonien-section" id="uebersicht">
    <h3>Übersicht</h3>
    <p class="bratonien-section__intro">Aktueller Zustand der Bratonien-Wasserzeichenverwaltung.</p>
    <div class="bratonien-grid">
      <div class="bratonien-card">
        <h4>Engine</h4>
        <div class="bratonien-status">
          <span class="bratonien-status__dot {if $WATERMARK_ENGINE.enabled}is-active{/if}"></span>
          <strong>{if $WATERMARK_ENGINE.enabled}Aktiv{else}Inaktiv{/if}</strong>
        </div>
        <form method="post">
          <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
          <label><input type="checkbox" name="engine_enabled" value="1" {if $WATERMARK_ENGINE.enabled}checked{/if}> Bratonien-Wasserzeichenverwaltung aktivieren</label>
          <div class="bratonien-actions">
            <button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_engine">Status speichern</button>
          </div>
        </form>
      </div>

      <div class="bratonien-card">
        <h4>Standardregeln</h4>
        <div class="bratonien-form-grid">
          <span class="bratonien-label">Öffentliche Alben</span>
          <strong>{if $WATERMARK_DEFAULTS.public_profile}{foreach from=$WATERMARK_PROFILES item=profile}{if $profile.id == $WATERMARK_DEFAULTS.public_profile}{$profile.name|escape:html}{/if}{/foreach}{else}Kein Wasserzeichen{/if}</strong>
          <span class="bratonien-label">Private Alben</span>
          <strong>{if $WATERMARK_DEFAULTS.private_profile}{foreach from=$WATERMARK_PROFILES item=profile}{if $profile.id == $WATERMARK_DEFAULTS.private_profile}{$profile.name|escape:html}{/if}{/foreach}{else}Kein Wasserzeichen{/if}</strong>
          <span class="bratonien-label">Profile</span>
          <span>{$WATERMARK_PROFILES|@count} vorhanden</span>
        </div>
      </div>
    </div>
  </section>

  <section class="bratonien-section" id="wasserzeichen">
    <h3>Wasserzeichen</h3>
    <p class="bratonien-section__intro">Dateien und Profile an einer Stelle verwalten.</p>

    <div class="bratonien-grid">
      <div class="bratonien-card">
        <h4>Aktuelles Wasserzeichen</h4>
        <div class="bratonien-watermark-preview">
          <div class="bratonien-watermark-preview__image">
            {if $WATERMARK.preview_url}
              <img src="{$WATERMARK.preview_url|escape:html}" alt="Aktuelles Wasserzeichen">
            {else}
              <div class="bratonien-watermark-preview__empty">Noch kein Wasserzeichen ausgewählt</div>
            {/if}
          </div>
          <div class="bratonien-watermark-preview__meta">
            {if $WATERMARK.file}
              <strong>{$WATERMARK.file|escape:html}</strong><br>
              Position: {$WATERMARK.xpos} / {$WATERMARK.ypos}<br>
              Deckkraft: {$WATERMARK.opacity}%<br>
              Mindestgröße: {$WATERMARK.minw} × {$WATERMARK.minh}
            {else}
              Keine Datei ausgewählt
            {/if}
          </div>
        </div>
      </div>

      <div class="bratonien-card">
        <h4>Datei auswählen oder hochladen</h4>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
          <div class="bratonien-form-grid">
            <label for="watermark_file">Vorhandene Datei</label>
            <select id="watermark_file" name="watermark_file">{foreach from=$WATERMARK.files key=file item=name}<option value="{$file|escape:html}" {if $file == $WATERMARK.file}selected{/if}>{$name|escape:html}</option>{/foreach}</select>

            <label for="watermark_upload">Neues PNG</label>
            <input id="watermark_upload" type="file" name="watermark_upload" accept="image/png">

            <span class="bratonien-label">Position</span>
            <span class="bratonien-inline">X <input type="number" name="watermark_xpos" value="{$WATERMARK.xpos}" min="0" max="100" size="4"> Y <input type="number" name="watermark_ypos" value="{$WATERMARK.ypos}" min="0" max="100" size="4"></span>

            <label for="watermark_opacity">Deckkraft</label>
            <span><input id="watermark_opacity" type="number" name="watermark_opacity" value="{$WATERMARK.opacity}" min="1" max="100" size="4"> %</span>

            <span class="bratonien-label">Mindestgröße</span>
            <span class="bratonien-inline"><input type="number" name="watermark_minw" value="{$WATERMARK.minw}" min="0" size="5"> × <input type="number" name="watermark_minh" value="{$WATERMARK.minh}" min="0" size="5"></span>
          </div>
          <div class="bratonien-actions">
            <label><input type="checkbox" name="watermark_clear_cache" value="1"> Bildcache danach leeren</label>
            <button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_save">Wasserzeichen speichern</button>
          </div>
        </form>
      </div>
    </div>

    <div class="bratonien-card" style="margin-top:16px;">
      <h4>Profile</h4>
      <p class="bratonien-muted">Profile bleiben kompakt. Zum Bearbeiten das gewünschte Profil öffnen.</p>
      <div class="bratonien-profile-list">
        {foreach from=$WATERMARK_PROFILES item=profile}
          <details class="bratonien-profile">
            <summary>{$profile.name|escape:html}<span class="bratonien-profile__summary">{if $profile.active}aktiv{else}inaktiv{/if} · {$profile.opacity}%</span></summary>
            <div class="bratonien-profile__body">
              <form method="post">
                <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
                <input type="hidden" name="profile_id" value="{$profile.id}">
                <div class="bratonien-form-grid">
                  <label>Name</label>
                  <span class="bratonien-inline"><input name="profile_name" value="{$profile.name|escape:html}" size="28"> <label><input type="checkbox" name="profile_active" value="1" {if $profile.active}checked{/if}> aktiv</label></span>
                  <label>Datei</label>
                  <select name="profile_file"><option value="">Keine Datei</option>{foreach from=$WATERMARK.files key=file item=name}<option value="{$file|escape:html}" {if $file == $profile.watermark_file}selected{/if}>{$name|escape:html}</option>{/foreach}</select>
                  <span class="bratonien-label">Position</span>
                  <span class="bratonien-inline">X <input type="number" name="profile_xpos" value="{$profile.xpos}" min="0" max="100" size="3"> Y <input type="number" name="profile_ypos" value="{$profile.ypos}" min="0" max="100" size="3"></span>
                  <span class="bratonien-label">Wiederholen</span>
                  <span class="bratonien-inline">X <input type="number" name="profile_xrepeat" value="{$profile.xrepeat}" min="0" max="20" size="3"> Y <input type="number" name="profile_yrepeat" value="{$profile.yrepeat}" min="0" max="20" size="3"></span>
                  <label>Deckkraft</label>
                  <span><input type="number" name="profile_opacity" value="{$profile.opacity}" min="1" max="100" size="3"> %</span>
                  <span class="bratonien-label">Mindestgröße</span>
                  <span class="bratonien-inline"><input type="number" name="profile_min_width" value="{$profile.min_width}" min="0" size="5"> × <input type="number" name="profile_min_height" value="{$profile.min_height}" min="0" size="5"></span>
                </div>
                <div class="bratonien-actions">
                  <button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_profile_save">Speichern</button>
                  <button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_profile_duplicate">Duplizieren</button>
                  <button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_profile_delete" onclick="return confirm('Profil wirklich löschen?');">Löschen</button>
                </div>
              </form>
            </div>
          </details>
        {/foreach}

        <details class="bratonien-profile">
          <summary>+ Neues Profil</summary>
          <div class="bratonien-profile__body">
            <form method="post">
              <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
              <div class="bratonien-form-grid">
                <label>Name</label>
                <span class="bratonien-inline"><input name="profile_name" size="28"> <label><input type="checkbox" name="profile_active" value="1" checked> aktiv</label></span>
                <label>Datei</label>
                <select name="profile_file"><option value="">Keine Datei</option>{foreach from=$WATERMARK.files key=file item=name}<option value="{$file|escape:html}">{$name|escape:html}</option>{/foreach}</select>
                <span class="bratonien-label">Position</span>
                <span class="bratonien-inline">X <input type="number" name="profile_xpos" value="90" min="0" max="100" size="3"> Y <input type="number" name="profile_ypos" value="90" min="0" max="100" size="3"></span>
                <span class="bratonien-label">Wiederholen</span>
                <span class="bratonien-inline">X <input type="number" name="profile_xrepeat" value="0" min="0" max="20" size="3"> Y <input type="number" name="profile_yrepeat" value="0" min="0" max="20" size="3"></span>
                <label>Deckkraft</label>
                <span><input type="number" name="profile_opacity" value="35" min="1" max="100" size="3"> %</span>
                <span class="bratonien-label">Mindestgröße</span>
                <span class="bratonien-inline"><input type="number" name="profile_min_width" value="10" min="0" size="5"> × <input type="number" name="profile_min_height" value="10" min="0" size="5"></span>
              </div>
              <div class="bratonien-actions"><button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_profile_save">Profil anlegen</button></div>
            </form>
          </div>
        </details>
      </div>
    </div>
  </section>

  <section class="bratonien-section" id="regeln">
    <h3>Regeln</h3>
    <p class="bratonien-section__intro">Standardregeln und Album-Ausnahmen gemeinsam verwalten.</p>

    <div class="bratonien-card">
      <h4>Globale Standardregeln</h4>
      <form method="post">
        <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
        <div class="bratonien-form-grid">
          <label>Öffentliche Alben</label>
          <select name="public_profile"><option value="">Kein Wasserzeichen</option>{foreach from=$WATERMARK_PROFILES item=profile}{if $profile.active}<option value="{$profile.id}" {if $WATERMARK_DEFAULTS.public_profile == $profile.id}selected{/if}>{$profile.name|escape:html}</option>{/if}{/foreach}</select>
          <label>Private Alben</label>
          <select name="private_profile"><option value="">Kein Wasserzeichen</option>{foreach from=$WATERMARK_PROFILES item=profile}{if $profile.active}<option value="{$profile.id}" {if $WATERMARK_DEFAULTS.private_profile == $profile.id}selected{/if}>{$profile.name|escape:html}</option>{/if}{/foreach}</select>
        </div>
        <div class="bratonien-actions"><button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_defaults">Standardregeln speichern</button></div>
      </form>
    </div>

    <div class="bratonien-card" style="margin-top:16px;">
      <h4>Album-Ausnahmen</h4>
      <p class="bratonien-muted">Erben verwendet die nächste explizite Regel eines Elternalbums und danach den globalen Standard.</p>
      <table class="bratonien-rule-table">
        <thead><tr><th>Album</th><th>Sichtbarkeit</th><th>Regel</th><th>Profil</th><th>Wirksam</th><th></th></tr></thead>
        <tbody>
        {foreach from=$WATERMARK_CATEGORIES item=category}
          <tr>
            <form method="post">
              <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
              <input type="hidden" name="category_id" value="{$category.id}">
              <td><strong>{$category.display_name|escape:html}</strong></td>
              <td>{$category.status|escape:html}</td>
              <td><select name="rule_mode"><option value="inherit" {if $category.rule.mode == 'inherit'}selected{/if}>Erben</option><option value="disabled" {if $category.rule.mode == 'disabled'}selected{/if}>Kein Wasserzeichen</option><option value="profile" {if $category.rule.mode == 'profile'}selected{/if}>Profil verwenden</option></select></td>
              <td><select name="rule_profile"><option value="">Profil wählen</option>{foreach from=$WATERMARK_PROFILES item=profile}{if $profile.active}<option value="{$profile.id}" {if $category.rule.profile_id == $profile.id}selected{/if}>{$profile.name|escape:html}</option>{/if}{/foreach}</select></td>
              <td class="bratonien-effective"><strong>{$category.effective_label|escape:html}</strong> <span class="bratonien-muted">({$category.effective.source|escape:html})</span></td>
              <td><button class="buttonLike" type="submit" name="bratonien_tool" value="watermark_rule">Speichern</button></td>
            </form>
          </tr>
        {/foreach}
        </tbody>
      </table>
    </div>
  </section>

  <section class="bratonien-section" id="wartung">
    <h3>Wartung</h3>
    <p class="bratonien-section__intro">Werkzeuge, die nicht zur täglichen Konfiguration gehören.</p>
    <div class="bratonien-card">
      <h4>Bildcache</h4>
      <p>Löscht alle erzeugten Piwigo- und Bratonien-Bildderivate. Originalbilder bleiben erhalten.</p>
      <form method="post">
        <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
        <button class="buttonLike" type="submit" name="bratonien_tool" value="image_cache_clear" onclick="return confirm('Wirklich den gesamten Bildcache leeren?');">Bildcache leeren</button>
      </form>
    </div>
  </section>
</div>