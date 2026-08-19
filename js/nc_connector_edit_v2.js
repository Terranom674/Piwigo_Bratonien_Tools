(function () {
  'use strict';

  function ready(callback) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', callback);
    else callback();
  }

  ready(function () {
    var section = document.getElementById('nc-connector');
    if (!section) return;

    var tokenInput = section.querySelector('input[name="pwg_token"]');
    var pwgToken = tokenInput ? tokenInput.value : '';

    var technicalButton = document.getElementById('bratonien-nc-technical-open');
    if (technicalButton) technicalButton.remove();
    var technicalCreate = document.getElementById('bratonien-nc-technical-create');
    if (technicalCreate) technicalCreate.remove();

    var statusHeading = Array.prototype.find.call(section.querySelectorAll('h4'), function (heading) {
      return heading.textContent.trim() === 'Status';
    });
    var statusCard = statusHeading ? statusHeading.closest('.bratonien-card') : null;
    if (statusCard && pwgToken && !statusCard.querySelector('[data-nc-run-now]')) {
      var runForm = document.createElement('form');
      runForm.method = 'post';
      runForm.className = 'bratonien-actions';
      runForm.style.marginTop = '1rem';
      runForm.setAttribute('data-nc-run-now', '1');
      runForm.innerHTML = '<input type="hidden" name="pwg_token" value="'+escapeHtml(pwgToken)+'">'
        + '<button class="buttonLike" type="submit" name="bratonien_tool" value="nc_connector_run_now">Jetzt abgleichen</button>';
      runForm.addEventListener('submit', function () {
        var button = runForm.querySelector('button');
        if (button) {
          button.disabled = true;
          button.textContent = 'Abgleich wird gestartet …';
        }
      });
      statusCard.appendChild(runForm);
    }

    function escapeHtml(value) {
      return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
      });
    }

    function loadConnection(id) {
      return fetch('plugins/bratonien_tools/nc-connector-edit-data.php?connection_id='+encodeURIComponent(id)+'&_='+Date.now(), {
        credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json'}
      }).then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok) throw new Error(data.error || ('HTTP '+response.status));
          return data;
        });
      });
    }

    function createPostForm(label, tool, id) {
      var form = document.createElement('form');
      form.method = 'post';
      form.style.display = 'inline';
      form.innerHTML = '<input type="hidden" name="pwg_token" value="'+escapeHtml(pwgToken)+'">'
        + '<input type="hidden" name="connection_id" value="'+escapeHtml(id)+'">'
        + '<button class="buttonLike" type="submit" name="bratonien_tool" value="'+escapeHtml(tool)+'">'+escapeHtml(label)+'</button>';
      return form;
    }

    function dialog() {
      var node = document.getElementById('bratonien-nc-connection-edit-v2');
      if (node) return node;
      node = document.createElement('dialog');
      node.id = 'bratonien-nc-connection-edit-v2';
      node.className = 'bratonien-edit-dialog';
      node.innerHTML = '<div class="bratonien-edit-dialog__body">'
        + '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem">'
        + '<div><h4 style="margin:0">Verbindung bearbeiten</h4><p class="bratonien-base-note" style="margin:.35rem 0 0">Die Einstellungen gelten nur für diese Verbindung.</p></div>'
        + '<button type="button" class="buttonLike" data-edit-close>Schließen</button></div>'
        + '<div data-edit-content style="margin-top:1rem"></div></div>';
      document.body.appendChild(node);
      node.querySelector('[data-edit-close]').addEventListener('click', function () { node.close(); });
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

    function showError(form, data) {
      var old = form.querySelector('[data-edit-error]');
      if (old) old.remove();
      var box = document.createElement('div');
      box.dataset.editError = '1';
      box.className = 'bratonien-main-cache__warning';
      box.style.margin = '0 0 1rem';
      box.textContent = (data && data.message) ? data.message : 'Die Verbindung konnte nicht gespeichert werden.';
      form.insertBefore(box, form.firstChild);
    }

    function submitLocal(form, editDialog) {
      if (!form.reportValidity()) return;
      var button = form.querySelector('button[type="submit"]');
      if (button) button.disabled = true;
      fetch('plugins/bratonien_tools/nc-connector-edit-save.php', {
        method:'POST', credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json'}, body:new FormData(form)
      }).then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok || !data.ok) { showError(form, data); return; }
          editDialog.close();
          window.location.reload();
        });
      }).catch(function (error) {
        showError(form, {message:error.message || String(error)});
      }).finally(function () {
        if (button) button.disabled = false;
      });
    }

    function openLocalEditor(id) {
      var editDialog = dialog();
      var content = editDialog.querySelector('[data-edit-content]');
      content.innerHTML = '<p class="bratonien-base-note">Verbindung wird geladen …</p>';
      if (typeof editDialog.showModal === 'function') editDialog.showModal();
      else editDialog.setAttribute('open', 'open');

      loadConnection(id).then(function (data) {
        if (data.adapter !== 'local') throw new Error('Diese Verbindung wird über den WebDAV-Assistenten bearbeitet.');
        var legacy = data.legacy || {};
        var webdav = data.webdav || {};
        var storages = Array.isArray(legacy.storages) ? legacy.storages : [];
        var rows = storages.map(storageRow).join('') || storageRow({});

        content.innerHTML = '<form method="post" data-edit-form>'
          + '<input type="hidden" name="pwg_token" value="'+escapeHtml(pwgToken)+'">'
          + '<input type="hidden" name="connection_id" value="'+escapeHtml(data.id)+'">'
          + '<h5>Verbindung</h5><div class="bratonien-form-grid">'
          + '<label class="bratonien-label">Name</label><input name="connection_name" value="'+escapeHtml(data.name)+'" required>'
          + '</div>'
          + '<h5 style="margin-top:1.2rem">Nextcloud</h5><div class="bratonien-form-grid">'
          + '<label class="bratonien-label">Nextcloud-Adresse</label><input name="nc_nextcloud_url" value="'+escapeHtml(webdav.nextcloud_url || '')+'">'
          + '<label class="bratonien-label">Nextcloud-Benutzer</label><input name="nc_nextcloud_user" value="'+escapeHtml(webdav.nextcloud_user || '')+'" autocomplete="username">'
          + '<label class="bratonien-label">Nextcloud-Passwort</label><input name="nc_nextcloud_password" type="password" autocomplete="current-password" placeholder="'+(webdav.has_nextcloud_password ? 'gespeichert – leer = unverändert' : 'noch nicht gespeichert')+'">'
          + '</div>'
          + '<h5 style="margin-top:1.2rem">Piwigo-Zugang</h5><div class="bratonien-form-grid">'
          + '<label class="bratonien-label">API-Schlüssel-ID</label><input name="nc_connection_api_key_id" value="'+escapeHtml(webdav.api_key_id || '')+'" autocomplete="off">'
          + '<label class="bratonien-label">API-Geheimnis</label><input name="nc_connection_api_key_secret" type="password" autocomplete="new-password" placeholder="'+(webdav.has_api_key_secret ? 'gespeichert – leer = unverändert' : 'noch nicht gespeichert')+'">'
          + '<label class="bratonien-label">Fallback-Benutzer</label><input name="nc_fallback_user" value="'+escapeHtml(webdav.fallback_user || '')+'" autocomplete="username">'
          + '<label class="bratonien-label">Fallback-Passwort</label><input name="nc_fallback_password" type="password" autocomplete="current-password" placeholder="'+(webdav.has_fallback_password ? 'gespeichert – leer = unverändert' : 'noch nicht gespeichert')+'">'
          + '</div>'
          + '<h5 style="margin-top:1.2rem">Lokaler Connector</h5><div class="bratonien-form-grid">'
          + '<label class="bratonien-label">Datenbank-Server</label><input name="nc_host" value="'+escapeHtml(legacy.host)+'" required>'
          + '<label class="bratonien-label">Port</label><input name="nc_port" type="number" min="1" max="65535" value="'+escapeHtml(legacy.port || 5432)+'" required>'
          + '<label class="bratonien-label">Datenbank</label><input name="nc_database" value="'+escapeHtml(legacy.database)+'" required>'
          + '<label class="bratonien-label">Reader-Benutzer</label><input name="nc_user" value="'+escapeHtml(legacy.user)+'" required>'
          + '<label class="bratonien-label">Reader-Passwort</label><input name="nc_db_password" type="password" autocomplete="new-password" placeholder="'+(legacy.has_db_password ? 'gespeichert – leer = unverändert' : 'noch nicht gespeichert')+'">'
          + '</div>'
          + '<h5 style="margin-top:1.2rem">Speicherorte</h5><div data-storage-list>'+rows+'</div>'
          + '<button type="button" class="buttonLike" data-add-storage>Speicherort hinzufügen</button>'
          + '<details style="margin-top:1rem"><summary>Erweiterte Einstellungen</summary><div class="bratonien-form-grid" style="margin-top:.75rem">'
          + '<label class="bratonien-label">Source-View</label><input name="nc_source_view" value="'+escapeHtml(legacy.source_view)+'" required>'
          + '<label class="bratonien-label">Activity-View</label><input name="nc_activity_view" value="'+escapeHtml(legacy.activity_view)+'" required>'
          + '<label class="bratonien-label">Piwigo-Galerieordner</label><input name="nc_gallery_root" value="'+escapeHtml(legacy.gallery_root)+'" required>'
          + '<label class="bratonien-label">Ruhezeit (Sek.)</label><input name="nc_quiet_seconds" type="number" min="0" value="'+escapeHtml(legacy.quiet_seconds)+'">'
          + '<label class="bratonien-label">Maximale Wartezeit (Sek.)</label><input name="nc_max_wait_seconds" type="number" min="60" value="'+escapeHtml(legacy.max_wait_seconds)+'">'
          + '<label class="bratonien-label">Vollprüfung nach (Sek.)</label><input name="nc_full_sync_seconds" type="number" min="300" value="'+escapeHtml(legacy.full_sync_seconds)+'">'
          + '</div></details>'
          + '<div class="bratonien-actions" style="margin-top:1rem"><button class="buttonLike" type="submit">Änderungen prüfen und speichern</button><button class="buttonLike" type="button" data-cancel>Abbrechen</button></div>'
          + '</form>';

        var form = content.querySelector('[data-edit-form]');
        form.querySelector('[data-cancel]').addEventListener('click', function () { editDialog.close(); });
        form.querySelector('[data-add-storage]').addEventListener('click', function () {
          form.querySelector('[data-storage-list]').insertAdjacentHTML('beforeend', storageRow({}));
        });
        form.addEventListener('click', function (event) {
          var remove = event.target.closest('[data-remove-storage]');
          if (remove) {
            var row = remove.closest('[data-storage-row]');
            if (row) row.remove();
          }
        });
        form.addEventListener('submit', function (event) {
          event.preventDefault();
          submitLocal(form, editDialog);
        });
      }).catch(function (error) {
        content.innerHTML = '<p class="bratonien-main-cache__warning"><strong>Bearbeiten nicht möglich:</strong> '+escapeHtml(error.message || String(error))+'</p>';
      });
    }

    [].slice.call(section.querySelectorAll('button[value="nc_connector_edit_start"]')).forEach(function (button) {
      var form = button.closest('form');
      if (form) form.remove();
    });

    [].slice.call(section.querySelectorAll('button[value="nc_connector_migrate_start"]')).forEach(function (button) {
      var form = button.closest('form');
      if (form) form.remove();
    });

    [].slice.call(section.querySelectorAll('button[value="nc_connector_delete"]')).forEach(function (deleteButton) {
      var deleteForm = deleteButton.closest('form');
      if (!deleteForm || !deleteForm.parentElement) return;
      var idInput = deleteForm.querySelector('input[name="connection_id"]');
      if (!idInput) return;
      var id = idInput.value;
      var actions = deleteForm.parentElement;

      loadConnection(id).then(function (data) {
        var edit;
        if (data.adapter === 'local') {
          edit = document.createElement('button');
          edit.type = 'button';
          edit.className = 'buttonLike';
          edit.textContent = 'Bearbeiten';
          edit.addEventListener('click', function () { openLocalEditor(id); });
        } else {
          edit = createPostForm('Bearbeiten', 'nc_connector_edit_start', id);
        }
        actions.insertBefore(edit, deleteForm);
      }).catch(function (error) {
        var info = document.createElement('span');
        info.className = 'bratonien-main-cache__warning';
        info.textContent = 'Verbindung konnte nicht geladen werden: '+(error.message || String(error));
        actions.insertBefore(info, deleteForm);
      });
    });
  });
})();
