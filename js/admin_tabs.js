(function () {
  'use strict';

  function initBratonienTabs() {
    var admin = document.querySelector('.bratonien-admin');
    if (!admin) return;

    var definitions = [
      { id: 'uebersicht', label: 'Übersicht' },
      { id: 'wasserzeichen', label: 'Wasserzeichen' },
      { id: 'regeln', label: 'Albumregeln' },
      { id: 'auswahl-download', label: 'Fotoauswahl & Downloads' },
      { id: 'bilddateien', label: 'Bilddateien & Pfade' },
      { id: 'wartung', label: 'Wartung / Cache' }
    ];

    var panels = [];
    definitions.forEach(function (definition) {
      var panel = document.getElementById(definition.id);
      if (!panel) return;

      if (panel.parentNode !== admin) {
        admin.appendChild(panel);
      }

      panel.classList.add('bratonien-tab-panel');
      panel.setAttribute('role', 'tabpanel');
      panel.setAttribute('aria-labelledby', 'bratonien-tab-' + definition.id);
      panels.push({ definition: definition, panel: panel });
    });

    if (!panels.length) return;

    var oldNav = admin.querySelector('.bratonien-nav');
    if (oldNav) oldNav.hidden = true;

    var tabs = document.createElement('div');
    tabs.className = 'bratonien-tabs';
    tabs.setAttribute('role', 'tablist');
    tabs.setAttribute('aria-label', 'Bratonien Tools Bereiche');

    panels.forEach(function (item) {
      var button = document.createElement('button');
      button.type = 'button';
      button.id = 'bratonien-tab-' + item.definition.id;
      button.className = 'bratonien-tab';
      button.setAttribute('role', 'tab');
      button.setAttribute('aria-controls', item.definition.id);
      button.setAttribute('aria-selected', 'false');
      button.dataset.tab = item.definition.id;
      button.textContent = item.definition.label;
      tabs.appendChild(button);
    });

    admin.insertBefore(tabs, admin.firstChild);

    function activate(id, remember) {
      var found = false;

      panels.forEach(function (item) {
        var active = item.definition.id === id;
        item.panel.hidden = !active;
        var button = tabs.querySelector('[data-tab="' + item.definition.id + '"]');
        if (button) {
          button.classList.toggle('is-active', active);
          button.setAttribute('aria-selected', active ? 'true' : 'false');
          button.tabIndex = active ? 0 : -1;
        }
        if (active) found = true;
      });

      if (!found) {
        activate(panels[0].definition.id, remember);
        return;
      }

      if (remember !== false) {
        try { window.localStorage.setItem('bratonien-tools-active-tab', id); } catch (e) {}
      }
    }

    tabs.addEventListener('click', function (event) {
      var button = event.target.closest('.bratonien-tab');
      if (!button) return;
      activate(button.dataset.tab);
    });

    tabs.addEventListener('keydown', function (event) {
      if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
      var buttons = Array.prototype.slice.call(tabs.querySelectorAll('.bratonien-tab'));
      var current = buttons.indexOf(document.activeElement);
      if (current < 0) return;
      event.preventDefault();
      var next = event.key === 'ArrowRight' ? current + 1 : current - 1;
      if (next >= buttons.length) next = 0;
      if (next < 0) next = buttons.length - 1;
      buttons[next].focus();
      activate(buttons[next].dataset.tab);
    });

    var initial = '';
    if (window.location.hash) {
      var hashId = window.location.hash.substring(1);
      if (panels.some(function (item) { return item.definition.id === hashId; })) initial = hashId;
    }
    if (!initial) {
      try { initial = window.localStorage.getItem('bratonien-tools-active-tab') || ''; } catch (e) {}
    }
    if (!panels.some(function (item) { return item.definition.id === initial; })) {
      initial = panels[0].definition.id;
    }

    activate(initial, false);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBratonienTabs);
  } else {
    initBratonienTabs();
  }
})();
