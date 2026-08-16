<section class="bratonien-section" id="freigaben">
  <h3>Geschützte Albumfreigaben</h3>
  <p class="bratonien-section__intro">Private Alben per individuellem Link teilen – optional mit Passwort und Ablaufdatum, ohne Abhängigkeit von ShareAlbum.</p>

  <div class="bratonien-grid">
    <div class="bratonien-card">
      <h4>Albumzugriff</h4>
      <p class="bratonien-muted">Mit einem Klick ein Album privat oder wieder öffentlich schalten. Beim Sperren behält dein eigener Benutzer automatisch Zugriff.</p>

      <form method="get" action="admin.php" style="margin-bottom:14px;">
        <input type="hidden" name="page" value="plugin-bratonien_tools">
        <div class="bratonien-actions" style="align-items:center; gap:8px;">
          <input type="search" name="br_album_search" value="{$BRATONIEN_ALBUM_SEARCH|escape:html}" placeholder="Album suchen …" aria-label="Album suchen" style="min-width:240px; flex:1;">
          <button class="buttonLike" type="submit">Suchen</button>
          {if $BRATONIEN_ALBUM_SEARCH != ''}
            <a class="buttonLike" href="admin.php?page=plugin-bratonien_tools#freigaben">Zurücksetzen</a>
          {/if}
        </div>
      </form>

      {if empty($BRATONIEN_ALBUM_LOCK_PAGE.albums)}
        {if $BRATONIEN_ALBUM_SEARCH != ''}
          <p class="bratonien-muted">Keine Alben für „{$BRATONIEN_ALBUM_SEARCH|escape:html}“ gefunden.</p>
        {else}
          <p class="bratonien-muted">Keine Alben vorhanden.</p>
        {/if}
      {else}
        <table class="bratonien-rule-table">
          <thead>
            <tr>
              <th>Album</th>
              <th style="width:70px; text-align:center;">Zugriff</th>
            </tr>
          </thead>
          <tbody>
            {foreach from=$BRATONIEN_ALBUM_LOCK_PAGE.albums item=album}
              <tr>
                <td>{$album.name|escape:html}</td>
                <td style="text-align:center;">
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
                    <input type="hidden" name="lock_category_id" value="{$album.id}">
                    {if $album.status == 'private'}
                      <button class="buttonLike" type="submit" name="bratonien_tool" value="album_lock_toggle" title="Album ist privat – klicken zum Entsperren" aria-label="Album entsperren" onclick="return confirm('Dieses Album wieder öffentlich schalten?');"><span class="icon-lock" aria-hidden="true"></span></button>
                    {else}
                      <button class="buttonLike" type="submit" name="bratonien_tool" value="album_lock_toggle" title="Album ist öffentlich – klicken zum Sperren" aria-label="Album sperren" onclick="return confirm('Dieses Album auf privat stellen?');"><span class="icon-eye" aria-hidden="true"></span></button>
                    {/if}
                  </form>
                </td>
              </tr>
            {/foreach}
          </tbody>
        </table>

        {if $BRATONIEN_ALBUM_LOCK_PAGE.pages > 1}
          <div class="bratonien-actions" style="justify-content:space-between; align-items:center; margin-top:12px;">
            <div>
              {if $BRATONIEN_ALBUM_LOCK_PAGE.has_previous}
                <a class="buttonLike" href="{$BRATONIEN_ALBUM_PAGER_URL}{$BRATONIEN_ALBUM_LOCK_PAGE.previous_page}#freigaben">Zurück</a>
              {/if}
            </div>
            <span class="bratonien-muted">Seite {$BRATONIEN_ALBUM_LOCK_PAGE.page} von {$BRATONIEN_ALBUM_LOCK_PAGE.pages}</span>
            <div>
              {if $BRATONIEN_ALBUM_LOCK_PAGE.has_next}
                <a class="buttonLike" href="{$BRATONIEN_ALBUM_PAGER_URL}{$BRATONIEN_ALBUM_LOCK_PAGE.next_page}#freigaben">Weiter</a>
              {/if}
            </div>
          </div>
        {/if}
      {/if}
    </div>

    <div class="bratonien-card">
      <h4>Neue Freigabe</h4>
      <form method="post">
        <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
        <div class="bratonien-form-grid">
          <label for="share_category_id">Privates Album</label>
          <select id="share_category_id" name="share_category_id" required>
            <option value="">Album auswählen</option>
            {foreach from=$BRATONIEN_PRIVATE_ALBUMS item=album}
              <option value="{$album.id}">{$album.name|escape:html}</option>
            {/foreach}
          </select>

          <label for="share_password">Passwort (optional)</label>
          <input id="share_password" type="password" name="share_password" autocomplete="new-password" placeholder="Leer lassen für Freigabe nur per Link">

          <label for="share_expires_at">Ablaufdatum (optional)</label>
          <input id="share_expires_at" type="datetime-local" name="share_expires_at">
        </div>
        <div class="bratonien-actions">
          <button class="buttonLike" type="submit" name="bratonien_tool" value="album_share_create">Freigabe erstellen</button>
        </div>
      </form>
    </div>

    <div class="bratonien-card">
      <h4>Hinweis</h4>
      <p class="bratonien-muted">Jede Freigabe erhält einen eigenen, nicht erratbaren Link. Ein Passwort ist optional; ohne Passwort genügt der Link bis zum Widerruf oder Ablaufdatum.</p>
      <p class="bratonien-muted">Passwörter werden nur als Hash gespeichert. Der Freigabelink bleibt in der Liste unten sichtbar und kann jederzeit kopiert werden.</p>
    </div>
  </div>

  <div class="bratonien-card" style="margin-top:16px;">
    <h4>Aktive Freigaben</h4>
    {if empty($BRATONIEN_ALBUM_SHARES)}
      <p class="bratonien-muted">Noch keine Freigaben vorhanden.</p>
    {else}
      <table class="bratonien-rule-table">
        <thead>
          <tr>
            <th>Album</th>
            <th>Schutz</th>
            <th>Ablauf</th>
            <th>Freigabelink</th>
            <th>Aktion</th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$BRATONIEN_ALBUM_SHARES item=share}
            <tr>
              <td>{$share.category_name|default:'Gelöschtes Album'|escape:html}</td>
              <td>{if $share.password_protected}Passwort{else}Nur Link{/if}</td>
              <td>{if $share.expires_at}{$share.expires_at|escape:html}{else}Unbegrenzt{/if}</td>
              <td style="min-width:360px;">
                {if $share.link_copyable}
                  <div class="bratonien-actions" style="align-items:center; gap:6px; flex-wrap:nowrap;">
                    <input id="br-share-link-{$share.id}" type="text" value="{$share.share_url|escape:html}" readonly style="min-width:260px; flex:1;">
                    <button class="buttonLike bratonien-copy-share" type="button" data-copy-target="br-share-link-{$share.id}" title="Freigabelink kopieren" aria-label="Freigabelink kopieren"><span class="icon-docs" aria-hidden="true"></span></button>
                  </div>
                {else}
                  <span class="bratonien-muted">Alter Link kann nicht rekonstruiert werden.</span>
                  <form method="post" style="display:inline; margin-left:6px;">
                    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
                    <input type="hidden" name="share_id" value="{$share.id}">
                    <button class="buttonLike" type="submit" name="bratonien_tool" value="album_share_regenerate_link" onclick="return confirm('Neuen Freigabelink erzeugen? Der bisherige Link wird dadurch ungültig.');">Neuen Link erzeugen</button>
                  </form>
                {/if}
              </td>
              <td>
                <form method="post" style="display:inline">
                  <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
                  <input type="hidden" name="share_id" value="{$share.id}">
                  <button class="buttonLike bratonien-delete-button" type="submit" name="bratonien_tool" value="album_share_revoke" onclick="return confirm('Diese Freigabe wirklich widerrufen?');">Widerrufen</button>
                </form>
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    {/if}
  </div>
</section>

{literal}
<script>
(function () {
  'use strict';

  document.addEventListener('click', function (event) {
    var button = event.target.closest('.bratonien-copy-share');
    if (!button) return;

    var input = document.getElementById(button.getAttribute('data-copy-target'));
    if (!input) return;

    function copied() {
      var oldTitle = button.getAttribute('title') || 'Freigabelink kopieren';
      button.setAttribute('title', 'Kopiert');
      button.setAttribute('aria-label', 'Freigabelink kopiert');
      setTimeout(function () {
        button.setAttribute('title', oldTitle);
        button.setAttribute('aria-label', 'Freigabelink kopieren');
      }, 1400);
    }

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(input.value).then(copied).catch(function () {
        input.focus();
        input.select();
        document.execCommand('copy');
        copied();
      });
      return;
    }

    input.focus();
    input.select();
    document.execCommand('copy');
    copied();
  });
})();
</script>
{/literal}
