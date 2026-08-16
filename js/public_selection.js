(function ($) {
  'use strict';

  var active = false;
  var selected = {};

  function candidateLinks() {
    return $('#thumbnails a[href], .thumbnails a[href]');
  }

  function imageIdFromHref(href) {
    if (!href) {
      return 0;
    }

    var patterns = [
      /(?:^|\/)picture(?:\.php)?\?\/(\d+)(?:[-/]|$)/i,
      /(?:^|\/)picture\/(\d+)(?:[-/]|$)/i,
      /(?:^|\/)image\/(\d+)(?:[-/]|$)/i,
      /[?&](?:image_id|image|picture_id|id)=(\d+)(?:&|$)/i
    ];

    for (var i = 0; i < patterns.length; i++) {
      var match = href.match(patterns[i]);
      if (match) {
        return parseInt(match[1], 10) || 0;
      }
    }

    return 0;
  }

  function imageIdFromLink($link) {
    var direct = $link.attr('data-image-id') || $link.data('image-id');
    if (direct) {
      return parseInt(direct, 10) || 0;
    }

    var $withId = $link.closest('[data-image-id]');
    if ($withId.length) {
      return parseInt($withId.attr('data-image-id'), 10) || 0;
    }

    return imageIdFromHref($link.attr('href') || '');
  }

  function itemFromLink($link) {
    // Prefer semantic list/card wrappers used by Piwigo themes and thumbnail
    // plugins. gdThumb is only one supported markup, not a dependency.
    var $item = $link.closest('li, .thumbnail, .thumbnailCategory, .gdthumb, .card');
    return $item.length ? $item.first() : $link;
  }

  function selectableLinks() {
    return candidateLinks().filter(function () {
      return imageIdFromLink($(this)) > 0;
    });
  }

  function refreshSelectableItems() {
    selectableLinks().each(function () {
      var $link = $(this);
      var imageId = imageIdFromLink($link);
      var $item = itemFromLink($link);
      $item.addClass('bratonien-selectable').attr('data-bratonien-image-id', imageId);
      if (selected[imageId]) {
        $item.addClass('bratonien-selected');
      }
    });
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
    refreshSelectableItems();
    if (!active) {
      clearSelection();
    }
  }

  function clearSelection() {
    selected = {};
    $('.bratonien-selectable').removeClass('bratonien-selected');
    updateBar();
  }

  $(document).on('click', '#bratonien-selection-toggle', function (e) {
    e.preventDefault();
    setActive(!active);
  });

  $(document).on('click', '#thumbnails a[href], .thumbnails a[href]', function (e) {
    if (!active) {
      return;
    }

    var $link = $(this);
    var imageId = imageIdFromLink($link);
    if (!imageId) {
      return;
    }

    e.preventDefault();
    e.stopPropagation();

    var $item = itemFromLink($link);
    if (selected[imageId]) {
      delete selected[imageId];
      $item.removeClass('bratonien-selected');
    } else {
      selected[imageId] = true;
      $item.addClass('bratonien-selected');
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

  // Some plugins append thumbnails after the initial page render. Refresh the
  // markers without depending on any particular thumbnail implementation.
  $(document).ajaxComplete(function () {
    if (active) {
      refreshSelectableItems();
    }
  });
})(jQuery);
