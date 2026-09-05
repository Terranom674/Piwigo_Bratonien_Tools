<section class="bratonien-section" id="kunden-qr">
  <h3>Kunden-QR-Upload</h3>
  <p class="bratonien-section__intro">Öffentliches Uploadformular für QR-Codes mit eindeutiger Jahres-/Nummern-Zuordnung und Batch-Upload.</p>

  <div class="bratonien-grid">
    <div class="bratonien-card">
      <h4>Formular</h4>
      <form method="post">
        <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
        <input type="hidden" name="bratonien_tool" value="customer_qr_settings">

        <label style="display:flex;gap:10px;align-items:center;margin:0 0 14px">
          <input type="checkbox" name="customer_qr_enabled" value="1" {if $CUSTOMER_QR.enabled}checked{/if}>
          <strong>Kunden-QR-Upload aktivieren</strong>
        </label>

        <button class="buttonLike" type="submit">Einstellung speichern</button>
      </form>

      <p class="bratonien-base-note" style="margin-top:14px">Ist das Formular deaktiviert, liefert die öffentliche Uploadseite keine Uploadmöglichkeit aus.</p>
    </div>

    <div class="bratonien-card">
      <h4>Öffentliche Adresse</h4>
      <div class="bratonien-form-grid">
        <span class="bratonien-label">Status</span><strong>{if $CUSTOMER_QR.enabled}Aktiv{else}Deaktiviert{/if}</strong>
        <span class="bratonien-label">Jahre</span><strong>{$CUSTOMER_QR.year_min}–{$CUSTOMER_QR.year_max}</strong>
        <span class="bratonien-label">Standardjahr</span><strong>{$CUSTOMER_QR.default_year}</strong>
        <span class="bratonien-label">Server-Batchlimit</span><strong>{$CUSTOMER_QR.max_files} Dateien</strong>
      </div>
      <p style="margin-top:14px;overflow-wrap:anywhere"><a href="{$CUSTOMER_QR.url|escape:html}" target="_blank" rel="noopener">{$CUSTOMER_QR.url|escape:html}</a></p>
    </div>

    <div class="bratonien-card">
      <h4>Vorgesehene QR-Codes</h4>
      <div class="bratonien-form-grid">
        {foreach from=$CUSTOMER_QR.years item=qr_year}
          <span class="bratonien-label">{$qr_year.year}</span>
          <strong>{$qr_year.used} / {$qr_year.capacity} belegt · {$qr_year.remaining} frei</strong>
        {/foreach}
      </div>
      <p class="bratonien-base-note">2023: Nummern 1–100 · 2024: 1–50 · 2025 und 2026: jeweils 1–30. Weitere Jahre werden erst mit dem jeweiligen Jahresupdate ergänzt.</p>
    </div>

    <div class="bratonien-card">
      <h4>Belegte QR-Code-Nummern</h4>
      <div class="bratonien-form-grid">
        <span class="bratonien-label">Gesamt</span><strong>{$CUSTOMER_QR.total}</strong>
        <span class="bratonien-label">Im Standardjahr {$CUSTOMER_QR.default_year}</span><strong>{$CUSTOMER_QR.current_year_total} / {$CUSTOMER_QR.current_year_capacity}</strong>
      </div>
      <p class="bratonien-base-note">Eine QR-Code-Nummer ist pro Jahr eindeutig. Dieselbe Nummer kann in einem anderen Jahr erneut verwendet werden.</p>
    </div>
  </div>
</section>
