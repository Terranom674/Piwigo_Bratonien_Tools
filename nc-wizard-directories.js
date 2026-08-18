(function(){
  'use strict';

  var script=document.currentScript;
  if(!script)return;
  var endpoint=new URL('nc-wizard-directories.php',script.src).toString();

  function fetchListing(path){
    return fetch(endpoint+'?path='+encodeURIComponent(path||''),{credentials:'same-origin',cache:'no-store'})
      .then(function(response){return response.json().then(function(data){if(!response.ok||data.state!=='ok')throw new Error(data.message||('HTTP '+response.status));return data;});});
  }

  function makeButton(text,handler){var b=document.createElement('button');b.type='button';b.className='buttonLike';b.textContent=text;b.addEventListener('click',handler);return b;}

  function enhanceMountForm(){
    var submit=document.querySelector('button[name="bratonien_tool"][value="nc_connector_wizard_save_mounts"]');
    if(!submit)return;
    var form=submit.closest('form');
    if(!form||form.dataset.directoryEnhanced==='1')return;

    fetchListing('').then(function(root){
      if(!root.ready)return;
      form.dataset.directoryEnhanced='1';
      var oldGrid=form.querySelector('.bratonien-form-grid');
      if(!oldGrid)return;
      oldGrid.querySelectorAll('input[type="hidden"][name^="nc_wizard_storage_mount"]').forEach(function(input){form.appendChild(input);});

      var note=form.previousElementSibling;
      var heading=note&&note.previousElementSibling;
      if(heading&&/^H[1-6]$/.test(heading.tagName))heading.textContent='Verzeichnisse auswählen';
      if(note&&note.tagName==='P')note.textContent='Es werden ausschließlich Verzeichnisse des angemeldeten Nextcloud-Benutzers angezeigt. Keine Auswahl bedeutet Stammverzeichnis.';

      var wrapper=document.createElement('div');
      var selected=document.createElement('div');
      var browser=document.createElement('div');
      browser.style.marginTop='.8rem';
      var chosen=[];

      function renderSelected(){
        selected.innerHTML='';
        if(!chosen.length){var empty=document.createElement('p');empty.className='bratonien-base-note';empty.textContent='Keine Auswahl – Stammverzeichnis wird verwendet.';selected.appendChild(empty);}
        chosen.forEach(function(path,index){
          var row=document.createElement('div');row.style.display='flex';row.style.gap='.6rem';row.style.alignItems='center';row.style.margin='.35rem 0';
          var strong=document.createElement('strong');strong.style.flex='1';strong.textContent=path||'Stammverzeichnis';
          var hidden=document.createElement('input');hidden.type='hidden';hidden.name='nc_wizard_directory[0][]';hidden.value=path;
          row.appendChild(strong);row.appendChild(hidden);row.appendChild(makeButton('Entfernen',function(){chosen.splice(index,1);renderSelected();}));selected.appendChild(row);
        });
      }

      function browse(path){
        fetchListing(path).then(function(data){
          browser.innerHTML='';
          var title=document.createElement('p');var strong=document.createElement('strong');strong.textContent='Aktuell: ';title.appendChild(strong);title.appendChild(document.createTextNode('/'+(data.current||'')));browser.appendChild(title);
          var actions=document.createElement('div');actions.className='bratonien-actions';
          actions.appendChild(makeButton('Diesen Ordner hinzufügen',function(){if(chosen.indexOf(data.current)===-1)chosen.push(data.current);renderSelected();}));
          if(data.current!=='')actions.appendChild(makeButton('Eine Ebene hoch',function(){browse(data.parent||'');}));
          browser.appendChild(actions);
          var list=document.createElement('div');list.style.marginTop='.6rem';
          Object.keys(data.children||{}).forEach(function(child){var b=makeButton('📁 '+data.children[child],function(){browse(child);});b.style.display='block';b.style.width='100%';b.style.textAlign='left';b.style.margin='.25rem 0';list.appendChild(b);});
          if(!list.children.length){var p=document.createElement('p');p.className='bratonien-base-note';p.textContent='Keine Unterordner vorhanden.';list.appendChild(p);}
          browser.appendChild(list);
        }).catch(function(error){browser.innerHTML='';var p=document.createElement('p');p.className='bratonien-main-cache__warning';p.textContent=String(error.message||error);browser.appendChild(p);});
      }

      wrapper.appendChild(selected);wrapper.appendChild(browser);oldGrid.replaceWith(wrapper);submit.textContent='Verzeichnisse übernehmen';renderSelected();browse('');
    }).catch(function(){});
  }

  function lockUserField(){
    var field=document.getElementById('nc_wizard_showcase_user');
    if(!field)return;
    var strong=document.createElement('strong');strong.textContent=field.value||'';field.replaceWith(strong);
  }

  function addBackButton(){
    var dialog=document.getElementById('bratonien-nc-wizard-dialog');if(!dialog||dialog.querySelector('[data-nc-wizard-back]'))return;
    var step=0;dialog.querySelectorAll('strong').forEach(function(node){var m=node.textContent.match(/^Schritt\s+(\d+)\s+von/);if(m)step=parseInt(m[1],10);});
    if(step<=1)return;
    var token=dialog.querySelector('input[name="pwg_token"]');if(!token)return;
    var form=document.createElement('form');form.method='post';form.style.display='inline-block';form.style.marginRight='.5rem';form.setAttribute('data-bratonien-wizard-form','');form.appendChild(token.cloneNode(true));
    var b=document.createElement('button');b.type='submit';b.className='buttonLike';b.name='bratonien_tool';b.value='nc_connector_wizard_back';b.textContent='Zurück';b.setAttribute('data-nc-wizard-back','1');form.appendChild(b);
    var reset=dialog.querySelector('button[name="bratonien_tool"][value="nc_connector_wizard_reset"]');if(reset&&reset.closest('form'))reset.closest('form').parentNode.insertBefore(form,reset.closest('form'));else dialog.appendChild(form);
  }

  enhanceMountForm();
  lockUserField();
  addBackButton();
})();
