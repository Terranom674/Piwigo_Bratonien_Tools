(function () {
  'use strict';

  var statusEndpoint = 'plugins/bratonien_tools/nc-connector-status.php';

  function ensureLiveStatus(detail, actions) {
    var node = detail.querySelector('[data-nc-run-live]');
    if (node) return node;

    node = document.createElement('div');
    node.setAttribute('data-nc-run-live', '1');
    node.className = 'bratonien-base-note';
    node.style.marginTop = '.6rem';
    node.hidden = true;
    actions.parentNode.insertBefore(node, actions.nextSibling);
    return node;
  }

  function renderLiveStatus(node, data) {
    var state = String(data && data.state || '');
    var message = String(data && data.message || '');
    var detail = String(data && data.error_detail || '');

    node.hidden = false;
    node.innerHTML = '';

    var strong = document.createElement('strong');
    if (state === 'queued') strong.textContent = 'Angefordert: ';
    else if (state === 'running') strong.textContent = 'Läuft: ';
    else if (state === 'ok' || state === 'success') strong.textContent = 'Erfolgreich: ';
    else if (state === 'error') strong.textContent = 'Fehler: ';
    else strong.textContent = 'Status: ';
    node.appendChild(strong);
    node.appendChild(document.createTextNode(message || state || 'Status wird ermittelt …'));

    if (detail) {
      var details = document.createElement('details');
      details.style.marginTop = '.35rem';
      var summary = document.createElement('summary');
      summary.textContent = 'Technische Laufzeitdetails';
      var pre = document.createElement('pre');
      pre.style.whiteSpace = 'pre-wrap';
      pre.style.wordBreak = 'break-word';
      pre.textContent = detail;
      details.appendChild(summary);
      details.appendChild(pre);
      node.appendChild(details);
    }
  }

  function pollConnection(connectionId, node, button) {
    var attempts = 0;
    var maxAttempts = 180;
    var timer = null;

    function stop() {
      if (timer) window.clearInterval(timer);
      timer = null;
      if (button) {
        button.disabled = false;
        button.textContent = 'Jetzt abgleichen';
      }
    }

    function poll() {
      attempts += 1;
      fetch(statusEndpoint + '?connection_id=' + encodeURIComponent(connectionId) + '&_=' + Date.now(), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {'Accept': 'application/json'}
      })
        .then(function (response) {
          if (!response.ok) throw new Error('HTTP ' + response.status);
          return response.json();
        })
        .then(function (data) {
          renderLiveStatus(node, data);
          var state = String(data && data.state || '');
          if (state === 'ok' || state === 'success' || state === 'error' || attempts >= maxAttempts) stop();
        })
        .catch(function (error) {
          if (attempts >= maxAttempts) {
            renderLiveStatus(node, {state: 'error', message: 'Status konnte nicht gelesen werden.', error_detail: error.message});
            stop();
          }
        });
    }

    poll();
    timer = window.setInterval(poll, 1000);
  }

  function bindRunNow(form, detail, liveNode) {
    if (form.dataset.ncRunBound === '1') return;
    form.dataset.ncRunBound = '1';

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      detail.open = true;

      var button = form.querySelector('button[value="nc_connector_run_now"]');
      var connectionInput = form.querySelector('input[name="connection_id"]');
      if (!connectionInput) return;

      if (button) {
        button.disabled = true;
        button.textContent = 'Abgleich wird gestartet …';
      }
      renderLiveStatus(liveNode, {state: 'queued', message: 'Abgleich wird angefordert …'});

      fetch(form.action || window.location.href, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        body: new FormData(form)
      })
        .then(function (response) {
          if (!response.ok) throw new Error('HTTP ' + response.status);
          renderLiveStatus(liveNode, {state: 'queued', message: 'Abgleich wurde angefordert.'});
          pollConnection(connectionInput.value, liveNode, button);
        })
        .catch(function (error) {
          renderLiveStatus(liveNode, {state: 'error', message: 'Abgleich konnte nicht angefordert werden.', error_detail: error.message});
          if (button) {
            button.disabled = false;
            button.textContent = 'Jetzt abgleichen';
          }
        });
    });
  }

  function restoreRunNowButtons() {
    var section = document.getElementById('nc-connector');
    if (!section) return;

    var connectionCard = null;
    var headings = section.querySelectorAll('h4');
    for (var i = 0; i < headings.length; i++) {
      if ((headings[i].textContent || '').trim() === 'Bestehende Verbindungen') {
        connectionCard = headings[i].closest('.bratonien-card');
        break;
      }
    }
    if (!connectionCard) return;

    var details = connectionCard.querySelectorAll(':scope > details');
    details.forEach(function (detail) {
      var actions = detail.querySelector('.bratonien-actions');
      if (!actions) return;

      var connectionInput = detail.querySelector('input[name="connection_id"]');
      var tokenInput = detail.querySelector('input[name="pwg_token"]');
      if (!connectionInput || !tokenInput) return;

      var form = actions.querySelector('form[data-nc-run-now]');
      if (!form) {
        form = document.createElement('form');
        form.method = 'post';
        form.setAttribute('data-nc-run-now', '1');

        var token = document.createElement('input');
        token.type = 'hidden';
        token.name = 'pwg_token';
        token.value = tokenInput.value;
        form.appendChild(token);

        var connection = document.createElement('input');
        connection.type = 'hidden';
        connection.name = 'connection_id';
        connection.value = connectionInput.value;
        form.appendChild(connection);

        var button = document.createElement('button');
        button.className = 'buttonLike';
        button.type = 'submit';
        button.name = 'bratonien_tool';
        button.value = 'nc_connector_run_now';
        button.textContent = 'Jetzt abgleichen';
        form.appendChild(button);

        actions.insertBefore(form, actions.firstChild);
      }

      bindRunNow(form, detail, ensureLiveStatus(detail, actions));
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', restoreRunNowButtons);
  } else {
    restoreRunNowButtons();
  }
})();
