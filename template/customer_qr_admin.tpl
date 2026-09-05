<section class="bratonien-section" id="qr-upload">
  <h3>QR-Upload</h3>
  <p class="bratonien-section__intro">Aktivierbares Uploadformular mit eindeutiger Jahres-/Nummern-Zuordnung und Batch-Upload.</p>

  <div class="bratonien-grid">
    <div class="bratonien-card">
      <h4>Formular</h4>
      <form method="post">
        <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
        <input type="hidden" name="bratonien_tool" value="customer_qr_settings">

        <label style="display:flex;gap:10px;align-items:center;margin:0 0 14px">
          <input type="checkbox" name="customer_qr_enabled" value="1" {if $CUSTOMER_QR.enabled}checked{/if}>
          <strong>QR-Upload aktivieren</strong>
        </label>

        <button class="buttonLike" type="submit">Einstellung speichern</button>
      </form>

      <p class="bratonien-base-note" style="margin-top:14px">Ist das Formular deaktiviert, verschwindet der Menüpunkt und die Uploadseite nimmt keine Dateien an.</p>
    </div>

    <div class="bratonien-card">
      <h4>Formular-Adresse</h4>
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
      <p class="bratonien-base-note">Eine QR-Code-Nummer ist pro Jahr eindeutig. Wird ein Upload gelöscht, ist die Nummer für dieses Jahr wieder frei.</p>
    </div>
  </div>

  <div class="bratonien-card" style="margin-top:18px;overflow-x:auto">
    <h4>Uploads verwalten</h4>
    {if $CUSTOMER_QR.total > 0}
      <table style="width:100%;border-collapse:collapse;min-width:820px">
        <thead>
          <tr>
            <th style="text-align:left;padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.14)">Jahr</th>
            <th style="text-align:left;padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.14)">QR-Nr.</th>
            <th style="text-align:left;padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.14)">Datei</th>
            <th style="text-align:left;padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.14)">Größe</th>
            <th style="text-align:left;padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.14)">Upload</th>
            <th style="text-align:right;padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.14)">Aktionen</th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$CUSTOMER_QR.uploads item=qr_upload}
            <tr>
              <td style="padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.08)"><strong>{$qr_upload.year}</strong></td>
              <td style="padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.08)"><strong>#{$qr_upload.number|escape:html}</strong></td>
              <td style="padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.08);max-width:300px;overflow-wrap:anywhere">{$qr_upload.original_name|escape:html}</td>
              <td style="padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.08)">{$qr_upload.file_size_label|escape:html}</td>
              <td style="padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.08)">{$qr_upload.created|escape:html}</td>
              <td style="padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.08);text-align:right;white-space:nowrap">
                <a class="buttonLike" href="{$qr_upload.preview_url|escape:html}" target="_blank" rel="noopener" style="display:inline-block;margin-right:6px">Vorschau</a>
                <a class="buttonLike" href="{$qr_upload.preview_url|escape:html}&amp;download=1" style="display:inline-block;margin-right:6px">Herunterladen</a>
                <form method="post" style="display:inline" onsubmit="return confirm('Diesen QR-Upload wirklich löschen? Die QR-Nummer wird danach wieder frei.');">
                  <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
                  <input type="hidden" name="bratonien_tool" value="customer_qr_delete">
                  <input type="hidden" name="customer_qr_id" value="{$qr_upload.id}">
                  <button class="buttonLike" type="submit">Löschen</button>
                </form>
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    {else}
      <p class="bratonien-base-note">Noch keine QR-Codes hochgeladen.</p>
    {/if}
  </div>
</section>
