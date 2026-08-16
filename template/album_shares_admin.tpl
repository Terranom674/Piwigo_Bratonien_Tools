<section class="bratonien-section" id="freigaben">
  <h3>Geschützte Albumfreigaben</h3>
  <p class="bratonien-section__intro">Private Alben per individuellem Link und Passwort freigeben – ohne Abhängigkeit von ShareAlbum.</p>

  <div class="bratonien-grid">
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

          <label for="share_password">Passwort</label>
          <input id="share_password" type="password" name="share_password" autocomplete="new-password" required>

          <label for="share_expires_at">Ablaufdatum</label>
          <input id="share_expires_at" type="datetime-local" name="share_expires_at">
        </div>
        <div class="bratonien-actions">
          <button class="buttonLike" type="submit" name="bratonien_tool" value="album_share_create">Freigabe erstellen</button>
        </div>
      </form>
    </div>

    <div class="bratonien-card">
      <h4>Hinweis</h4>
      <p class="bratonien-muted">Die Freigabe verwendet einen eigenen technischen Piwigo-Benutzer mit Zugriff ausschließlich auf das gewählte private Album. Das sichtbare Freigabepasswort wird nur als Hash gespeichert.</p>
      <p class="bratonien-muted">Der erzeugte Link wird nach dem Erstellen oben als Meldung angezeigt. Ohne korrektes Passwort erfolgt kein Zugriff.</p>
    </div>
  </div>

  <div class="bratonien-card" style="margin-top:16px;">
    <h4>Aktive Freigaben</h4>
    {if empty($BRATONIEN_ALBUM_SHARES)}
      <p class="bratonien-muted">Noch keine geschützten Freigaben vorhanden.</p>
    {else}
      <table class="bratonien-rule-table">
        <thead>
          <tr>
            <th>Album</th>
            <th>Erstellt</th>
            <th>Ablauf</th>
            <th>Erstellt von</th>
            <th>Aktion</th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$BRATONIEN_ALBUM_SHARES item=share}
            <tr>
              <td>{$share.category_name|default:'Gelöschtes Album'|escape:html}</td>
              <td>{$share.created_at|escape:html}</td>
              <td>{if $share.expires_at}{$share.expires_at|escape:html}{else}Unbegrenzt{/if}</td>
              <td>{$share.created_by_name|default:'#'|escape:html}</td>
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
