{combine_css path=$BRATONIEN_SELECTION_PATH|cat:'/css/public_selection.css'}
{combine_script id='bratonien.public_selection' require='jquery' path=$BRATONIEN_SELECTION_PATH|cat:'/js/public_selection.js' load='footer'}

<div id="bratonien-selection-bar" class="bratonien-selection-bar" hidden>
  <div class="bratonien-selection-status"><strong><span id="bratonien-selection-count">0</span> ausgewählt</strong></div>
  <div class="bratonien-selection-actions">
    <button type="button" id="bratonien-selection-all">Alle auswählen</button>
    <button type="button" id="bratonien-selection-clear">Auswahl aufheben</button>
    <button type="button" id="bratonien-selection-download" class="bratonien-selection-primary" disabled>Herunterladen</button>
  </div>
  <div id="bratonien-selection-error" class="bratonien-selection-error" hidden></div>
</div>

<script>
window.BratonienSelectionConfig = {
  downloadUrl: '{$BRATONIEN_SELECTION_DOWNLOAD_URL|escape:'javascript'}',
  token: '{$BRATONIEN_SELECTION_TOKEN|escape:'javascript'}'
};
</script>
