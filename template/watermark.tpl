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

    <p>
      <label>Neues PNG hochladen:
        <input type="file" name="watermark_upload" accept="image/png">
      </label>
    </p>

    <p>
      Position X: <input type="number" name="watermark_xpos" value="{$WATERMARK.xpos}" min="0" max="100">
      Position Y: <input type="number" name="watermark_ypos" value="{$WATERMARK.ypos}" min="0" max="100">
    </p>

    <p>
      Deckkraft: <input type="number" name="watermark_opacity" value="{$WATERMARK.opacity}" min="1" max="100"> %
    </p>

    <p>
      Mindestgröße: <input type="number" name="watermark_minw" value="{$WATERMARK.minw}" min="0"> x
      <input type="number" name="watermark_minh" value="{$WATERMARK.minh}" min="0">
    </p>

    <p>
      <label>
        <input type="checkbox" name="watermark_clear_cache" value="1">
        Nach Speichern Bildcache leeren
      </label>
    </p>

    <button type="submit" class="buttonLike">Wasserzeichen speichern</button>
  </form>
</fieldset>
