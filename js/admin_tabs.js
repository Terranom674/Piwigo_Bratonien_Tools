(function () {
  'use strict';

  function initNcRouteStatus() {
    var section = document.getElementById('nc-connector');
    if (!section) return;

    var statusCard = section.querySelector('.bratonien-grid .bratonien-card');
    if (!statusCard) return;

    var grid = statusCard.querySelector('.bratonien-form-grid');
    if (!grid) return;

    var routeLabel = document.createElement('span');
    routeLabel.className = 'bratonien-label';
    routeLabel.textContent = 'Aktiver Datenweg';

    var routeValue = document.createElement('strong');
    routeValue.setAttribute('data-nc-route-status', '');
    routeValue.textContent = 'Noch kein Lauf erfasst';

    var routeTimeLabel = document.createElement('span');
    routeTimeLabel.className = 'bratonien-label';
    routeTimeLabel.textContent = 'Datenweg zuletzt';

    var routeTimeValue = document.createElement('strong');
    routeTimeValue.setAttribute('data-nc-route-time', '');
    routeTimeValue.textContent = 'Nicht verfügbar';

    grid.appendChild(routeLabel);
    grid.appendChild(routeValue);
    grid.appendChild(routeTimeLabel);
    grid.appendChild(routeTimeValue);

    var detail = document.createElement('p');
    detail.className = 'bratonien-base-note';
    detail.setAttribute('data-nc-route-detail', '');
    detail.textContent = 'Der Datenweg wird nach dem nächsten Connector-Lauf angezeigt.';
    statusCard.appendChild(detail);

    var basePath = window.location.pathname.replace(/admin\.php.*$/, '');
    var statusUrl = basePath + '_data/bratonien-tools/nc-connector-status/route-status.json';

    function formatTime(timestamp) {
      if (!timestamp) return 'Nicht verfügbar';
      var date = new Date(Number(timestamp) * 1000);
      if (Number.isNaN(date.getTime())) return 'Nicht verfügbar';
      return date.toLocaleString('de-DE');
    }

    function render(data) {
      var label = data && data.label ? String(data.label) : 'Unbekannt';
      var route = data && data.route ? String(data.route) : '';
      routeValue.textContent = label;
      routeTimeValue.textContent = formatTime(data && data.timestamp);

      if (route === 'webdav') {
        routeValue.textContent = 'WEBDAV PRIMÄR';
        detail.textContent = 'Portierung läuft über WebDAV. Legacy wurde in diesem Lauf nicht benutzt.';
      } else if (route === 'legacy_fallback') {
        routeValue.textContent = 'LEGACY-FALLBACK AKTIV';
        detail.textContent = 'WebDAV war nicht erfolgreich. Dieser Lauf wurde über die alte Struktur abgefangen.';
      } else if (route === 'failed') {
        routeValue.textContent = 'FEHLER - KEIN ERFOLGREICHER DATENWEG';
        detail.textContent = data && data.detail ? String(data.detail) : 'WebDAV und Fallback sind fehlgeschlagen.';
      } else {
        detail.textContent = data && data.detail ? String(data.detail) : 'Unbekannter Datenweg.';
      }
    }

    function refresh() {
      fetch(statusUrl + '?t=' + Date.now(), {
        cache: 'no-store',
        credentials: 'same-origin'
      })
        .then(function (response) {
          if (!response.ok) throw new Error('status unavailable');
          return response.json();
        })
        .then(render)
        .catch(function () {
          routeValue.textContent = 'Noch kein Lauf erfasst';
          routeTimeValue.textContent = 'Nicht verfügbar';
          detail.textContent = 'Nach dem nächsten Connector-Lauf wird hier WebDAV oder Legacy-Fallback angezeigt.';
        });
    }

    refresh();
    window.setInterval(refresh, 10000);
  }

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

  function init() {
    initBratonienTabs();
    initNcRouteStatus();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
