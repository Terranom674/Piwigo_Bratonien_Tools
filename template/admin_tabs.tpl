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
.bratonien-edit-dialog { width:min(920px,calc(100vw - 3rem)); max-height:88vh; overflow:auto; background:#444; color:inherit; border:1px solid #777; border-radius:4px; padding:0; box-shadow:0 18px 60px rgba(0,0,0,.55); }
.bratonien-edit-dialog::backdrop { background:rgba(0,0,0,.55); }
.bratonien-edit-dialog__body { padding:1.25rem 1.5rem; }
.bratonien-storage-row { display:grid; grid-template-columns:minmax(120px,.7fr) minmax(150px,1fr) minmax(240px,1.5fr) auto; gap:.5rem; align-items:end; margin:.5rem 0; }
.bratonien-storage-row label { display:flex; flex-direction:column; gap:.25rem; }
.bratonien-storage-row input { width:100%; box-sizing:border-box; }
@media (max-width:760px) { .bratonien-tabs{gap:5px}.bratonien-tab{border-bottom:1px solid rgba(255,255,255,.16);border-radius:4px;margin-bottom:0;padding:9px 11px}.bratonien-storage-row{grid-template-columns:1fr} }
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
    var modeKey='bratonienNcWizardMode';
    var resetBusy=false;

    function token(){var input=section.querySelector('input[name="pwg_token"]');return input?input.value:'';}
    function setOpen(value){try{if(value)sessionStorage.setItem(storageKey,'1');else sessionStorage.removeItem(storageKey);}catch(e){}}
    function mode(){try{return sessionStorage.getItem(modeKey)||'';}catch(e){return '';}}
    function clearMode(){try{sessionStorage.removeItem(modeKey);}catch(e){}}
    function applyMode(){
      if(!dialog)return;
      var current=mode();
      var heading=dialog.querySelector('h4');
      var finish=dialog.querySelector('button[value="nc_connector_wizard_finish"]');
      if(current==='edit'){
        if(heading)heading.textContent='Verbindung bearbeiten';
        if(finish)finish.textContent='Änderungen speichern';
      }else if(current==='migrate'){
        if(heading)heading.textContent='Auf WebDAV migrieren';
        if(finish)finish.textContent='Migration starten';
      }else{
        if(heading)heading.textContent='Neue Verbindung';
        if(finish)finish.textContent='Verbindung anlegen';
      }
    }
    function showWizard(){
      setOpen(true);applyMode();
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
      if(resetBusy)return;resetBusy=true;setOpen(false);clearMode();
      resetServer().catch(function(){}).finally(function(){resetBusy=false;if(dialog){if(typeof dialog.close==='function'&&dialog.open)dialog.close();else dialog.removeAttribute('open');}});
    }

    if(openButton){
      openButton.addEventListener('click',function(event){
        event.preventDefault();event.stopImmediatePropagation();clearMode();showWizard();
      },true);
    }
    if(closeButton){
      closeButton.addEventListener('click',function(event){event.preventDefault();event.stopImmediatePropagation();closeAfterReset();},true);
    }
    if(dialog){
      dialog.addEventListener('cancel',function(event){event.preventDefault();event.stopImmediatePropagation();closeAfterReset();},true);
      dialog.addEventListener('click',function(event){if(event.target===dialog){event.preventDefault();event.stopImmediatePropagation();closeAfterReset();}},true);
      [].slice.call(dialog.querySelectorAll('form[data-bratonien-wizard-form]')).forEach(function(form){
        form.addEventListener('submit',function(event){
          var submitter=event.submitter;
          setOpen(true);
          if(submitter&&submitter.hasAttribute('data-bratonien-wizard-end')){
            setOpen(false);
            if(submitter.value==='nc_connector_wizard_reset')clearMode();
          }
        },true);
      });
      try{if(sessionStorage.getItem(storageKey)==='1')showWizard();}catch(e){}
      applyMode();
    }
  }

  function initNCConnectionEditing(){
    var section=document.getElementById('nc-connector');if(!section)return;
    var modeKey='bratonienNcWizardMode';
    var pwgTokenInput=section.querySelector('input[name="pwg_token"]');
    var pwgToken=pwgTokenInput?pwgTokenInput.value:'';

    function setMode(value){try{sessionStorage.setItem(modeKey,value);}catch(e){}}
    function makePostButton(label,tool,id,modeValue){
      var form=document.createElement('form');form.method='post';form.style.display='inline';
      var token=document.createElement('input');token.type='hidden';token.name='pwg_token';token.value=pwgToken;form.appendChild(token);
      var connection=document.createElement('input');connection.type='hidden';connection.name='connection_id';connection.value=String(id);form.appendChild(connection);
      var button=document.createElement('button');button.type='submit';button.className='buttonLike';button.name='bratonien_tool';button.value=tool;button.textContent=label;
      if(modeValue)form.addEventListener('submit',function(){setMode(modeValue);});
      form.appendChild(button);return form;
    }
    function escapeHtml(value){return String(value==null?'':value).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
    function localEditorDialog(){
      var dialog=document.getElementById('bratonien-nc-local-edit-dialog');
      if(dialog)return dialog;
      dialog=document.createElement('dialog');dialog.id='bratonien-nc-local-edit-dialog';dialog.className='bratonien-edit-dialog';
      dialog.innerHTML='<div class="bratonien-edit-dialog__body"><div style="display:flex;align-items:center;justify-content:space-between;gap:1rem"><div><h4 style="margin:0">Verbindung bearbeiten</h4><p class="bratonien-base-note" style="margin:.35rem 0 0">Bestehende Legacy-Verbindung. Änderungen werden in derselben Verbindung gespeichert.</p></div><button type="button" class="buttonLike" data-local-edit-close>Schließen</button></div><div data-local-edit-content style="margin-top:1rem"></div></div>';
      document.body.appendChild(dialog);
      dialog.querySelector('[data-local-edit-close]').addEventListener('click',function(){dialog.close();});
      dialog.addEventListener('click',function(event){if(event.target===dialog)dialog.close();});
      return dialog;
    }
    function storageRow(storage){
      return '<div class="bratonien-storage-row" data-storage-row>'+
        '<label>Storage-ID<input name="nc_storage_id[]" value="'+escapeHtml(storage.storage_id||'')+'" required></label>'+
        '<label>Quellordner<input name="nc_source_prefix[]" value="'+escapeHtml(storage.source_prefix||'')+'" placeholder="optional"></label>'+
        '<label>Lokaler Speicherpfad<input name="nc_local_mount[]" value="'+escapeHtml(storage.local_mount||'')+'" required></label>'+
        '<button type="button" class="buttonLike" data-remove-storage>Entfernen</button></div>';
    }
    function openLocalEditor(id){
      var dialog=localEditorDialog();
      var content=dialog.querySelector('[data-local-edit-content]');
      content.innerHTML='<p class="bratonien-base-note">Verbindung wird geladen …</p>';
      if(typeof dialog.showModal==='function')dialog.showModal();else dialog.setAttribute('open','open');
      fetch('plugins/bratonien_tools/nc-connector-edit-data.php?connection_id='+encodeURIComponent(id)+'&_='+Date.now(),{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}})
        .then(function(response){return response.json().then(function(data){if(!response.ok)throw new Error(data.error||('HTTP '+response.status));return data;});})
        .then(function(data){
          if(data.adapter!=='local')throw new Error('Diese Verbindung ist keine Legacy-Verbindung.');
          var legacy=data.legacy||{};var storages=Array.isArray(legacy.storages)?legacy.storages:[];
          var rows=storages.map(storageRow).join('');
          if(!rows)rows=storageRow({});
          content.innerHTML='<form method="post" data-local-edit-form>'+
            '<input type="hidden" name="pwg_token" value="'+escapeHtml(pwgToken)+'">'+
            '<input type="hidden" name="connection_id" value="'+escapeHtml(data.id)+'">'+
            '<div class="bratonien-form-grid">'+
              '<label class="bratonien-label">Name</label><input name="connection_name" value="'+escapeHtml(data.name)+'" required>'+
              '<label class="bratonien-label">Datenbank-Server</label><input name="nc_host" value="'+escapeHtml(legacy.host)+'" required>'+
              '<label class="bratonien-label">Port</label><input name="nc_port" type="number" min="1" max="65535" value="'+escapeHtml(legacy.port||5432)+'" required>'+
              '<label class="bratonien-label">Datenbank</label><input name="nc_database" value="'+escapeHtml(legacy.database)+'" required>'+
              '<label class="bratonien-label">Reader-Benutzer</label><input name="nc_user" value="'+escapeHtml(legacy.user)+'" required>'+
              '<label class="bratonien-label">Reader-Passwort</label><input name="nc_db_password" type="password" autocomplete="new-password" placeholder="leer = unverändert">'+
            '</div>'+
            '<h5 style="margin-top:1rem">Speicherorte</h5><p class="bratonien-base-note">Jeder Speicherort wird einzeln bearbeitet. Es ist kein Pipe-/Textformat erforderlich.</p><div data-storage-list>'+rows+'</div><button type="button" class="buttonLike" data-add-storage>Speicherort hinzufügen</button>'+
            '<details style="margin-top:1rem"><summary>Erweiterte Legacy-Einstellungen</summary><div class="bratonien-form-grid" style="margin-top:.75rem">'+
              '<label class="bratonien-label">Source-View</label><input name="nc_source_view" value="'+escapeHtml(legacy.source_view)+'" required>'+
              '<label class="bratonien-label">Activity-View</label><input name="nc_activity_view" value="'+escapeHtml(legacy.activity_view)+'" required>'+
              '<label class="bratonien-label">Piwigo-Galerieordner</label><input name="nc_gallery_root" value="'+escapeHtml(legacy.gallery_root)+'" required>'+
              '<label class="bratonien-label">Ruhezeit (Sek.)</label><input name="nc_quiet_seconds" type="number" min="0" value="'+escapeHtml(legacy.quiet_seconds)+'">'+
              '<label class="bratonien-label">Maximale Wartezeit (Sek.)</label><input name="nc_max_wait_seconds" type="number" min="60" value="'+escapeHtml(legacy.max_wait_seconds)+'">'+
              '<label class="bratonien-label">Vollprüfung nach (Sek.)</label><input name="nc_full_sync_seconds" type="number" min="300" value="'+escapeHtml(legacy.full_sync_seconds)+'">'+
            '</div></details>'+
            '<div class="bratonien-actions" style="margin-top:1rem"><button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_update_local">Änderungen speichern</button><button class="buttonLike" type="button" data-local-edit-cancel>Abbrechen</button></div></form>';
          var form=content.querySelector('[data-local-edit-form]');
          form.querySelector('[data-local-edit-cancel]').addEventListener('click',function(){dialog.close();});
          form.querySelector('[data-add-storage]').addEventListener('click',function(){form.querySelector('[data-storage-list]').insertAdjacentHTML('beforeend',storageRow({}));});
          form.addEventListener('click',function(event){var remove=event.target.closest('[data-remove-storage]');if(remove){var row=remove.closest('[data-storage-row]');if(row)row.remove();}});
        })
        .catch(function(error){content.innerHTML='<p class="bratonien-main-cache__warning"><strong>Bearbeiten nicht möglich:</strong> '+escapeHtml(error.message||String(error))+'</p>';});
    }

    [].slice.call(section.querySelectorAll('button[value="nc_connector_edit_start"]')).forEach(function(button){var form=button.closest('form');if(form)form.remove();});

    [].slice.call(section.querySelectorAll('button[value="nc_connector_delete"]')).forEach(function(deleteButton){
      var deleteForm=deleteButton.closest('form');var card=deleteButton.closest('details');if(!deleteForm||!card)return;
      var idInput=deleteForm.querySelector('input[name="connection_id"]');if(!idInput)return;var id=idInput.value;
      var actions=deleteForm.parentElement;if(!actions)return;
      var text=card.textContent||'';var isLocal=text.indexOf('bestehende Legacy-Konfiguration')!==-1;
      if(isLocal){
        var edit=document.createElement('button');edit.type='button';edit.className='buttonLike';edit.textContent='Bearbeiten';edit.addEventListener('click',function(){openLocalEditor(id);});actions.insertBefore(edit,deleteForm);
        actions.insertBefore(makePostButton('Auf WebDAV migrieren','nc_connector_migrate_start',id,'migrate'),deleteForm);
      }else{
        actions.insertBefore(makePostButton('Bearbeiten','nc_connector_edit_start',id,'edit'),deleteForm);
      }
    });
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

  function initAll(){initBratonienTabs();initNCWizardLifecycle();initNCConnectionEditing();initNCConnectorPolling();}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initAll);else initAll();
})();
</script>
{/literal}
