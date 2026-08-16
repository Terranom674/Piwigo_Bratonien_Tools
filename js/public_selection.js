(function ($) {
  'use strict';

  var active = false;
  var selected = {};
  var downloading = false;

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
      $item.toggleClass('bratonien-selected', !!selected[imageId]);
    });
  }

  function showError(message) {
    var $error = $('#bratonien-selection-error');
    if (!message) {
      $error.prop('hidden', true).text('');
      return;
    }
    $error.text(message).prop('hidden', false);
  }

  function updateBar() {
    var ids = Object.keys(selected);
    $('#bratonien-selection-count').text(ids.length);
    $('#bratonien-selection-download').prop('disabled', ids.length === 0 || downloading);
  }

  function clearSelection() {
    selected = {};
    $('.bratonien-selectable').removeClass('bratonien-selected');
    showError('');
    updateBar();
  }

  function setActive(value) {
    active = value;
    $('body').toggleClass('bratonien-selection-active', active);
    $('#bratonien-selection-bar').prop('hidden', !active);
    $('#bratonien-selection-toggle').toggleClass('active', active).attr('aria-pressed', active ? 'true' : 'false');
    refreshSelectableItems();
    if (!active) {
      clearSelection();
    }
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
    showError('');
    updateBar();
  });

  $(document).on('click', '#bratonien-selection-all', function () {
    selectableLinks().each(function () {
      var $link = $(this);
      var imageId = imageIdFromLink($link);
      if (imageId) {
        selected[imageId] = true;
        itemFromLink($link).addClass('bratonien-selected');
      }
    });
    showError('');
    updateBar();
  });

  $(document).on('click', '#bratonien-selection-clear', function () {
    clearSelection();
  });

  $(document).on('click', '#bratonien-selection-download', function () {
    var ids = Object.keys(selected);
    var config = window.BratonienSelectionConfig || {};
    if (!ids.length || !config.downloadUrl || downloading) {
      return;
    }

    downloading = true;
    showError('');
    $('#bratonien-selection-download').text('Wird vorbereitet …');
    updateBar();

    $.ajax({
      url: config.downloadUrl,
      method: 'POST',
      dataType: 'json',
      data: {
        bratonien_selection_download: 1,
        image_ids: ids.join(','),
        pwg_token: config.token || ''
      }
    }).done(function (response) {
      if (!response || !response.ok || !response.download_url) {
        showError(response && response.error ? response.error : 'Der Download konnte nicht vorbereitet werden.');
        return;
      }
      window.location.href = response.download_url;
    }).fail(function (xhr) {
      var message = 'Der Download konnte nicht vorbereitet werden.';
      if (xhr.responseJSON && xhr.responseJSON.error) {
        message = xhr.responseJSON.error;
      }
      showError(message);
    }).always(function () {
      downloading = false;
      $('#bratonien-selection-download').text('Herunterladen');
      updateBar();
    });
  });

  // The native Batch Downloader button downloads the whole current set and
  // performs a page round-trip first. When Bratonien selection is available,
  // the selection mode replaces that control; "Alle auswählen" covers the
  // complete visible album without the confusing duplicate button.
  $(function () {
    $('#batchDownloadLink, #batchDownloadRequest').closest('a').hide();
    $('#bratonien-selection-toggle').attr('aria-pressed', 'false');
  });

  $(document).ajaxComplete(function () {
    if (active) {
      refreshSelectableItems();
    }
  });
})(jQuery);
