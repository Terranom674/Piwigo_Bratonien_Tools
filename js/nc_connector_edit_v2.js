(function () {
  'use strict';

  function ready(callback) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function () { window.setTimeout(callback, 0); });
    } else {
      window.setTimeout(callback, 0);
    }
  }

  ready(function () {
    var section = document.getElementById('nc-connector');
    if (!section) return;

    var pwgTokenInput = section.querySelector('input[name="pwg_token"]');
    var pwgToken = pwgTokenInput ? pwgTokenInput.value : '';
    var modeKey = 'bratonienNcWizardMode';

    function escapeHtml(value) {
      return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
      });
    }

    function setMode(value) {
      try { sessionStorage.setItem(modeKey, value); } catch (e) {}
    }

    function postForm(label, tool, id, mode) {
      var form = document.createElement('form');
      form.method = 'post';
      form.style.display = 'inline';
      form.dataset.ncV2Action = tool;
      form.innerHTML = '<input type="hidden" name="pwg_token" value="'+escapeHtml(pwgToken)+'">'
        + '<input type="hidden" name="connection_id" value="'+escapeHtml(id)+'">'
        + '<button class="buttonLike" type="submit" name="bratonien_tool" value="'+escapeHtml(tool)+'">'+escapeHtml(label)+'</button>';
      if (mode) form.addEventListener('submit', function () { setMode(mode); }, true);
      return form;
    }

    function loadConnection(id) {
      return fetch('plugins/bratonien_tools/nc-connector-edit-data.php?connection_id='+encodeURIComponent(id)+'&_='+Date.now(), {
        credentials:'same-origin',
        cache:'no-store',
        headers:{'Accept':'application/json'}
      }).then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok) throw new Error(data.error || ('HTTP '+response.status));
          return data;
        });
      });
    }

    function dialog() {
      var node = document.getElementById('bratonien-nc-connection-edit-v2');
      if (node) return node;
      node = document.createElement('dialog');
      node.id = 'bratonien-nc-connection-edit-v2';
      node.className = 'bratonien-edit-dialog';
      node.innerHTML = '<div class="bratonien-edit-dialog__body">'
        + '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem">'
        + '<div><h4 style="margin:0">Verbindung bearbeiten</h4><p class="bratonien-base-note" style="margin:.35rem 0 0">Alle Daten dieser Verbindung werden hier gepflegt. Eine Migration ist ein eigener Vorgang.</p></div>'
        + '<button type="button" class="buttonLike" data-edit-v2-close>Schließen</button></div>'
        + '<div data-edit-v2-content style="margin-top:1rem"></div></div>';
      document.body.appendChild(node);
      node.querySelector('[data-edit-v2-close]').addEventListener('click', function () { node.close(); });
      node.addEventListener('click', function (event) { if (event.target === node) node.close(); });
      return node;
    }

    function storageRow(storage) {
      storage = storage || {};
      return '<div class="bratonien-storage-row" data-storage-row>'
        + '<label>Storage-ID<input name="nc_storage_id[]" value="'+escapeHtml(storage.storage_id || '')+'" required></label>'
        + '<label>Quellordner<input name="nc_source_prefix[]" value="'+escapeHtml(storage.source_prefix || '')+'" placeholder="optional"></label>'
        + '<label>Lokaler Speicherpfad<input name="nc_local_mount[]" value="'+escapeHtml(storage.local_mount || '')+'" required></label>'
        + '<button type="button" class="buttonLike" data-remove-storage>Entfernen</button></div>';
    }

    function clearValidation(form) {
      [].slice.call(form.querySelectorAll('[aria-invalid="true"]')).forEach(function (field) {
        field.removeAttribute('aria-invalid');
        field.style.borderColor = '';
        field.style.boxShadow = '';
      });
      [].slice.call(form.querySelectorAll('[data-edit-v2-field-error]')).forEach(function (node) { node.remove(); });
      var box = form.querySelector('[data-edit-v2-error-summary]');
      if (box) box.remove();
    }

    function fieldNodes(form, fieldName) {
      return [].slice.call(form.querySelectorAll('[name="'+fieldName.replace(/"/g, '\\"')+'"]'));
    }

    function showValidation(form, data) {
      clearValidation(form);
      var message = (data && data.message) ? data.message : 'Die Verbindung konnte nicht gespeichert werden.';
      var summary = document.createElement('div');
      summary.dataset.editV2ErrorSummary = '1';
      summary.className = 'bratonien-main-cache__warning';
      summary.style.margin = '0 0 1rem';
      summary.style.padding = '.75rem';
      summary.style.border = '1px solid currentColor';
      summary.innerHTML = '<strong>Speichern fehlgeschlagen:</strong> '+escapeHtml(message);
      form.insertBefore(summary, form.firstChild);

      var first = null;
      var fields = data && Array.isArray(data.fields) ? data.fields : [];
      fields.forEach(function (fieldName) {
        fieldNodes(form, fieldName).forEach(function (field) {
          field.setAttribute('aria-invalid', 'true');
          field.style.borderColor = '#d65a5a';
          field.style.boxShadow = '0 0 0 1px #d65a5a';
          if (!first) first = field;
          var note = document.createElement('span');
          note.dataset.editV2FieldError = '1';
          note.className = 'bratonien-main-cache__warning';
          note.style.display = 'block';
          note.style.marginTop = '.25rem';
          note.textContent = message;
          field.insertAdjacentElement('afterend', note);
        });
      });

      if (first) {
        first.scrollIntoView({block:'center', behavior:'smooth'});
        window.setTimeout(function () { first.focus(); }, 150);
      } else {
        summary.scrollIntoView({block:'center', behavior:'smooth'});
      }
    }

    function submitEditor(form, editDialog) {
      clearValidation(form);
      if (!form.reportValidity()) return;

      var submit = form.querySelector('button[type="submit"]');
      if (submit) submit.disabled = true;

      var body = new FormData(form);
      fetch('plugins/bratonien_tools/nc-connector-edit-save.php', {
        method:'POST',
        credentials:'same-origin',
        cache:'no-store',
        headers:{'Accept':'application/json'},
        body:body
      }).then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok || !data.ok) {
            showValidation(form, data);
            return;
          }
          editDialog.close();
          window.location.reload();
        });
      }).catch(function (error) {
        showValidation(form, {message:error.message || String(error), fields:[]});
      }).finally(function () {
        if (submit) submit.disabled = false;
      });
    }

    function openEditor(id) {
      var editDialog = dialog();
      var content = editDialog.querySelector('[data-edit-v2-content]');
      content.innerHTML = '<p class="bratonien-base-note">Verbindung wird geladen …</p>';
      if (typeof editDialog.showModal === 'function') editDialog.showModal();
      else editDialog.setAttribute('open', 'open');

      loadConnection(id).then(function (data) {
        if (data.adapter !== 'local') throw new Error('Diese Ansicht ist nur für die bestehende Legacy-Verbindung vorgesehen.');
        var legacy = data.legacy || {};
        var webdav = data.webdav || {};
        var storages = Array.isArray(legacy.storages) ? legacy.storages : [];
        var rows = storages.map(storageRow).join('') || storageRow({});
        var migrationText = webdav.migration_ready
          ? '<strong>Bereit.</strong> Nextcloud-Zugang und verbindungseigener Piwigo-API-Key sind vollständig gespeichert.'
          : '<strong>Noch nicht bereit.</strong> Es fehlen: '+escapeHtml((webdav.migration_missing || []).join(', ') || 'WebDAV-Zugangsdaten')+'.';

        content.innerHTML = '<form method="post" data-edit-v2-form>'
          + '<input type="hidden" name="pwg_token" value="'+escapeHtml(pwgToken)+'">'
          + '<input type="hidden" name="connection_id" value="'+escapeHtml(data.id)+'">'
          + '<h5>Verbindung</h5><div class="bratonien-form-grid">'
          + '<label class="bratonien-label">Name</label><input name="connection_name" value="'+escapeHtml(data.name)+'" required>'
          + '</div>'
          + '<h5 style="margin-top:1.2rem">Nextcloud / WebDAV</h5>'
          + '<p class="bratonien-base-note">Diese Angaben werden für den neuen WebDAV-Weg benötigt und gehören zu genau dieser Verbindung.</p>'
          + '<div class="bratonien-form-grid">'
          + '<label class="bratonien-label">Nextcloud-Adresse</label><input name="nc_nextcloud_url" value="'+escapeHtml(webdav.nextcloud_url || '')+'" placeholder="https://cloud.example.de">'
          + '<label class="bratonien-label">Nextcloud-Benutzer</label><input name="nc_nextcloud_user" value="'+escapeHtml(webdav.nextcloud_user || '')+'" autocomplete="username">'
          + '<label class="bratonien-label">Nextcloud-Passwort</label><input name="nc_nextcloud_password" type="password" autocomplete="current-password" placeholder="'+(webdav.has_nextcloud_password ? 'gespeichert – leer = unverändert' : 'noch nicht gespeichert')+'">'
          + '</div>'
          + '<h5 style="margin-top:1.2rem">Piwigo API dieser Verbindung</h5>'
          + '<p class="bratonien-base-note">Der API-Key wird verbindungseigen gespeichert und beim Speichern geprüft.</p>'
          + '<div class="bratonien-form-grid">'
          + '<label class="bratonien-label">API-Schlüssel-ID</label><input name="nc_connection_api_key_id" value="'+escapeHtml(webdav.api_key_id || '')+'" autocomplete="off">'
          + '<label class="bratonien-label">API-Geheimnis</label><input name="nc_connection_api_key_secret" type="password" autocomplete="new-password" placeholder="'+(webdav.has_api_key_secret ? 'gespeichert – leer = unverändert' : 'noch nicht gespeichert')+'">'
          + '</div>'
          + '<p class="bratonien-base-note"><strong>WebDAV-Migration:</strong> '+migrationText+'</p>'
          + '<h5 style="margin-top:1.2rem">Bestehender Legacy-Weg</h5><div class="bratonien-form-grid">'
          + '<label class="bratonien-label">Datenbank-Server</label><input name="nc_host" value="'+escapeHtml(legacy.host)+'" required>'
          + '<label class="bratonien-label">Port</label><input name="nc_port" type="number" min="1" max="65535" value="'+escapeHtml(legacy.port || 5432)+'" required>'
          + '<label class="bratonien-label">Datenbank</label><input name="nc_database" value="'+escapeHtml(legacy.database)+'" required>'
          + '<label class="bratonien-label">Reader-Benutzer</label><input name="nc_user" value="'+escapeHtml(legacy.user)+'" required>'
          + '<label class="bratonien-label">Reader-Passwort</label><input name="nc_db_password" type="password" autocomplete="new-password" placeholder="'+(legacy.has_db_password ? 'gespeichert – leer = unverändert' : 'noch nicht gespeichert')+'">'
          + '</div>'
          + '<h5 style="margin-top:1.2rem">Speicherorte</h5><div data-storage-list>'+rows+'</div>'
          + '<button type="button" class="buttonLike" data-add-storage>Speicherort hinzufügen</button>'
          + '<details style="margin-top:1rem"><summary>Erweiterte Legacy-Einstellungen</summary><div class="bratonien-form-grid" style="margin-top:.75rem">'
          + '<label class="bratonien-label">Source-View</label><input name="nc_source_view" value="'+escapeHtml(legacy.source_view)+'" required>'
          + '<label class="bratonien-label">Activity-View</label><input name="nc_activity_view" value="'+escapeHtml(legacy.activity_view)+'" required>'
          + '<label class="bratonien-label">Piwigo-Galerieordner</label><input name="nc_gallery_root" value="'+escapeHtml(legacy.gallery_root)+'" required>'
          + '<label class="bratonien-label">Ruhezeit (Sek.)</label><input name="nc_quiet_seconds" type="number" min="0" value="'+escapeHtml(legacy.quiet_seconds)+'">'
          + '<label class="bratonien-label">Maximale Wartezeit (Sek.)</label><input name="nc_max_wait_seconds" type="number" min="60" value="'+escapeHtml(legacy.max_wait_seconds)+'">'
          + '<label class="bratonien-label">Vollprüfung nach (Sek.)</label><input name="nc_full_sync_seconds" type="number" min="300" value="'+escapeHtml(legacy.full_sync_seconds)+'">'
          + '</div></details>'
          + '<div class="bratonien-actions" style="margin-top:1rem"><button class="buttonLike" type="submit">Änderungen prüfen und speichern</button><button class="buttonLike" type="button" data-edit-v2-cancel>Abbrechen</button></div>'
          + '</form>';

        var form = content.querySelector('[data-edit-v2-form]');
        form.querySelector('[data-edit-v2-cancel]').addEventListener('click', function () { editDialog.close(); });
        form.querySelector('[data-add-storage]').addEventListener('click', function () {
          form.querySelector('[data-storage-list]').insertAdjacentHTML('beforeend', storageRow({}));
        });
        form.addEventListener('click', function (event) {
          var remove = event.target.closest('[data-remove-storage]');
          if (!remove) return;
          var row = remove.closest('[data-storage-row]');
          if (row) row.remove();
        });
        form.addEventListener('submit', function (event) {
          event.preventDefault();
          event.stopPropagation();
          submitEditor(form, editDialog);
        });
      }).catch(function (error) {
        content.innerHTML = '<p class="bratonien-main-cache__warning"><strong>Bearbeiten nicht möglich:</strong> '+escapeHtml(error.message || String(error))+'</p>';
      });
    }

    function rebuildLocalActions(deleteForm, id) {
      var actions = deleteForm.parentElement;
      if (!actions) return;

      [].slice.call(actions.querySelectorAll('form')).forEach(function (form) {
        var migrate = form.querySelector('button[value="nc_connector_migrate_start"]');
        if (migrate) form.remove();
      });
      [].slice.call(actions.children).forEach(function (child) {
        if (child.tagName === 'BUTTON' && (child.textContent || '').trim() === 'Bearbeiten') child.remove();
        if (child.dataset && child.dataset.ncMigrationInfo) child.remove();
      });

      var edit = document.createElement('button');
      edit.type = 'button';
      edit.className = 'buttonLike';
      edit.textContent = 'Bearbeiten';
      edit.addEventListener('click', function () { openEditor(id); });
      actions.insertBefore(edit, deleteForm);

      var info = document.createElement('span');
      info.dataset.ncMigrationInfo = '1';
      info.className = 'bratonien-base-note';
      info.textContent = 'WebDAV-Migration wird geprüft …';
      actions.insertBefore(info, deleteForm);

      loadConnection(id).then(function (data) {
        var webdav = data.webdav || {};
        if (webdav.migration_ready) {
          info.remove();
          actions.insertBefore(postForm('Auf WebDAV migrieren', 'nc_connector_migrate_start', id, 'migrate'), deleteForm);
        } else {
          var missing = (webdav.migration_missing || []).join(', ');
          info.textContent = 'WebDAV-Migration noch nicht bereit: '+(missing || 'Zugangsdaten fehlen')+'. Zuerst „Bearbeiten“.';
        }
      }).catch(function (error) {
        info.textContent = 'WebDAV-Migration kann nicht geprüft werden: '+(error.message || String(error));
      });
    }

    [].slice.call(section.querySelectorAll('button[value="nc_connector_delete"]')).forEach(function (deleteButton) {
      var deleteForm = deleteButton.closest('form');
      var card = deleteButton.closest('details');
      if (!deleteForm || !card) return;
      var idInput = deleteForm.querySelector('input[name="connection_id"]');
      if (!idInput) return;
      var text = card.textContent || '';
      var isLocal = text.indexOf('bestehende Legacy-Konfiguration') !== -1;
      if (isLocal) rebuildLocalActions(deleteForm, idInput.value);
    });
  });
})();
