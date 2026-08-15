<div class="titrePage">
  <h2>Bratonien Tools</h2>
</div>

<p>Wartungs- und Verwaltungswerkzeuge fuer diese Piwigo-Installation.</p>

{foreach from=$BRATONIEN_MESSAGES item=message}
  <div class="infos"><p>{$message|escape:html}</p></div>
{/foreach}

{foreach from=$BRATONIEN_ERRORS item=error}
  <div class="errors"><p>{$error|escape:html}</p></div>
{/foreach}

<div class="bratonien-tools">
{foreach from=$BRATONIEN_TOOLS key=tool_id item=tool}
  <fieldset style="margin-bottom:1.5em;">
    <legend>{$tool.title|escape:html}</legend>
    <p>{$tool.description|escape:html}</p>

    {if $tool_id == 'watermark'}
      <h3>Wasserzeichenprofile</h3>
      <p>Profile definieren, welche Wasserzeichen-Einstellungen fuer spaetere Albumregeln verwendet werden.</p>

      <table class="table2">
        <tr>
          <th>Name</th>
          <th>Position</th>
          <th>Deckkraft</th>
          <th>Aktion</th>
        </tr>
        {foreach from=$WATERMARK_PROFILES item=profile}
          <tr>
            <td>{$profile.name|escape:html}</td>
            <td>{$profile.xpos}/{$profile.ypos}</td>
            <td>{$profile.opacity}%</td>
            <td>
              <form method="post" action="">
                <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
                <input type="hidden" name="bratonien_tool" value="watermark">
                <input type="hidden" name="profile_id" value="{$profile.id}">
                <button class="buttonLike" type="submit">Bearbeiten</button>
              </form>
            </td>
          </tr>
        {/foreach}
      </table>

      <h3>Neues Profil</h3>
      <form method="post" action="">
        <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
        <input type="hidden" name="bratonien_tool" value="watermark">
        <p>Name: <input name="profile_name" value=""></p>
        <p>Datei: <input name="profile_file" value=""></p>
        <p>X: <input name="profile_xpos" value="90" size="3"> Y: <input name="profile_ypos" value="90" size="3"></p>
        <p>Deckkraft: <input name="profile_opacity" value="35" size="3">%</p>
        <p>Mindestgröße: <input name="profile_min_width" value="10" size="4"> x <input name="profile_min_height" value="10" size="4"></p>
        <button class="buttonLike" type="submit">Profil speichern</button>
      </form>
    {/if}

    <form method="post" action="">
      <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
      <input type="hidden" name="bratonien_tool" value="{$tool_id|escape:html}">
      <button
        type="submit"
        class="buttonLike"
        onclick="return confirm('{$tool.confirm|escape:javascript}');"
      >{$tool.button|escape:html}</button>
    </form>
  </fieldset>
{/foreach}
</div>
