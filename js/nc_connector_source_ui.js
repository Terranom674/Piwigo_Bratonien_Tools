(function () {
  'use strict';

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
    });
  }

  function fieldNodes(form, fieldName) {
    return [].slice.call(form.querySelectorAll('[name="'+fieldName.replace(/"/g, '\\"')+'"]'));
  }

  function clearPickerErrors(form) {
    [].slice.call(form.querySelectorAll('[data-source-picker-error]')).forEach(function (node) { node.remove(); });
    [].slice.call(form.querySelectorAll('[data-source-picker-invalid="1"]')).forEach(function (field) {
      field.removeAttribute('data-source-picker-invalid');
      if (field.getAttribute('aria-invalid') === 'true') field.removeAttribute('aria-invalid');
      field.style.borderColor = '';
      field.style.boxShadow = '';
    });
  }

  function showPickerError(form, data) {
    clearPickerErrors(form);
    var message = data && data.message ? data.message : 'Die Nextcloud-Ordnerauswahl konnte nicht gestartet werden.';
    var fields = data && Array.isArray(data.fields) ? data.fields : [];

    var summary = document.createElement('div');
    summary.dataset.sourcePickerError = '1';
    summary.className = 'bratonien-main-cache__warning';
    summary.style.margin = '0 0 1rem';
    summary.style.padding = '.75rem';
    summary.style.border = '1px solid currentColor';
    summary.innerHTML = '<strong>Ordnerauswahl nicht möglich:</strong> '+escapeHtml(message);
    form.insertBefore(summary, form.firstChild);

    var first = null;
    fields.forEach(function (fieldName) {
      fieldNodes(form, fieldName).forEach(function (field) {
        field.dataset.sourcePickerInvalid = '1';
        field.setAttribute('aria-invalid', 'true');
        field.style.borderColor = '#d65a5a';
        field.style.boxShadow = '0 0 0 1px #d65a5a';
        if (!first) first = field;
      });
    });

    if (first) {
      first.scrollIntoView({block:'center', behavior:'smooth'});
      window.setTimeout(function () { first.focus(); }, 150);
    } else {
      summary.scrollIntoView({block:'center', behavior:'smooth'});
    }
  }

  function jsonResponse(response) {
    return response.json().then(function (data) {
      if (!response.ok || !data.ok) throw data;
      return data;
    });
  }

  function startSourcePicker(form, button) {
    clearPickerErrors(form);
    if (!form.reportValidity()) return;

    button.disabled = true;
    var originalText = button.textContent;
    button.textContent = 'Verbindung prüfen …';

    var saveBody = new FormData(form);
    fetch('plugins/bratonien_tools/nc-connector-edit-save.php', {
      method:'POST',
      credentials:'same-origin',
      cache:'no-store',
      headers:{'Accept':'application/json'},
      body:saveBody
    })
      .then(jsonResponse)
      .then(function () {
        button.textContent = 'Nextcloud-Ordner laden …';
        var pickerBody = new FormData();
        var token = form.querySelector('input[name="pwg_token"]');
        var id = form.querySelector('input[name="connection_id"]');
        pickerBody.append('pwg_token', token ? token.value : '');
        pickerBody.append('connection_id', id ? id.value : '');
        return fetch('plugins/bratonien_tools/nc-connector-source-picker-start.php', {
          method:'POST',
          credentials:'same-origin',
          cache:'no-store',
          headers:{'Accept':'application/json'},
          body:pickerBody
        }).then(jsonResponse);
      })
      .then(function () {
        try {
          sessionStorage.setItem('bratonienNcWizardMode', 'migrate');
          sessionStorage.setItem('bratonienNcWizardOpen', '1');
        } catch (e) {}
        window.location.reload();
      })
      .catch(function (error) {
        showPickerError(form, error && typeof error === 'object' ? error : {message:String(error), fields:[]});
        button.disabled = false;
        button.textContent = originalText;
      });
  }

  function enhanceMigrationButtons(root) {
    var label = 'Nextcloud-Ordner auswählen & migrieren';
    var title = 'Die WebDAV-Quelle wird im Nextcloud-Ordnerbrowser ausgewählt. Der bestehende SMB-/Legacy-Speicher wird nicht als WebDAV-Quelle übernommen.';
    [].slice.call(root.querySelectorAll('button[value="nc_connector_migrate_start"]')).forEach(function (button) {
      if (button.textContent !== label) button.textContent = label;
      if (button.title !== title) button.title = title;
    });
  }

  function enhanceEditor(root) {
    var form = root.querySelector('[data-edit-v2-form]');
    if (!form || form.dataset.sourceUiEnhanced === '1') return;
    form.dataset.sourceUiEnhanced = '1';

    var headings = [].slice.call(form.querySelectorAll('h5'));
    var webdavHeading = headings.find(function (node) {
      return (node.textContent || '').trim() === 'Nextcloud / WebDAV';
    });
    var legacyHeading = headings.find(function (node) {
      return (node.textContent || '').trim() === 'Bestehender Legacy-Weg';
    });
    var storageHeading = headings.find(function (node) {
      return (node.textContent || '').trim() === 'Speicherorte';
    });
    var storageList = form.querySelector('[data-storage-list]');
    var addStorage = form.querySelector('[data-add-storage]');

    if (webdavHeading && !form.querySelector('[data-webdav-source-picker]')) {
      var webdavGrid = webdavHeading.nextElementSibling;
      while (webdavGrid && !webdavGrid.classList.contains('bratonien-form-grid')) webdavGrid = webdavGrid.nextElementSibling;
      if (webdavGrid) {
        var picker = document.createElement('div');
        picker.dataset.webdavSourcePicker = '1';
        picker.style.margin = '.8rem 0 1rem';
        picker.innerHTML = '<p class="bratonien-base-note" style="margin:.25rem 0 .5rem"><strong>WebDAV-Quelle:</strong> Du musst keinen Pfad kennen. Die Ordner des oben eingetragenen Nextcloud-Benutzers werden automatisch geladen und können anschließend ausgewählt werden.</p>';
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'buttonLike';
        button.textContent = 'Nextcloud-Ordner automatisch auswählen';
        button.addEventListener('click', function () { startSourcePicker(form, button); });
        picker.appendChild(button);
        webdavGrid.insertAdjacentElement('afterend', picker);
      }
    }

    if (legacyHeading && !form.querySelector('[data-webdav-source-note]')) {
      var note = document.createElement('p');
      note.dataset.webdavSourceNote = '1';
      note.className = 'bratonien-main-cache__warning';
      note.innerHTML = '<strong>Wichtig:</strong> Der folgende SMB-/lokale Speicher gehört ausschließlich zum bisherigen Legacy-Fallback. Er wird <strong>nicht</strong> als WebDAV-Quelle übernommen.';
      legacyHeading.insertAdjacentElement('beforebegin', note);
    }

    if (storageHeading && storageList && addStorage) {
      storageHeading.textContent = 'Legacy-Speicher (nur Fallback)';

      var details = document.createElement('details');
      details.dataset.legacyStorageTechnical = '1';
      details.style.marginTop = '.75rem';
      var summary = document.createElement('summary');
      summary.textContent = 'Technische Legacy-Speicherzuordnung bearbeiten';
      details.appendChild(summary);

      var info = document.createElement('p');
      info.className = 'bratonien-base-note';
      info.textContent = 'Diese Felder betreffen nur den alten Fallback-Weg. Für WebDAV werden keine SMB-Adressen oder lokalen Mount-Pfade eingegeben.';
      details.appendChild(info);

      storageHeading.insertAdjacentElement('beforebegin', details);
      details.appendChild(storageHeading);
      details.appendChild(storageList);
      details.appendChild(addStorage);

      addStorage.textContent = 'Legacy-Speicher manuell hinzufügen';
      addStorage.title = 'Nur für den alten Legacy-Fallback. WebDAV-Ordner werden im Nextcloud-Ordnerbrowser ausgewählt.';
    }
  }

  function enhance(root) {
    if (!root || root.nodeType !== 1) return;
    enhanceMigrationButtons(root);
    if (root.matches && root.matches('#bratonien-nc-connection-edit-v2')) enhanceEditor(root);
    var dialog = root.querySelector ? root.querySelector('#bratonien-nc-connection-edit-v2') : null;
    if (dialog) enhanceEditor(dialog);
  }

  function start() {
    var section = document.getElementById('nc-connector');
    if (!section) return;

    enhance(section);
    enhanceMigrationButtons(document);

    var observer = new MutationObserver(function (mutations) {
      mutations.forEach(function (mutation) {
        [].slice.call(mutation.addedNodes || []).forEach(function (node) {
          if (node && node.nodeType === 1) enhance(node);
        });
      });
      var dialog = document.getElementById('bratonien-nc-connection-edit-v2');
      if (dialog) enhanceEditor(dialog);
      enhanceMigrationButtons(document);
    });

    observer.observe(document.body, {childList:true, subtree:true});
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
