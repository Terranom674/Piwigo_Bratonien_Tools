<?php
/*
Plugin Name: Bratonien Tools
Version: 0.4.1
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
add_event_handler('loc_end_page_tail', 'bratonien_tools_admin_cache_build_ui', 50);

function bratonien_tools_admin_menu($menu)
{
  $menu[] = array(
    'NAME' => 'Bratonien Tools',
    'URL' => get_root_url() . 'admin.php?page=plugin-' . BRATONIEN_TOOLS_ID,
  );

  return $menu;
}

function bratonien_tools_admin_cache_build_ui()
{
  global $template;

  if (script_basename() !== 'admin' || (string)($_GET['page'] ?? '') !== 'plugin-'.BRATONIEN_TOOLS_ID)
  {
    return;
  }

  $status_url = json_encode(get_root_url().'plugins/'.BRATONIEN_TOOLS_ID.'/main-cache-status.php', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  $token = json_encode(get_pwg_token(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

  $script = <<<'HTML'
<style>
.bratonien-main-cache { margin-top:16px; padding-top:14px; border-top:1px solid rgba(255,255,255,.1); }
.bratonien-main-cache__warning { margin:10px 0; color:#d6ae62; font-size:12px; line-height:1.45; }
.bratonien-main-cache__progress { display:none; margin-top:14px; }
.bratonien-main-cache__head { display:flex; justify-content:space-between; gap:12px; margin-bottom:7px; }
.bratonien-main-cache__track { height:18px; overflow:hidden; border:1px solid rgba(255,255,255,.16); border-radius:4px; background:rgba(0,0,0,.25); }
.bratonien-main-cache__bar { width:0; height:100%; background:#66a845; transition:width .25s ease; }
.bratonien-main-cache__progress.is-error .bratonien-main-cache__bar { background:#c95b5b; }
.bratonien-main-cache__progress.is-queued .bratonien-main-cache__bar { background:#a7834d; }
.bratonien-main-cache__details { margin-top:8px; color:#a9a9a9; line-height:1.45; }
.bratonien-main-cache__current { margin-top:4px; font-size:12px; color:#8f8f8f; overflow-wrap:anywhere; }
</style>
<script>
(function(){
  'use strict';
  var statusUrl=__STATUS_URL__;
  var pwgToken=__PWG_TOKEN__;

  function init(){
    var section=document.getElementById('wartung');
    if(!section)return;
    var card=section.querySelector('.bratonien-card');
    if(!card)return;

    var wrap=document.createElement('div');
    wrap.className='bratonien-main-cache';
    wrap.innerHTML=''
      +'<h4>Piwigo-Bildcache vorbereiten</h4>'
      +'<p>Erzeugt die normalen Piwigo-Bildgrößen vorab. Bratonien-Wasserzeichen werden weiterhin nur bei Bedarf erzeugt.</p>'
      +'<p class="bratonien-main-cache__warning"><strong>Experimentell:</strong> Der manuelle Cache-Aufbau erzeugt viele Bildvarianten nacheinander und kann CPU, Arbeitsspeicher und Datenträger des LXC deutlich belasten. Diese Warnung kann entfernt werden, sobald der Ablauf im Dauerbetrieb stabil ist.</p>'
      +'<form method="post"><input type="hidden" name="pwg_token" value="'+String(pwgToken).replace(/&/g,'&amp;').replace(/"/g,'&quot;')+'"><button class="buttonLike" type="submit" name="bratonien_tool" value="image_cache_build">Piwigo-Bildcache aufbauen</button></form>'
      +'<div class="bratonien-main-cache__progress" data-main-cache-progress>'
      +'<div class="bratonien-main-cache__head"><strong data-cache-title>Cache-Aufbau</strong><strong data-cache-percent>0 %</strong></div>'
      +'<div class="bratonien-main-cache__track" role="progressbar" aria-label="Piwigo-Bildcache" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-cache-track><div class="bratonien-main-cache__bar" data-cache-bar></div></div>'
      +'<div class="bratonien-main-cache__details" data-cache-details></div><div class="bratonien-main-cache__current" data-cache-current></div></div>';
    card.appendChild(wrap);

    var box=wrap.querySelector('[data-main-cache-progress]');
    var title=wrap.querySelector('[data-cache-title]');
    var percentEl=wrap.querySelector('[data-cache-percent]');
    var details=wrap.querySelector('[data-cache-details]');
    var current=wrap.querySelector('[data-cache-current]');
    var bar=wrap.querySelector('[data-cache-bar]');
    var track=wrap.querySelector('[data-cache-track]');
    var timer=null;
    var hideTimer=null;

    function schedule(ms){if(timer)clearTimeout(timer);timer=setTimeout(load,ms);}
    function render(data){
      var state=data.state||'idle';
      var total=Math.max(0,parseInt(data.total,10)||0);
      var completed=Math.max(0,parseInt(data.completed,10)||0);
      var percent=total>0?Math.min(100,Math.round(completed/total*100)):(state==='complete'?100:0);
      box.classList.toggle('is-error',state==='error');
      box.classList.toggle('is-queued',state==='queued');
      bar.style.width=percent+'%';
      track.setAttribute('aria-valuenow',String(percent));
      percentEl.textContent=percent+' %';
      var labels={queued:'Cache-Aufbau wartet',running:'Cache-Aufbau läuft',complete:'Cache-Aufbau fertig',error:'Cache-Aufbau mit Fehlern'};
      title.textContent=labels[state]||'Cache-Aufbau';
      details.textContent=(data.message||'')+(total>0?' · '+completed+' / '+total+' Varianten · '+(parseInt(data.generated,10)||0)+' neu · '+(parseInt(data.cached,10)||0)+' vorhanden · '+(parseInt(data.skipped,10)||0)+' übersprungen · '+(parseInt(data.errors,10)||0)+' Fehler':'');
      current.textContent=data.current?('Aktuell: '+data.current):'';

      if(state==='queued'||state==='running'||state==='error'){
        if(hideTimer){clearTimeout(hideTimer);hideTimer=null;}
        box.style.display='block';
      }
      if(state==='queued'||state==='running'){
        schedule(1000);
      }else if(state==='complete'){
        box.style.display='block';
        if(hideTimer)clearTimeout(hideTimer);
        hideTimer=setTimeout(function(){box.style.display='none';},5000);
      }else if(state==='idle'){
        box.style.display='none';
      }
    }

    function load(){
      fetch(statusUrl+(statusUrl.indexOf('?')===-1?'?':'&')+'_='+(Date.now()),{credentials:'same-origin',cache:'no-store'})
        .then(function(r){if(!r.ok)throw new Error('HTTP '+r.status);return r.json();})
        .then(render)
        .catch(function(){box.style.display='block';box.classList.add('is-error');details.textContent='Cache-Status konnte nicht geladen werden.';});
    }
    load();
  }

  if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',init);}else{init();}
})();
</script>
HTML;

  $script = str_replace(array('__STATUS_URL__', '__PWG_TOKEN__'), array($status_url, $token), $script);
  $template->append('footer_elements', $script);
}
