{combine_css path=$BRATONIEN_SELECTION_PATH|cat:'/css/public_selection.css'}
{combine_script id='bratonien.public_selection' require='jquery' path=$BRATONIEN_SELECTION_PATH|cat:'/js/public_selection.js' load='footer'}

<div id="bratonien-selection-bar" class="bratonien-selection-bar" hidden>
  <strong><span id="bratonien-selection-count">0</span> Bilder ausgewaehlt</strong>
  <button type="button" id="bratonien-selection-download" disabled>Auswahl herunterladen</button>
  <button type="button" id="bratonien-selection-clear">Auswahl aufheben</button>
</div>

<script>
window.BratonienSelectionConfig = {
  downloadUrl: '{$BRATONIEN_SELECTION_DOWNLOAD_URL|escape:'javascript'}'
};
</script>
