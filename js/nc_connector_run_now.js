(function () {
  'use strict';

  function init() {
    var section = document.getElementById('nc-connector');
    if (!section || section.querySelector('[data-nc-run-now]')) return;

    var statusHeading = Array.prototype.find.call(section.querySelectorAll('h4'), function (heading) {
      return heading.textContent.trim() === 'Status';
    });
    if (!statusHeading) return;

    var card = statusHeading.closest('.bratonien-card');
    if (!card) return;

    var tokenInput = section.querySelector('input[name="pwg_token"]');
    if (!tokenInput || !tokenInput.value) return;

    var form = document.createElement('form');
    form.method = 'post';
    form.className = 'bratonien-actions';
    form.style.marginTop = '1rem';
    form.setAttribute('data-nc-run-now', '1');

    var token = document.createElement('input');
    token.type = 'hidden';
    token.name = 'pwg_token';
    token.value = tokenInput.value;

    var tool = document.createElement('input');
    tool.type = 'hidden';
    tool.name = 'bratonien_tool';
    tool.value = 'nc_connector_run_now';

    var button = document.createElement('button');
    button.type = 'submit';
    button.className = 'buttonLike';
    button.textContent = 'Jetzt abgleichen';

    form.addEventListener('submit', function () {
      button.disabled = true;
      button.textContent = 'Abgleich wird gestartet …';
    });

    form.appendChild(token);
    form.appendChild(tool);
    form.appendChild(button);
    card.appendChild(form);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
