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
      { id:'freigaben', label:'Geschützte Freigaben' },
      { id:'qr-upload', label:'QR-Upload' },
      { id:'nc-connector', label:'NC Connector' },
      { id:'bilddateien', label:'Bilddateien & Pfade' },
      { id:'wartung', label:'Wartung / Cache' },
      { id:'system', label:'System & Updates' }
    ];
    var panels=[];
    definitions.forEach(function(d){
      var ids=d.sections||[d.id],elements=[];
      ids.forEach(function(id){var el=document.getElementById(id);if(el){el.classList.add('bratonien-tab-panel');elements.push(el);}});
      if(elements.length)panels.push({definition:d,elements:elements});
    });
    if(!panels.length)return;
    var anchor=document.getElementById('bratonien-tabs-anchor');if(!anchor)return;
    var tabs=document.createElement('div');tabs.className='bratonien-tabs';tabs.setAttribute('role','tablist');tabs.setAttribute('aria-label','Bratonien Tools Bereiche');
    panels.forEach(function(item){var b=document.createElement('button');b.type='button';b.className='bratonien-tab';b.id='bratonien-tab-'+item.definition.id;b.dataset.tab=item.definition.id;b.setAttribute('role','tab');b.textContent=item.definition.label;tabs.appendChild(b);item.elements.forEach(function(el){el.setAttribute('role','tabpanel');el.setAttribute('aria-labelledby',b.id);});});
    anchor.replaceWith(tabs);
    function activate(id,remember){var valid=false;panels.forEach(function(item){var active=item.definition.id===id;item.elements.forEach(function(el){el.hidden=!active;});var b=tabs.querySelector('[data-tab="'+item.definition.id+'"]');if(b){b.classList.toggle('is-active',active);b.setAttribute('aria-selected',active?'true':'false');b.tabIndex=active?0:-1;}if(active)valid=true;});if(!valid)return activate(panels[0].definition.id,remember);if(remember!==false){try{localStorage.setItem('bratonien-tools-active-tab',id);}catch(e){}}}
    tabs.addEventListener('click',function(e){var b=e.target.closest('.bratonien-tab');if(b)activate(b.dataset.tab);});
    tabs.addEventListener('keydown',function(e){if(e.key!=='ArrowLeft'&&e.key!=='ArrowRight')return;var buttons=[].slice.call(tabs.querySelectorAll('.bratonien-tab'));var i=buttons.indexOf(document.activeElement);if(i<0)return;e.preventDefault();i+=e.key==='ArrowRight'?1:-1;if(i>=buttons.length)i=0;if(i<0)i=buttons.length-1;buttons[i].focus();activate(buttons[i].dataset.tab);});
    var initial='',hash=location.hash?location.hash.slice(1):'';panels.forEach(function(item){if(item.definition.id===hash||(item.definition.sections||[]).indexOf(hash)!==-1)initial=item.definition.id;});if(!initial){try{initial=localStorage.getItem('bratonien-tools-active-tab')||'';}catch(e){}}if(!panels.some(function(i){return i.definition.id===initial;}))initial=panels[0].definition.id;activate(initial,false);
  }

  function initNCWizardLifecycle(){
    var section=document.getElementById('nc-connector');if(!section)return;
    var dialog=document.getElementById('bratonien-nc-wizard-dialog');
    var openButton=document.getElementById('bratonien-nc-wizard-open');
    var closeButton=document.getElementById('bratonien-nc-wizard-close');
    var storageKey='bratonienNcWizardOpen';
    var resetBusy=false;

    function token(){var input=section.querySelector('input[name="pwg_token"]');return input?input.value:'';}
    function setOpen(value){try{if(value)sessionStorage.setItem(storageKey,'1');else sessionStorage.removeItem(storageKey);}catch(e){}}
    function showWizard(){
      setOpen(true);
      if(!dialog)return;
      if(typeof dialog.showModal==='function'&&!dialog.open)dialog.showModal();
      else dialog.setAttribute('open','open');
    }
    function resetServer(){
      var pwgToken=token();
      if(!pwgToken)return Promise.reject(new Error('CSRF token missing'));
      var body=new URLSearchParams();body.set('pwg_token',pwgToken);body.set('bratonien_tool','nc_connector_wizard_reset');
      return fetch(window.location.href,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()});
    }
    function closeAfterReset(){
      if(resetBusy)return;resetBusy=true;setOpen(false);
      resetServer().catch(function(){}).finally(function(){resetBusy=false;if(dialog){if(typeof dialog.close==='function'&&dialog.open)dialog.close();else dialog.removeAttribute('open');}});
    }

    if(openButton){
      openButton.addEventListener('click',function(event){
        event.preventDefault();event.stopImmediatePropagation();showWizard();
      },true);
    }
    if(closeButton){
      closeButton.addEventListener('click',function(event){event.preventDefault();event.stopImmediatePropagation();closeAfterReset();},true);
    }
    if(dialog){
      dialog.addEventListener('cancel',function(event){event.preventDefault();event.stopImmediatePropagation();closeAfterReset();},true);
      dialog.addEventListener('click',function(event){if(event.target===dialog){event.preventDefault();event.stopImmediatePropagation();closeAfterReset();}},true);

      // Every wizard POST explicitly preserves the open state before the
      // browser leaves the page. Validation errors therefore return to the
      // same wizard step instead of closing/resetting the dialog.
      [].slice.call(dialog.querySelectorAll('form[data-bratonien-wizard-form]')).forEach(function(form){
        form.addEventListener('submit',function(){setOpen(true);},true);
      });

      try{if(sessionStorage.getItem(storageKey)==='1')showWizard();}catch(e){}
    }
  }

  function initNCConnectorPolling(){
    var section=document.getElementById('nc-connector');if(!section)return;
    var endpoint='plugins/bratonien_tools/nc-connector-status.php';
    var pollTimer=null;

    function valueNodeForLabel(labelText){
      var labels=[].slice.call(section.querySelectorAll('.bratonien-label'));
      for(var i=0;i<labels.length;i++){
        if((labels[i].textContent||'').trim()===labelText){
          var node=labels[i].nextElementSibling;
          return node&&node.tagName==='STRONG'?node:null;
        }
      }
      return null;
    }

    function lastResultValueNode(){
      var notes=[].slice.call(section.querySelectorAll('.bratonien-base-note'));
      for(var i=0;i<notes.length;i++){
        var text=(notes[i].textContent||'').trim();
        if(text.indexOf('Letztes Ergebnis:')===0){
          return notes[i].querySelector('strong');
        }
      }
      return null;
    }

    var lastRunNode=valueNodeForLabel('Letzter Lauf');
    var nextRunNode=valueNodeForLabel('Nächster Lauf');
    var lastResultNode=lastResultValueNode();

    function render(data){
      if(lastRunNode&&typeof data.last_run_label==='string'&&data.last_run_label!=='')lastRunNode.textContent=data.last_run_label;
      if(nextRunNode&&typeof data.next_run_label==='string'&&data.next_run_label!=='')nextRunNode.textContent=data.next_run_label;
      if(lastResultNode&&typeof data.message==='string'&&data.message!=='')lastResultNode.textContent=data.message;
    }

    function poll(){
      fetch(endpoint+'?_='+Date.now(),{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}})
        .then(function(response){if(!response.ok)throw new Error('HTTP '+response.status);return response.json();})
        .then(render)
        .catch(function(){});
    }

    function schedule(){
      if(pollTimer)window.clearInterval(pollTimer);
      pollTimer=window.setInterval(poll,5000);
    }

    poll();schedule();
  }

  function initAll(){initBratonienTabs();initNCWizardLifecycle();initNCConnectorPolling();}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initAll);else initAll();
})();
</script>
{/literal}
