{combine_css path=$BRATONIEN_SELECTION_PATH|cat:'/css/public_selection.css' version=1}
{combine_script id='bratonien.public-selection' path=$BRATONIEN_SELECTION_PATH|cat:'/js/public_selection.js' require='jquery' load='footer'}

{footer_script require='bratonien.public-selection'}
window.BratonienPublicSelection = {
  downloadUrl: '{$BRATONIEN_SELECTION_DOWNLOAD_URL|escape:'javascript'}'
};
{/footer_script}
