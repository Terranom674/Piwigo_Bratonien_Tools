{literal}
<style>
.bratonien-qr-stock-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px}
.bratonien-qr-stock-head h4{margin:0}
.bratonien-qr-legend{display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin:0 0 16px}
.bratonien-qr-legend-item{display:inline-flex;gap:7px;align-items:center}
.bratonien-qr-legend-box{width:18px;height:18px;border-radius:4px;border:1px solid}
.bratonien-qr-legend-box.is-present{background:rgba(62,170,94,.28);border-color:#58c878}
.bratonien-qr-legend-box.is-missing{background:rgba(205,72,72,.25);border-color:#db6d6d}
.bratonien-qr-year{padding:14px 0;border-top:1px solid rgba(255,255,255,.10)}
.bratonien-qr-year:first-of-type{border-top:0;padding-top:0}
.bratonien-qr-year-head{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px}
.bratonien-qr-year-head strong{font-size:1.05rem}
.bratonien-qr-year-count{opacity:.78}
.bratonien-qr-year-download{margin-left:auto}
.bratonien-qr-slots{display:grid;grid-template-columns:repeat(auto-fill,minmax(42px,1fr));gap:6px}
.bratonien-qr-slot{min-height:38px;display:flex;align-items:center;justify-content:center;border:1px solid;border-radius:5px;font-weight:700;text-decoration:none!important}
.bratonien-qr-slot.is-present{background:rgba(62,170,94,.28);border-color:#58c878;color:#dff7e6}
.bratonien-qr-slot.is-present:hover{background:rgba(62,170,94,.42);color:#fff}
.bratonien-qr-slot.is-missing{background:rgba(205,72,72,.25);border-color:#db6d6d;color:#ffdede}
@media(max-width:760px){.bratonien-qr-year-download{margin-left:0}.bratonien-qr-slots{grid-template-columns:repeat(auto-fill,minmax(38px,1fr))}}
</style>
{/literal}

<section class="bratonien-section" id="qr-upload">
  <h3>QR-Upload</h3>
  <p class="bratonien-section__intro">Aktivierbares Uploadformular für nummerierte QR-Codes und persönliche Freundschaftscodes.</p>

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
        <span class="bratonien-label">Freundschaftscodes</span><strong>{$CUSTOMER_QR.friendship_codes.total}</strong>
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

  <div class="bratonien-card" style="margin-top:18px">
    <div class="bratonien-qr-stock-head">
      <h4>QR-Code-Bestand</h4>
      {if $CUSTOMER_QR.batch_download_available and $CUSTOMER_QR.total > 0}
        <a class="buttonLike" href="{$CUSTOMER_QR.batch_download_url|escape:html}">Alle QR-Codes als ZIP herunterladen</a>
      {/if}
    </div>

    <div class="bratonien-qr-legend">
      <span class="bratonien-qr-legend-item"><span class="bratonien-qr-legend-box is-present"></span>Vorhanden</span>
      <span class="bratonien-qr-legend-item"><span class="bratonien-qr-legend-box is-missing"></span>Fehlt</span>
    </div>

    {foreach from=$CUSTOMER_QR.years item=qr_year}
      <div class="bratonien-qr-year">
        <div class="bratonien-qr-year-head">
          <strong>{$qr_year.year}</strong>
          <span class="bratonien-qr-year-count">{$qr_year.used} / {$qr_year.capacity} vorhanden · {$qr_year.remaining} fehlen</span>
          {if $CUSTOMER_QR.batch_download_available and $qr_year.used > 0}
            <a class="buttonLike bratonien-qr-year-download" href="{$qr_year.batch_download_url|escape:html}">{$qr_year.year} als ZIP</a>
          {/if}
        </div>
        <div class="bratonien-qr-slots" aria-label="QR-Code-Bestand {$qr_year.year}">
          {foreach from=$qr_year.slots item=qr_slot}
            {if $qr_slot.present}
              <a class="bratonien-qr-slot is-present" href="{$qr_slot.preview_url|escape:html}" target="_blank" rel="noopener" title="QR-Code #{$qr_slot.number} vorhanden – Vorschau öffnen">{$qr_slot.number}</a>
            {else}
              <span class="bratonien-qr-slot is-missing" title="QR-Code #{$qr_slot.number} fehlt">{$qr_slot.number}</span>
            {/if}
          {/foreach}
        </div>
      </div>
    {/foreach}

    {if not $CUSTOMER_QR.batch_download_available}
      <p class="bratonien-base-note" style="margin-top:14px">Batch-Download ist erst verfügbar, wenn die PHP-Erweiterung ZipArchive auf dem Server aktiv ist.</p>
    {/if}
  </div>

  <div class="bratonien-card" style="margin-top:18px;overflow-x:auto">
    <h4>QR-Codes verwalten</h4>
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

  <div class="bratonien-card" style="margin-top:18px;overflow-x:auto">
    <h4>Persönliche Freundschaftscodes</h4>
    {if $CUSTOMER_QR.friendship_codes.total > 0}
      <table style="width:100%;border-collapse:collapse;min-width:760px">
        <thead>
          <tr>
            <th style="text-align:left;padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.14)">Name</th>
            <th style="text-align:left;padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.14)">Datei</th>
            <th style="text-align:left;padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.14)">Größe</th>
            <th style="text-align:left;padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.14)">Upload</th>
            <th style="text-align:right;padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.14)">Aktionen</th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$CUSTOMER_QR.friendship_codes.uploads item=friendship}
            <tr>
              <td style="padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.08)"><strong>{$friendship.name|escape:html}</strong></td>
              <td style="padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.08);max-width:300px;overflow-wrap:anywhere">{$friendship.original_name|escape:html}</td>
              <td style="padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.08)">{$friendship.file_size_label|escape:html}</td>
              <td style="padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.08)">{$friendship.created|escape:html}</td>
              <td style="padding:9px 8px;border-bottom:1px solid rgba(255,255,255,.08);text-align:right;white-space:nowrap">
                <a class="buttonLike" href="{$friendship.preview_url|escape:html}" target="_blank" rel="noopener" style="display:inline-block;margin-right:6px">Vorschau</a>
                <a class="buttonLike" href="{$friendship.download_url|escape:html}" style="display:inline-block;margin-right:6px">Herunterladen</a>
                <form method="post" style="display:inline" onsubmit="return confirm('Diesen Freundschaftscode wirklich löschen?');">
                  <input type="hidden" name="pwg_token" value="{$PWG_TOKEN|escape:html}">
                  <input type="hidden" name="bratonien_tool" value="friendship_code_delete">
                  <input type="hidden" name="friendship_code_id" value="{$friendship.id}">
                  <button class="buttonLike" type="submit">Löschen</button>
                </form>
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    {else}
      <p class="bratonien-base-note">Noch keine persönlichen Freundschaftscodes hochgeladen.</p>
    {/if}
  </div>
</section>
