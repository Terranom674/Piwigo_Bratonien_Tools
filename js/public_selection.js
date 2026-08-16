(function ($) {
  'use strict';

  var state = {
    active: false,
    selected: {}
  };

  function imageId($thumb) {
    var $link = $thumb.find('a[href]').first();
    var href = $link.attr('href') || '';
    var match = href.match(/(?:picture\.php\?\/|\/picture\/|picture\.php\?id=)(\d+)/i);
    if (match) {
      return parseInt(match[1], 10);
    }

    var $img = $thumb.find('img.thumbnail').first();
    var id = $img.data('id') || $thumb.data('id');
    return id ? parseInt(id, 10) : null;
  }

  function thumbs() {
    return $('#thumbnails').find('li.gdthumb, li.thumbnailCategory, li.thumbnail');
  }

  function selectedIds() {
    return Object.keys(state.selected).filter(function (id) {
      return state.selected[id];
    });
  }

  function ensureBar() {
    if ($('#bratonien-selection-bar').length) {
      return;
    }

    $('body').append(
      '<div id="bratonien-selection-bar" hidden>' +
        '<strong><span id="bratonien-selection-count">0</span> Bilder ausgewaehlt</strong>' +
        '<button type="button" id="bratonien-selection-all">Alle auf dieser Seite</button>' +
        '<button type="button" id="bratonien-selection-clear">Auswahl aufheben</button>' +
        '<button type="button" id="bratonien-selection-download" disabled>Auswahl herunterladen</button>' +
      '</div>'
    );
  }

  function decorate() {
    thumbs().each(function () {
      var $thumb = $(this);
      var id = imageId($thumb);
      if (!id) {
        return;
      }
      $thumb.attr('data-bratonien-image-id', id).addClass('bratonien-selectable');
      $thumb.toggleClass('bratonien-selected', !!state.selected[id]);
    });
  }

  function refresh() {
    var count = selectedIds().length;
    $('#bratonien-selection-count').text(count);
    $('#bratonien-selection-download').prop('disabled', count === 0);
    $('#bratonien-selection-bar').prop('hidden', !state.active);
    $('#bratonien-selection-toggle').toggleClass('active', state.active);
    $('body').toggleClass('bratonien-selection-active', state.active);
    decorate();
  }

  function toggleMode(event) {
    event.preventDefault();
    state.active = !state.active;
    refresh();
  }

  function toggleThumb(event) {
    if (!state.active) {
      return;
    }

    var $thumb = $(event.currentTarget);
    var id = parseInt($thumb.attr('data-bratonien-image-id'), 10);
    if (!id) {
      return;
    }

    event.preventDefault();
    event.stopPropagation();
    state.selected[id] = !state.selected[id];
    refresh();
  }

  function selectAll() {
    thumbs().each(function () {
      var id = parseInt($(this).attr('data-bratonien-image-id'), 10);
      if (id) {
        state.selected[id] = true;
      }
    });
    refresh();
  }

  function clearSelection() {
    state.selected = {};
    refresh();
  }

  function downloadSelection() {
    var ids = selectedIds();
    if (!ids.length || !window.BratonienPublicSelection) {
      return;
    }

    var separator = window.BratonienPublicSelection.downloadUrl.indexOf('?') === -1 ? '?' : '&';
    window.location.href = window.BratonienPublicSelection.downloadUrl + separator +
      'bratonien_selection=' + encodeURIComponent(ids.join(','));
  }

  $(function () {
    ensureBar();
    decorate();
    refresh();

    $(document).on('click', '#bratonien-selection-toggle', toggleMode);
    $(document).on('click', '#thumbnails .bratonien-selectable', toggleThumb);
    $(document).on('click', '#bratonien-selection-all', selectAll);
    $(document).on('click', '#bratonien-selection-clear', clearSelection);
    $(document).on('click', '#bratonien-selection-download', downloadSelection);

    $(document).ajaxComplete(function () {
      decorate();
      refresh();
    });
  });
})(jQuery);
