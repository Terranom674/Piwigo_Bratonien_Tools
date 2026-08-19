(function () {
  'use strict';

  function enhanceMigrationButtons(root) {
    [].slice.call(root.querySelectorAll('button[value="nc_connector_migrate_start"]')).forEach(function (button) {
      button.textContent = 'Nextcloud-Ordner auswählen & migrieren';
      button.title = 'Die WebDAV-Quelle wird im Nextcloud-Ordnerbrowser ausgewählt. Der bestehende SMB-/Legacy-Speicher wird nicht als WebDAV-Quelle übernommen.';
    });
  }

  function enhanceEditor(root) {
    var form = root.querySelector('[data-edit-v2-form]');
    if (!form || form.dataset.sourceUiEnhanced === '1') return;
    form.dataset.sourceUiEnhanced = '1';

    var headings = [].slice.call(form.querySelectorAll('h5'));
    var legacyHeading = headings.find(function (node) {
      return (node.textContent || '').trim() === 'Bestehender Legacy-Weg';
    });
    var storageHeading = headings.find(function (node) {
      return (node.textContent || '').trim() === 'Speicherorte';
    });
    var storageList = form.querySelector('[data-storage-list]');
    var addStorage = form.querySelector('[data-add-storage]');

    if (legacyHeading && !form.querySelector('[data-webdav-source-note]')) {
      var note = document.createElement('p');
      note.dataset.webdavSourceNote = '1';
      note.className = 'bratonien-main-cache__warning';
      note.innerHTML = '<strong>Wichtig:</strong> Der folgende SMB-/lokale Speicher gehört ausschließlich zum bisherigen Legacy-Fallback. Er wird <strong>nicht</strong> als WebDAV-Quelle übernommen. Die WebDAV-Quellordner werden beim Start der Migration direkt aus Nextcloud angezeigt und ausgewählt.';
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
