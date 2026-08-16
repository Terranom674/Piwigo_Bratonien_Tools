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

          <label for="share_tag">Freigabe-Tag (optional)</label>
          <input id="share_tag" type="text" name="share_tag" maxlength="255" placeholder="z. B. Max Mustermann · August 2026">

          <label for="share_password">Passwort (optional)</label>
          <div class="bratonien-actions" style="align-items:center; gap:6px; flex-wrap:nowrap;">
            <input id="share_password" type="password" name="share_password" autocomplete="new-password" placeholder="Leer lassen für Freigabe nur per Link" style="flex:1; min-width:180px;">
            <button id="br-generate-share-password" class="buttonLike" type="button" title="Sicheres Passwort erzeugen"><span class="icon-key" aria-hidden="true"></span> Generieren</button>
            <button id="br-toggle-share-password" class="buttonLike" type="button" title="Passwort anzeigen/verbergen" aria-label="Passwort anzeigen oder verbergen"><span class="icon-eye" aria-hidden="true"></span></button>
            <button id="br-copy-share-password" class="buttonLike" type="button" title="Passwort kopieren" aria-label="Passwort kopieren"><span class="icon-docs" aria-hidden="true"></span></button>
          </div>

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
      <p class="bratonien-muted">Mit dem Freigabe-Tag kannst du mehrere Freigaben desselben Albums auseinanderhalten. Passwörter werden nach dem Erstellen nur als Hash gespeichert.</p>
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
            <th>Tag</th>
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
              <td>{if $share.share_tag != ''}{$share.share_tag|escape:html}{else}<span class="bratonien-muted">–</span>{/if}</td>
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

  function copyInput(input, button) {
    if (!input || !input.value) return;

    function copied() {
      if (!button) return;
      var oldTitle = button.getAttribute('title') || 'Kopieren';
      button.setAttribute('title', 'Kopiert');
      setTimeout(function () { button.setAttribute('title', oldTitle); }, 1400);
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
  }

  document.addEventListener('click', function (event) {
    var shareCopy = event.target.closest('.bratonien-copy-share');
    if (shareCopy) {
      copyInput(document.getElementById(shareCopy.getAttribute('data-copy-target')), shareCopy);
      return;
    }

    var generate = event.target.closest('#br-generate-share-password');
    if (generate) {
      var input = document.getElementById('share_password');
      if (!input) return;

      var lower = 'abcdefghijkmnopqrstuvwxyz';
      var upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
      var digits = '23456789';
      var symbols = '!@#$%&*+-=?';
      var all = lower + upper + digits + symbols;
      var chars = [
        lower[randomIndex(lower.length)],
        upper[randomIndex(upper.length)],
        digits[randomIndex(digits.length)],
        symbols[randomIndex(symbols.length)]
      ];
      while (chars.length < 18) chars.push(all[randomIndex(all.length)]);
      secureShuffle(chars);
      input.value = chars.join('');
      input.type = 'text';
      input.focus();
      input.select();
      return;
    }

    var toggle = event.target.closest('#br-toggle-share-password');
    if (toggle) {
      var password = document.getElementById('share_password');
      if (password) password.type = password.type === 'password' ? 'text' : 'password';
      return;
    }

    var copyPassword = event.target.closest('#br-copy-share-password');
    if (copyPassword) {
      copyInput(document.getElementById('share_password'), copyPassword);
    }
  });

  function randomIndex(max) {
    if (window.crypto && window.crypto.getRandomValues) {
      var limit = Math.floor(0x100000000 / max) * max;
      var values = new Uint32Array(1);
      do { window.crypto.getRandomValues(values); } while (values[0] >= limit);
      return values[0] % max;
    }
    return Math.floor(Math.random() * max);
  }

  function secureShuffle(values) {
    for (var i = values.length - 1; i > 0; i--) {
      var j = randomIndex(i + 1);
      var tmp = values[i];
      values[i] = values[j];
      values[j] = tmp;
    }
  }
})();
</script>
{/literal}
