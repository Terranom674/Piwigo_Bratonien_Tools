<fieldset>
  <legend>Wasserzeichen</legend>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
    <input type="hidden" name="bratonien_tool" value="watermark">

    <p>
      <label>Vorhandenes Wasserzeichen:
        <select name="watermark_file">
          {foreach from=$WATERMARK.files key=file item=name}
            <option value="{$file|escape:html}" {if $file == $WATERMARK.file}selected{/if}>{$name|escape:html}</option>
          {/foreach}
        </select>
      </label>
    </p>

    <p><label>PNG hochladen: <input type="file" name="watermark_upload" accept="image/png"></label></p>

    <p>Position X: <input name="watermark_xpos" value="{$WATERMARK.xpos}"> Position Y: <input name="watermark_ypos" value="{$WATERMARK.ypos}"></p>
    <p>Deckkraft: <input name="watermark_opacity" value="{$WATERMARK.opacity}"> %</p>

    <label><input type="checkbox" name="watermark_clear_cache" value="1"> Nach Speichern Cache leeren</label>

    <button class="buttonLike">Wasserzeichen speichern</button>
  </form>

  <h3>Profile</h3>
  <table class="table2">
    <tr><th>Name</th><th>Datei</th><th>Deckkraft</th></tr>
    {foreach from=$WATERMARK_PROFILES item=profile}
      <tr>
        <td>{$profile.name|escape:html}</td>
        <td>{$profile.watermark_file|escape:html}</td>
        <td>{$profile.opacity}%</td>
      </tr>
    {/foreach}
  </table>

  <h3>Albumregeln</h3>
  <p>Albumregeln erlauben eigene Profile oder die Vererbung von Einstellungen.</p>

  <form method="post">
    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
    <input type="hidden" name="bratonien_tool" value="watermark">

    <label>Album:
      <select name="category_id">
        {foreach from=$WATERMARK_CATEGORIES item=category}
          <option value="{$category.id}">{$category.name|escape:html}</option>
        {/foreach}
      </select>
    </label>

    <label>Regel:
      <select name="rule_mode">
        <option value="inherit">Erben</option>
        <option value="profile">Profil</option>
        <option value="disabled">Kein Wasserzeichen</option>
      </select>
    </label>

    <button class="buttonLike">Albumregel speichern</button>
  </form>
</fieldset>
