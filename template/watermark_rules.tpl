<fieldset>
  <legend>Globale Wasserzeichenregeln</legend>

  <form method="post">
    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
    <input type="hidden" name="bratonien_tool" value="watermark">

    <p>
      Öffentliche Alben:
      <select name="public_profile">
        <option value="">Kein Profil</option>
        {foreach from=$WATERMARK_PROFILES item=profile}
          <option value="{$profile.id}">{$profile.name|escape:html}</option>
        {/foreach}
      </select>
    </p>

    <p>
      Private Alben:
      <select name="private_profile">
        <option value="">Kein Profil</option>
        {foreach from=$WATERMARK_PROFILES item=profile}
          <option value="{$profile.id}">{$profile.name|escape:html}</option>
        {/foreach}
      </select>
    </p>

    <button class="buttonLike" type="submit">Globale Regeln speichern</button>
  </form>
</fieldset>

<fieldset>
  <legend>Albumregeln</legend>
  <p>Einzelne Alben können die globale Regel überschreiben.</p>

  <form method="post">
    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
    <input type="hidden" name="bratonien_tool" value="watermark">

    <p>
      Album:
      <select name="category_id">
        {foreach from=$WATERMARK_CATEGORIES item=category}
          <option value="{$category.id}">{$category.name|escape:html}</option>
        {/foreach}
      </select>
    </p>

    <p>
      Regel:
      <select name="rule_mode">
        <option value="inherit">Erben</option>
        <option value="profile">Eigenes Profil</option>
        <option value="disabled">Kein Wasserzeichen</option>
      </select>
    </p>

    <p>
      Profil:
      <select name="rule_profile">
        {foreach from=$WATERMARK_PROFILES item=profile}
          <option value="{$profile.id}">{$profile.name|escape:html}</option>
        {/foreach}
      </select>
    </p>

    <button class="buttonLike" type="submit">Albumregel speichern</button>
  </form>
</fieldset>
