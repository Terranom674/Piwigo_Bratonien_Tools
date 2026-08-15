<fieldset>
  <legend>Wasserzeichenprofile</legend>

  <p>Profile definieren die Darstellung von Wasserzeichen fuer verschiedene Bereiche.</p>

  {foreach from=$WATERMARK_PROFILES item=profile}
  <div style="border:1px solid #ccc;padding:10px;margin-bottom:10px;">
    <strong>{$profile.name|escape:html}</strong><br>
    Datei: {$profile.watermark_file|escape:html}<br>
    Position: {$profile.xpos}/{$profile.ypos}<br>
    Deckkraft: {$profile.opacity}%<br>
  </div>
  {/foreach}

  <p>Profil-Bearbeitung wird hier zentral erweitert.</p>
</fieldset>
