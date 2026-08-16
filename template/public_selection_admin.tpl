<section class="bratonien-section" id="auswahl-download">
  <h3>Fotoauswahl & Download</h3>
  <p class="bratonien-section__intro">Steuert, wer in der öffentlichen Galerie einzelne Fotos markieren und gesammelt herunterladen darf.</p>

  <form method="post">
    <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">

    <div class="bratonien-card">
      <div class="bratonien-form-grid">
        <span class="bratonien-label">Auswahlfunktion</span>
        <label><input type="checkbox" name="selection_enabled" value="1" {if $PUBLIC_SELECTION.enabled}checked{/if}> Aktivieren</label>

        <span class="bratonien-label">Gäste</span>
        <label><input type="checkbox" name="selection_allow_guests" value="1" {if $PUBLIC_SELECTION.allow_guests}checked{/if}> Nicht angemeldete Besucher dürfen auswählen</label>

        <span class="bratonien-label">Registrierte Benutzer</span>
        <label><input type="checkbox" name="selection_allow_registered" value="1" {if $PUBLIC_SELECTION.allow_registered}checked{/if}> Alle angemeldeten Benutzer dürfen auswählen</label>

        <span class="bratonien-label">Benutzergruppen</span>
        <div>
          {if $PUBLIC_SELECTION_GROUPS|@count}
            {foreach from=$PUBLIC_SELECTION_GROUPS item=group}
              <label style="display:block;margin-bottom:5px;">
                <input type="checkbox" name="selection_groups[]" value="{$group.id}" {if in_array($group.id, $PUBLIC_SELECTION.groups)}checked{/if}>
                {$group.name|escape:html}
              </label>
            {/foreach}
          {else}
            <span class="bratonien-muted">Keine Benutzergruppen vorhanden.</span>
          {/if}
        </div>
      </div>

      <p class="bratonien-base-note">Administratoren haben immer Zugriff. Gruppen werden zusätzlich zu den beiden allgemeinen Freigaben ausgewertet. Für eine reine Gruppenfreigabe „Registrierte Benutzer“ deaktivieren und nur die gewünschten Gruppen auswählen.</p>

      <div class="bratonien-actions">
        <button class="buttonLike" type="submit" name="bratonien_tool" value="public_selection_settings">Zugriffsrechte speichern</button>
      </div>
    </div>
  </form>
</section>
