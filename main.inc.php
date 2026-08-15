<?php
/*
Plugin Name: Bratonien Tools
Version: 0.3.4
Description: Erweiterbare Administrationswerkzeuge fuer die Bratonien-Piwigo-Installation.
Plugin URI: https://github.com/Terranom674/Piwigo_Bratonien_Tools
Author: Bratonien
*/

if (!defined('PHPWG_ROOT_PATH'))
{
  die('Hacking attempt!');
}

define('BRATONIEN_TOOLS_ID', basename(dirname(__FILE__)));
define('BRATONIEN_TOOLS_PATH', PHPWG_PLUGINS_PATH . BRATONIEN_TOOLS_ID . '/');

require_once(BRATONIEN_TOOLS_PATH . 'include/watermark_runtime.inc.php');

add_event_handler('get_admin_plugin_menu_links', 'bratonien_tools_admin_menu');
add_event_handler('get_derivative_url', 'bratonien_tools_filter_derivative_url', EVENT_HANDLER_PRIORITY_NEUTRAL, 4);
add_event_handler('loc_end_page_tail', 'bratonien_tools_admin_progress_visibility', 50);

function bratonien_tools_admin_menu($menu)
{
  $menu[] = array(
    'NAME' => 'Bratonien Tools',
    'URL' => get_root_url() . 'admin.php?page=plugin-' . BRATONIEN_TOOLS_ID,
  );

  return $menu;
}

function bratonien_tools_admin_progress_visibility()
{
  global $template;

  if (script_basename() !== 'admin')
  {
    return;
  }

  $script = <<<'HTML'
<script>
(function(){
  'use strict';
  function init(){
    var box=document.querySelector('[data-precache-progress]');
    if(!box)return;
    var title=box.querySelector('[data-precache-title]');
    box.style.display='none';
    function update(){
      var text=title?title.textContent:'';
      var visible=box.classList.contains('is-queued')||box.classList.contains('is-error')||text==='Precache läuft';
      box.style.display=visible?'':'none';
    }
    update();
    new MutationObserver(update).observe(box,{subtree:true,childList:true,characterData:true,attributes:true,attributeFilter:['class']});
  }
  if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',init);}else{init();}
})();
</script>
HTML;

  $template->append('footer_elements', $script);
}
