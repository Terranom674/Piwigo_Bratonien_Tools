(function ($) {
  'use strict';

  var active = false;
  var selected = {};

  function getThumbs() {
    return $('#thumbnails .gdthumb');
  }

  function imageIdFromThumb($thumb) {
    var $link = $thumb.find('a[href]').first();
    var href = $link.attr('href') || '';
    var matches = href.match(/(?:image|picture)\/(\d+)|[?&](?:image_id|id)=(\d+)/i);
    if (matches) {
      return parseInt(matches[1] || matches[2], 10);
    }

    var id = $thumb.data('image-id') || $thumb.attr('data-image-id');
    return id ? parseInt(id, 10) : 0;
  }

  function updateBar() {
    var ids = Object.keys(selected);
    $('#bratonien-selection-count').text(ids.length);
    $('#bratonien-selection-download').prop('disabled', ids.length === 0);
  }

  function setActive(value) {
    active = value;
    $('body').toggleClass('bratonien-selection-active', active);
    $('#bratonien-selection-bar').prop('hidden', !active);
    $('#bratonien-selection-toggle').toggleClass('active', active);
    if (!active) {
      clearSelection();
    }
  }

  function clearSelection() {
    selected = {};
    getThumbs().removeClass('bratonien-selected');
    updateBar();
  }

  $(document).on('click', '#bratonien-selection-toggle', function (e) {
    e.preventDefault();
    setActive(!active);
  });

  $(document).on('click', '#thumbnails .gdthumb a', function (e) {
    if (!active) {
      return;
    }

    e.preventDefault();
    e.stopPropagation();

    var $thumb = $(this).closest('.gdthumb');
    var imageId = imageIdFromThumb($thumb);
    if (!imageId) {
      return;
    }

    if (selected[imageId]) {
      delete selected[imageId];
      $thumb.removeClass('bratonien-selected');
    } else {
      selected[imageId] = true;
      $thumb.addClass('bratonien-selected');
    }
    updateBar();
  });

  $(document).on('click', '#bratonien-selection-clear', function () {
    clearSelection();
  });

  $(document).on('click', '#bratonien-selection-download', function () {
    var ids = Object.keys(selected);
    if (!ids.length || !window.BratonienSelectionConfig) {
      return;
    }

    var separator = window.BratonienSelectionConfig.downloadUrl.indexOf('?') === -1 ? '?' : '&';
    window.location.href = window.BratonienSelectionConfig.downloadUrl + separator + 'bratonien_selection=' + encodeURIComponent(ids.join(','));
  });
})(jQuery);
