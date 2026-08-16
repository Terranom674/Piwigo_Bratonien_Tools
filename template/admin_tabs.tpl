{literal}
<style>
.bratonien-admin { max-width:none !important; width:100%; margin:0 !important; }
.bratonien-nav { display:none !important; }
.bratonien-tabs { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 18px; padding:0; border-bottom:1px solid rgba(255,255,255,.14); }
.bratonien-tab { appearance:none; border:1px solid rgba(255,255,255,.16); border-bottom:0; border-radius:5px 5px 0 0; background:rgba(0,0,0,.08); color:#d7d7d7; padding:10px 14px; margin:0 0 -1px; cursor:pointer; font:inherit; font-weight:600; }
.bratonien-tab:hover,.bratonien-tab:focus { color:#f0a646; border-color:rgba(240,166,70,.55); outline:none; }
.bratonien-tab.is-active { color:#f0a646; background:rgba(240,166,70,.07); border-color:rgba(240,166,70,.65); box-shadow:inset 0 2px 0 rgba(240,166,70,.75); }
.bratonien-tab-panel { width:100%; max-width:none; margin-left:0 !important; margin-right:0 !important; }
.bratonien-tab-panel[hidden] { display:none !important; }
.bratonien-card { margin-left:0; margin-right:0; }
@media (max-width:760px) { .bratonien-tabs{gap:5px}.bratonien-tab{border-bottom:1px solid rgba(255,255,255,.16);border-radius:4px;margin-bottom:0;padding:9px 11px} }
</style>

<div id="bratonien-tabs-anchor"></div>

<script>
(function () {
  'use strict';
  function initBratonienTabs() {
    var definitions = [
      { id:'wasserzeichen', label:'Wasserzeichen', sections:['uebersicht','wasserzeichen','regeln'] },
      { id:'auswahl-download', label:'Fotoauswahl & Downloads' },
      { id:'bilddateien', label:'Bilddateien & Pfade' },
      { id:'wartung', label:'Wartung / Cache' },
      { id:'system', label:'System & Updates' }
    ];
    var panels = [];
    definitions.forEach(function (d) {
      var ids=d.sections||[d.id], elements=[];
      ids.forEach(function(id){var el=document.getElementById(id);if(el){el.classList.add('bratonien-tab-panel');elements.push(el);}});
      if (!elements.length) return;
      panels.push({definition:d,elements:elements});
    });
    if (!panels.length) return;

    var anchor = document.getElementById('bratonien-tabs-anchor');
    if (!anchor) return;
    var tabs = document.createElement('div');
    tabs.className = 'bratonien-tabs';
    tabs.setAttribute('role','tablist');
    tabs.setAttribute('aria-label','Bratonien Tools Bereiche');

    panels.forEach(function (item) {
      var b = document.createElement('button');
      b.type='button';
      b.className='bratonien-tab';
      b.id='bratonien-tab-'+item.definition.id;
      b.dataset.tab=item.definition.id;
      b.setAttribute('role','tab');
      b.textContent=item.definition.label;
      tabs.appendChild(b);
      item.elements.forEach(function(el){el.setAttribute('role','tabpanel');el.setAttribute('aria-labelledby',b.id);});
    });
    anchor.replaceWith(tabs);

    function activate(id, remember) {
      var valid=false;
      panels.forEach(function (item) {
        var active=item.definition.id===id;
        item.elements.forEach(function(el){el.hidden=!active;});
        var b=tabs.querySelector('[data-tab="'+item.definition.id+'"]');
        if (b) {
          b.classList.toggle('is-active',active);
          b.setAttribute('aria-selected',active?'true':'false');
          b.tabIndex=active?0:-1;
        }
        if (active) valid=true;
      });
      if (!valid) return activate(panels[0].definition.id,remember);
      if (remember!==false) { try { localStorage.setItem('bratonien-tools-active-tab',id); } catch(e) {} }
    }

    tabs.addEventListener('click',function(e){ var b=e.target.closest('.bratonien-tab'); if(b) activate(b.dataset.tab); });
    tabs.addEventListener('keydown',function(e){
      if(e.key!=='ArrowLeft'&&e.key!=='ArrowRight') return;
      var buttons=[].slice.call(tabs.querySelectorAll('.bratonien-tab'));
      var i=buttons.indexOf(document.activeElement); if(i<0) return;
      e.preventDefault(); i += e.key==='ArrowRight'?1:-1; if(i>=buttons.length)i=0; if(i<0)i=buttons.length-1;
      buttons[i].focus(); activate(buttons[i].dataset.tab);
    });

    var initial='';
    var hash=location.hash?location.hash.slice(1):'';
    panels.forEach(function(item){if(item.definition.id===hash||(item.definition.sections||[]).indexOf(hash)!==-1)initial=item.definition.id;});
    if(!initial){ try { initial=localStorage.getItem('bratonien-tools-active-tab')||''; } catch(e){} }
    if(!panels.some(function(i){return i.definition.id===initial})) initial=panels[0].definition.id;
    activate(initial,false);
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',initBratonienTabs); else initBratonienTabs();
})();
</script>
{/literal}
