(function () {
  'use strict';

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
      if (!actions || actions.querySelector('[value="nc_connector_run_now"]')) return;

      var connectionInput = detail.querySelector('input[name="connection_id"]');
      var tokenInput = detail.querySelector('input[name="pwg_token"]');
      if (!connectionInput || !tokenInput) return;

      var form = document.createElement('form');
      form.method = 'post';

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
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', restoreRunNowButtons);
  } else {
    restoreRunNowButtons();
  }
})();
