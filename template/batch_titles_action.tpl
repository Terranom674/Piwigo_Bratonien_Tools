<div class="bratonien-batch-titles" data-bratonien-batch-titles>
  <p><strong>Fortlaufende Bildtitel</strong></p>
  <p>Vergibt Titel nur an die aktuell in der Stapelverarbeitung ausgewählten Bilder.</p>

  <div class="bratonien-batch-titles__grid">
    <label for="bratonien-title-prefix">Titelpräfix</label>
    <input id="bratonien-title-prefix" type="text" name="bratonien_title_prefix" value="{$BRATONIEN_BATCH_TITLES.prefix|escape:html}" placeholder="z. B. Samt 2026">

    <label for="bratonien-title-start">Startnummer</label>
    <input id="bratonien-title-start" type="number" name="bratonien_title_start" value="{$BRATONIEN_BATCH_TITLES.start}" min="0" step="1">

    <label for="bratonien-title-padding">Stellenzahl</label>
    <input id="bratonien-title-padding" type="number" name="bratonien_title_padding" value="{$BRATONIEN_BATCH_TITLES.padding}" min="1" max="12" step="1">

    <label for="bratonien-title-replace-mode">Vorhandene Titel</label>
    <select id="bratonien-title-replace-mode" name="bratonien_title_replace_mode">
      <option value="camera" {if $BRATONIEN_BATCH_TITLES.replace_mode == 'camera'}selected{/if}>Nur leere, Kamera- oder Importtitel ersetzen</option>
      <option value="all" {if $BRATONIEN_BATCH_TITLES.replace_mode == 'all'}selected{/if}>Alle ausgewählten Titel überschreiben</option>
    </select>

    <label for="bratonien-title-sort">Reihenfolge</label>
    <select id="bratonien-title-sort" name="bratonien_title_sort">
      <option value="filename" {if $BRATONIEN_BATCH_TITLES.sort == 'filename'}selected{/if}>Dateiname</option>
      <option value="date_creation" {if $BRATONIEN_BATCH_TITLES.sort == 'date_creation'}selected{/if}>Aufnahmedatum</option>
      <option value="album_order" {if $BRATONIEN_BATCH_TITLES.sort == 'album_order'}selected{/if}>Aktuelle Albumreihenfolge</option>
    </select>
  </div>

  <div class="bratonien-batch-titles__preview">
    <strong>Vorschau</strong>
    <div data-bratonien-title-preview></div>
  </div>

  <p class="bratonien-batch-titles__note">Die physischen Dateinamen werden nicht verändert. Bei „Aktuelle Albumreihenfolge“ muss die Stapelverarbeitung auf genau ein Album ohne Unteralben gefiltert sein.</p>
</div>

<style>
.bratonien-batch-titles { max-width:760px; padding:14px 0; }
.bratonien-batch-titles__grid { display:grid; grid-template-columns:190px minmax(220px,1fr); gap:10px 14px; align-items:center; }
.bratonien-batch-titles__grid label { font-weight:600; }
.bratonien-batch-titles__grid input[type=number] { width:110px; }
.bratonien-batch-titles__preview { margin-top:16px; padding:12px 14px; border:1px solid rgba(255,255,255,.15); border-radius:4px; background:rgba(0,0,0,.08); }
.bratonien-batch-titles__preview [data-bratonien-title-preview] { margin-top:6px; line-height:1.6; }
.bratonien-batch-titles__note { margin:12px 0 0; color:#999; font-size:12px; line-height:1.45; }
@media (max-width:700px) { .bratonien-batch-titles__grid { grid-template-columns:1fr; } }
</style>

<script>
(function () {
  'use strict';

  function initBatchTitlePreview() {
    var root = document.querySelector('[data-bratonien-batch-titles]');
    if (!root || root.getAttribute('data-preview-ready') === '1') {
      return;
    }

    root.setAttribute('data-preview-ready', '1');

    var prefix = root.querySelector('[name="bratonien_title_prefix"]');
    var start = root.querySelector('[name="bratonien_title_start"]');
    var padding = root.querySelector('[name="bratonien_title_padding"]');
    var preview = root.querySelector('[data-bratonien-title-preview]');

    function render() {
      var prefixValue = (prefix.value || '').trim();
      var startValue = parseInt(start.value, 10);
      var paddingValue = parseInt(padding.value, 10);

      if (!Number.isFinite(startValue) || startValue < 0) startValue = 0;
      if (!Number.isFinite(paddingValue) || paddingValue < 1) paddingValue = 1;
      if (paddingValue > 12) paddingValue = 12;

      var titles = [];
      for (var i = 0; i < 3; i += 1) {
        var number = String(startValue + i).padStart(paddingValue, '0');
        titles.push(prefixValue ? prefixValue + ' - ' + number : number);
      }

      preview.textContent = titles.join('  ·  ') + '  ·  …';
    }

    prefix.addEventListener('input', render);
    start.addEventListener('input', render);
    padding.addEventListener('input', render);
    render();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBatchTitlePreview);
  } else {
    initBatchTitlePreview();
  }
})();
</script>
