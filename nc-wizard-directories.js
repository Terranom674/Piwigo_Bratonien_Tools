(function(){
  'use strict';

  var submit=document.querySelector('button[name="bratonien_tool"][value="nc_connector_wizard_save_mounts"]');
  if(!submit)return;

  var script=document.currentScript;
  if(!script)return;
  var endpoint=new URL('nc-wizard-directories.php',script.src).toString();

  function optionElement(value,label){
    var option=document.createElement('option');
    option.value=value;
    option.textContent=label;
    return option;
  }

  function addRow(container,index,options,value){
    var row=document.createElement('div');
    row.style.display='flex';
    row.style.gap='.6rem';
    row.style.alignItems='center';
    row.style.margin='.4rem 0';

    var select=document.createElement('select');
    select.name='nc_wizard_directory['+index+'][]';
    select.style.flex='1 1 auto';
    Object.keys(options).forEach(function(key){select.appendChild(optionElement(key,options[key]));});
    select.value=value||'';

    var remove=document.createElement('button');
    remove.type='button';
    remove.className='buttonLike';
    remove.textContent='Entfernen';
    remove.addEventListener('click',function(){row.remove();});

    row.appendChild(select);
    row.appendChild(remove);
    container.appendChild(row);
  }

  function enhance(data){
    if(!data||data.state!=='ok'||!data.ready||!Array.isArray(data.storages)||!data.storages.length)return;

    var form=submit.closest('form');
    if(!form||form.dataset.directoryEnhanced==='1')return;
    form.dataset.directoryEnhanced='1';

    var dialog=document.getElementById('bratonien-nc-wizard-dialog');
    if(dialog){
      dialog.querySelectorAll('p.bratonien-base-note').forEach(function(paragraph){
        if(paragraph.textContent.indexOf('Der Assistent verwendet den Zugang aus dem ersten Schritt')!==-1){
          paragraph.innerHTML='<strong>Nextcloud wurde gefunden.</strong> Die vorhandene Reader-Verbindung wurde automatisch erkannt und erfolgreich für den Datenzugriff verwendet.';
        }
      });
    }

    var note=form.previousElementSibling;
    var heading=note&&note.previousElementSibling;
    if(heading&&/^H[1-6]$/.test(heading.tagName))heading.textContent='Verzeichnisse auswählen';
    if(note&&note.tagName==='P')note.textContent='Wähle die Verzeichnisse aus, die berücksichtigt werden sollen. Du kannst mehrere hinzufügen oder wieder entfernen. Bleibt die Liste leer, wird automatisch das Stammverzeichnis verwendet.';

    var oldGrid=form.querySelector('.bratonien-form-grid');
    if(!oldGrid)return;
    oldGrid.querySelectorAll('input[type="hidden"][name^="nc_wizard_storage_mount"]').forEach(function(input){form.appendChild(input);});

    var wrapper=document.createElement('div');
    wrapper.className='bratonien-form-grid';

    data.storages.forEach(function(storage){
      var label=document.createElement('span');
      label.className='bratonien-label';
      label.textContent=data.storages.length>1?'Speicher '+(storage.index+1):'Verzeichnisse';

      var area=document.createElement('div');
      var list=document.createElement('div');
      var options=storage.options||{'':'Stammverzeichnis'};
      addRow(list,storage.index,options,'');

      var add=document.createElement('button');
      add.type='button';
      add.className='buttonLike';
      add.textContent='Verzeichnis hinzufügen';
      add.style.marginTop='.4rem';
      add.addEventListener('click',function(){addRow(list,storage.index,options,'');});

      area.appendChild(list);
      area.appendChild(add);
      wrapper.appendChild(label);
      wrapper.appendChild(area);
    });

    oldGrid.replaceWith(wrapper);
    submit.textContent='Verzeichnisse übernehmen';
  }

  fetch(endpoint,{credentials:'same-origin',cache:'no-store'})
    .then(function(response){if(!response.ok)throw new Error('HTTP '+response.status);return response.json();})
    .then(enhance)
    .catch(function(){/* Der vorhandene manuelle Speicherpfad-Dialog bleibt als sicherer Fallback bestehen. */});
})();
