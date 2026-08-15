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
