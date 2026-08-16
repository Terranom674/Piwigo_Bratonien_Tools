(function ($) {
  'use strict';

  var SIDE_RATIO = 0.18;
  var PREVIEW_RATIO = 0.18;
  var resizeTimer = null;
  var overlayContainer = null;
  var overlays = {};

  var zoneIcons = {
    previous: 'fas fa-chevron-left',
    thumbnails: 'fas fa-chevron-up',
    photoswipe: 'fas fa-expand-arrows-alt',
    next: 'fas fa-chevron-right'
  };

  function absoluteUrl(url) {
    if (!url) {
      return '';
    }

    var a = document.createElement('a');
    a.href = url;
    return a.href;
  }

  function findNavigationHref(selector) {
    var element = document.querySelector(selector);
    return element ? absoluteUrl(element.getAttribute('href')) : '';
  }

  function findUpHref() {
    var icon = document.querySelector('#navigationButtons a i.fa-chevron-up');
    if (!icon || !icon.parentElement) {
      return '';
    }

    return absoluteUrl(icon.parentElement.getAttribute('href'));
  }

  function findAreaByHref(map, href) {
    if (!map || !href) {
      return null;
    }

    var areas = map.querySelectorAll('area[href]');
    for (var i = 0; i < areas.length; i += 1) {
      if (absoluteUrl(areas[i].getAttribute('href')) === href) {
        return areas[i];
      }
    }

    return null;
  }

  function ensurePhotoSwipeArea(map) {
    var area = map.querySelector('area[data-bratonien-zone="photoswipe"]');
    if (area) {
      return area;
    }

    area = document.createElement('area');
    area.setAttribute('shape', 'rect');
    area.setAttribute('href', '#');
    area.setAttribute('title', 'Vollbild');
    area.setAttribute('alt', 'Vollbild');
    area.setAttribute('data-bratonien-zone', 'photoswipe');

    area.addEventListener('click', function (event) {
      event.preventDefault();
      var photoSwipeButton = document.getElementById('startPhotoSwipe');
      if (photoSwipeButton) {
        photoSwipeButton.click();
      }
    });

    map.appendChild(area);
    return area;
  }

  function ensureOverlayContainer(image) {
    if (overlayContainer && document.body.contains(overlayContainer)) {
      return overlayContainer;
    }

    var parent = image.parentElement;
    if (!parent) {
      return null;
    }

    if (window.getComputedStyle(parent).position === 'static') {
      parent.style.position = 'relative';
    }

    overlayContainer = document.createElement('div');
    overlayContainer.className = 'bratonien-picture-zones';
    overlayContainer.setAttribute('aria-hidden', 'true');
    parent.appendChild(overlayContainer);

    Object.keys(zoneIcons).forEach(function (role) {
      var zone = document.createElement('div');
      zone.className = 'bratonien-picture-zone bratonien-picture-zone-' + role;
      zone.setAttribute('data-bratonien-zone-overlay', role);

      var icon = document.createElement('i');
      icon.className = zoneIcons[role];
      zone.appendChild(icon);

      overlayContainer.appendChild(zone);
      overlays[role] = zone;
    });

    return overlayContainer;
  }

  function setOverlayRect(role, left, top, width, height) {
    var overlay = overlays[role];
    if (!overlay) {
      return;
    }

    overlay.style.left = left + 'px';
    overlay.style.top = top + 'px';
    overlay.style.width = width + 'px';
    overlay.style.height = height + 'px';
  }

  function hideAllOverlays() {
    Object.keys(overlays).forEach(function (role) {
      overlays[role].classList.remove('is-active');
    });
  }

  function bindAreaHover(area, role) {
    if (!area || area.getAttribute('data-bratonien-hover-bound') === '1') {
      return;
    }

    area.setAttribute('data-bratonien-hover-bound', '1');

    area.addEventListener('mouseenter', function () {
      hideAllOverlays();
      if (overlays[role]) {
        overlays[role].classList.add('is-active');
      }
    });

    area.addEventListener('mouseleave', function () {
      if (overlays[role]) {
        overlays[role].classList.remove('is-active');
      }
    });
  }

  function setZone(area, role, coords) {
    if (!area) {
      return;
    }

    area.setAttribute('shape', 'rect');
    area.setAttribute('coords', coords.join(','));
    area.setAttribute('data-bratonien-zone', role);
    bindAreaHover(area, role);
  }

  function updateMap() {
    var image = document.getElementById('theMainImage');
    if (!image) {
      return;
    }

    var useMap = image.getAttribute('usemap');
    if (!useMap || useMap.charAt(0) !== '#') {
      return;
    }

    var map = document.querySelector('map[name="' + useMap.substring(1) + '"]');
    if (!map) {
      return;
    }

    var rect = image.getBoundingClientRect();
    var width = Math.round(rect.width || image.clientWidth || 0);
    var height = Math.round(rect.height || image.clientHeight || 0);
    if (width < 1 || height < 1) {
      return;
    }

    ensureOverlayContainer(image);

    var leftEdge = Math.round(width * SIDE_RATIO);
    var rightEdge = Math.round(width * (1 - SIDE_RATIO));
    var previewBottom = Math.round(height * PREVIEW_RATIO);

    var previousArea = findAreaByHref(map, findNavigationHref('#navPrevPicture'));
    var nextArea = findAreaByHref(map, findNavigationHref('#navNextPicture'));
    var upArea = findAreaByHref(map, findUpHref());
    var photoSwipeArea = document.getElementById('startPhotoSwipe') ? ensurePhotoSwipeArea(map) : null;

    setZone(previousArea, 'previous', [0, 0, leftEdge, height]);
    setZone(nextArea, 'next', [rightEdge, 0, width, height]);
    setZone(upArea, 'thumbnails', [leftEdge, 0, rightEdge, previewBottom]);
    setZone(photoSwipeArea, 'photoswipe', [leftEdge, previewBottom, rightEdge, height]);

    var imageLeft = image.offsetLeft;
    var imageTop = image.offsetTop;

    setOverlayRect('previous', imageLeft, imageTop, leftEdge, height);
    setOverlayRect('next', imageLeft + rightEdge, imageTop, width - rightEdge, height);
    setOverlayRect('thumbnails', imageLeft + leftEdge, imageTop, rightEdge - leftEdge, previewBottom);
    setOverlayRect('photoswipe', imageLeft + leftEdge, imageTop + previewBottom, rightEdge - leftEdge, height - previewBottom);
  }

  function scheduleUpdate() {
    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(updateMap, 60);
  }

  $(function () {
    var image = document.getElementById('theMainImage');
    if (!image) {
      return;
    }

    if (image.complete) {
      updateMap();
    } else {
      image.addEventListener('load', updateMap);
    }

    window.addEventListener('resize', scheduleUpdate);

    if (window.ResizeObserver) {
      var observer = new ResizeObserver(scheduleUpdate);
      observer.observe(image);
    }

    if (window.MutationObserver) {
      var mutationObserver = new MutationObserver(scheduleUpdate);
      mutationObserver.observe(image, {
        attributes: true,
        attributeFilter: ['src', 'srcset', 'usemap', 'class', 'style']
      });
    }
  });
})(jQuery);
