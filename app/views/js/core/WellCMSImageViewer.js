/**
 * WellCMS Image Viewer — Lightweight fullscreen image preview
 * Zero dependencies, dark mode ready, touch/desktop supported.
 *
 * Selector priority:
 *   1. img[data-lightbox]          — explicit opt-in, grouped by attribute value
 *   2. img[data-well-img="1"]      — uploaded via RTE editor
 *   3. .post-content img, .thread-content img — legacy content areas
 *
 * Usage: (auto-init, no manual calls needed)
 *   <img src="..." data-lightbox="gallery-1" alt="...">
 *
 * Copyright (C) www.wellcms.com
 */
(function () {
  'use strict';

  var viewer = null;       // overlay DOM element
  var images = [];         // current gallery image list
  var currentIndex = -1;   // currently displayed index
  var isOpen = false;
  var startX = 0, startY = 0, isSwiping = false;
  var scaleMode = false;   // true = full-size, false = fit-screen

  // ── Selector: all images eligible for preview ──────────────────────────────
  var _selectors = [
    'img[data-lightbox]',
    'img[data-well-img="1"]',
    '.post-content img',
    '.thread-content img',
    '.sub-reply-content img',
    '.message-content img',
    '.well-forum-content img',
  ];
  var SELECTOR = _selectors.join(', ');

  // ── Exclude images inside the RTE editor ──────────────────────────────────
  var _excludes = [
    '.rte-content img',
    '.rte-toolbar-scroll img',
    '.avatar img',
    '.emoji img',
    '.rte-mention-avatar img',
    'img[data-no-viewer]',
  ];
  var EXCLUDE = _excludes.join(', ');

  // ── Hooks ─────────────────────────────────────────────────────────────────
  var _hooks = { onOpen: [], onClose: [], onNavigate: [] };

  function fireHook(name, args) {
    _hooks[name].forEach(function (cb) {
      try { cb.apply(null, args); } catch (e) { console.warn('ImageViewer hook error:', e); }
    });
  }

  // ── srcset / data-src resolution ──────────────────────────────────────────
  function resolveBestSrc(imgEl) {
    var src = imgEl.getAttribute('data-src');
    if (src) return src;
    var srcset = imgEl.getAttribute('srcset');
    if (srcset) {
      var best = { url: imgEl.src, density: 1 };
      srcset.split(',').forEach(function (s) {
        var parts = s.trim().split(/\s+/);
        if (parts.length < 1) return;
        var density = parts[1] ? parseFloat(parts[1]) || 1 : 1;
        if (density > best.density) best = { url: parts[0], density: density };
      });
      return best.url;
    }
    return imgEl.src;
  }

  // ── Build a minimal SVG close icon ─────────────────────────────────────────
  function closeSVG() {
    return '<svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>';
  }

  // ── Build the viewer overlay DOM (lazy, created once) ──────────────────────
  function buildViewer() {
    if (viewer) return viewer;

    viewer = document.createElement('div');
    viewer.id = 'well-img-viewer';
    viewer.style.cssText = [
      'position:fixed',
      'inset:0',
      'z-index:99999',
      'background:rgba(0,0,0,0.82)',
      'display:flex',
      'align-items:center',
      'justify-content:center',
      'opacity:0',
      'transition:opacity 0.2s ease',
      'user-select:none',
      '-webkit-user-select:none',
      'overflow:hidden',
      'cursor:pointer',
    ].join(';');
    viewer.setAttribute('role', 'dialog');
    viewer.setAttribute('aria-hidden', 'true');

    // Close button
    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', 'Close (Esc)');
    closeBtn.innerHTML = closeSVG();
    closeBtn.style.cssText = 'position:fixed;top:12px;right:12px;z-index:10;width:40px;height:40px;display:flex;align-items:center;justify-content:center;border-radius:9999px;background:rgba(0,0,0,0.35);color:rgba(255,255,255,0.9);border:0;cursor:pointer;transition:background 0.15s';
    closeBtn.addEventListener('mouseenter', function () { closeBtn.style.background = 'rgba(0,0,0,0.55)'; });
    closeBtn.addEventListener('mouseleave', function () { closeBtn.style.background = 'rgba(0,0,0,0.35)'; });
    closeBtn.addEventListener('click', function (e) { e.stopPropagation(); closeViewer(); });
    viewer.appendChild(closeBtn);

    // Prev / Next navigation buttons
    var navStyle = 'position:fixed;top:50%;z-index:10;transform:translateY(-50%);width:44px;height:44px;display:flex;align-items:center;justify-content:center;border-radius:9999px;background:rgba(0,0,0,0.25);color:rgba(255,255,255,0.8);font-size:24px;border:0;cursor:pointer;transition:background 0.15s,opacity 0.2s';

    var prevBtn = document.createElement('button');
    prevBtn.type = 'button';
    prevBtn.setAttribute('aria-label', 'Previous');
    prevBtn.textContent = '‹';
    prevBtn.style.cssText = navStyle + ';left:8px';
    prevBtn.addEventListener('click', function (e) { e.stopPropagation(); navigate(-1); });
    viewer.appendChild(prevBtn);

    var nextBtn = document.createElement('button');
    nextBtn.type = 'button';
    nextBtn.setAttribute('aria-label', 'Next');
    nextBtn.textContent = '›';
    nextBtn.style.cssText = navStyle + ';right:8px';
    nextBtn.addEventListener('click', function (e) { e.stopPropagation(); navigate(1); });
    viewer.appendChild(nextBtn);

    // Image element
    var img = document.createElement('img');
    img.style.cssText = 'max-width:95vw;max-height:95vh;object-fit:contain;border-radius:8px;box-shadow:0 8px 40px rgba(0,0,0,0.5);cursor:zoom-in;transition:transform 0.2s ease;pointer-events:auto;';
    img.alt = '';
    img.draggable = false;
    viewer.appendChild(img);

    // Counter
    var counter = document.createElement('div');
    counter.style.cssText = 'position:fixed;bottom:16px;left:50%;transform:translateX(-50%);z-index:10;color:rgba(255,255,255,0.7);font-size:13px;font-family:sans-serif;pointer-events:none;';
    viewer.appendChild(counter);

    // Loading indicator
    var loader = document.createElement('div');
    loader.style.cssText = 'position:fixed;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;z-index:0;opacity:0;transition:opacity 0.15s';
    loader.innerHTML = '<div style="width:28px;height:28px;border:3px solid rgba(255,255,255,0.15);border-top-color:rgba(255,255,255,0.9);border-radius:50%;animation:well-img-spin 0.6s linear infinite"></div>';
    viewer.appendChild(loader);

    // Inject spinner keyframe once
    if (!document.getElementById('well-img-spin-style')) {
      var keyStyle = document.createElement('style');
      keyStyle.id = 'well-img-spin-style';
      keyStyle.textContent = '@keyframes well-img-spin{to{transform:rotate(360deg)}}';
      document.head.appendChild(keyStyle);
    }

    return viewer;
  }

  // ── Get the preview image element inside the viewer ───────────────────────
  function viewerImg() {
    return viewer ? viewer.querySelector('img') : null;
  }
  function viewerCounter() {
    return viewer ? viewer.querySelector('div:last-of-type') : null;
  }
  function viewerPrev() {
    return viewer ? viewer.querySelector('button[aria-label="Previous"]') : null;
  }
  function viewerNext() {
    return viewer ? viewer.querySelector('button[aria-label="Next"]') : null;
  }

  // ── Collect gallery images from the page ───────────────────────────────────
  function collectGallery(imgEl) {
    var group = imgEl.getAttribute('data-lightbox');
    var all = [];

    if (group) {
      // Explicit group: all images with same data-lightbox value
      var candidates = document.querySelectorAll('img[data-lightbox="' + CSS.escape(group) + '"]');
      candidates.forEach(function (el) {
        if (!el.closest(EXCLUDE)) all.push(el);
      });
    } else {
      // Implicit: all preview-eligible images on the page
      document.querySelectorAll(SELECTOR).forEach(function (el) {
        if (!el.closest(EXCLUDE)) all.push(el);
      });
    }

    // Deduplicate and preserve DOM order
    var seen = new Set();
    var result = [];
    all.forEach(function (el) {
      if (!seen.has(el)) { seen.add(el); result.push(el); }
    });
    return result;
  }

  // ── Open the viewer ────────────────────────────────────────────────────────
  function openViewer(imgEl) {
    if (!imgEl || !imgEl.src) return;

    images = collectGallery(imgEl);
    currentIndex = images.indexOf(imgEl);
    if (currentIndex === -1) {
      images = [imgEl];
      currentIndex = 0;
    }

    var el = buildViewer();
    document.body.appendChild(el);
    document.body.style.overflow = 'hidden';

    // Bind keyboard
    document.addEventListener('keydown', onKeyDown);
    document.addEventListener('touchstart', onTouchStart, { passive: true });
    document.addEventListener('touchmove', onTouchMove, { passive: true });
    document.addEventListener('touchend', onTouchEnd, { passive: true });

    scaleMode = false;
    showImage(currentIndex);

    // Fade in
    requestAnimationFrame(function () {
      el.style.opacity = '1';
      el.setAttribute('aria-hidden', 'false');
    });
    isOpen = true;

    fireHook('onOpen', [images, currentIndex]);
  }

  // ── Close the viewer ───────────────────────────────────────────────────────
  function closeViewer() {
    if (!viewer || !isOpen) return;

    document.removeEventListener('keydown', onKeyDown);
    document.removeEventListener('touchstart', onTouchStart);
    document.removeEventListener('touchmove', onTouchMove);
    document.removeEventListener('touchend', onTouchEnd);
    document.body.style.overflow = '';
    document.body.style.position = '';

    viewer.style.opacity = '0';
    viewer.setAttribute('aria-hidden', 'true');
    isOpen = false;

    // Remove after transition
    setTimeout(function () {
      if (viewer && viewer.parentNode) {
        viewer.parentNode.removeChild(viewer);
      }
    }, 220);

    fireHook('onClose', []);
  }

  // ── Display image at given index ──────────────────────────────────────────
  function showImage(index) {
    if (!viewer || index < 0 || index >= images.length) return;

    var imgEl = images[index];
    var src = resolveBestSrc(imgEl);

    // Security: only allow safe URL schemes
    if (src && !/^(https?:\/\/|\/|data:image\/)/.test(src)) {
      src = '';
    }

    var img = viewerImg();
    var counter = viewerCounter();

    img.style.transform = 'scale(1)';
    img.style.cursor = 'zoom-in';
    scaleMode = false;

    // Show loading indicator
    var loader = viewer.querySelector('div:last-of-type');
    if (loader) loader.style.opacity = '1';

    if (!src) {
      img.removeAttribute('src');
      img.alt = '[Invalid image]';
      if (counter) counter.textContent = '';
      if (loader) loader.style.opacity = '0';
      return;
    }

    img.alt = imgEl.alt || '';
    img.src = src;

    // Hide loading indicator on image load / error
    img.onload = function () {
      if (loader) loader.style.opacity = '0';
    };
    img.onerror = function () {
      if (loader) loader.style.opacity = '0';
      img.alt = '[加载失败]';
    };

    // Counter
    if (counter) {
      counter.textContent = images.length > 1
        ? (index + 1) + ' / ' + images.length
        : window.wellcms_lang?.image_viewer_hint || '点击图片缩放 · 按 Esc 关闭';
    }

    // Toggle zoom on image click
    img.onclick = function (e) {
      e.stopPropagation();
      scaleMode = !scaleMode;
      if (scaleMode) {
        img.style.maxWidth = 'none';
        img.style.maxHeight = 'none';
        img.style.width = 'auto';
        img.style.height = 'auto';
        img.style.cursor = 'zoom-out';
      } else {
        img.style.maxWidth = '95vw';
        img.style.maxHeight = '95vh';
        img.style.width = '';
        img.style.height = '';
        img.style.cursor = 'zoom-in';
      }
    };

    // Show/hide navigation buttons
    var prev = viewerPrev(), next = viewerNext();
    if (prev) prev.style.display = images.length > 1 ? '' : 'none';
    if (next) next.style.display = images.length > 1 ? '' : 'none';

    fireHook('onNavigate', [index, images.length]);
  }

  // ── Navigate gallery ───────────────────────────────────────────────────────
  function navigate(dir) {
    if (images.length < 2) return;
    currentIndex = (currentIndex + dir + images.length) % images.length;
    showImage(currentIndex);
  }

  // ── Keyboard handler ──────────────────────────────────────────────────────
  function onKeyDown(e) {
    if (!isOpen) return;
    switch (e.key) {
      case 'Escape': closeViewer(); break;
      case 'ArrowLeft': navigate(-1); e.preventDefault(); break;
      case 'ArrowRight': navigate(1); e.preventDefault(); break;
    }
  }

  // ── Touch swipe ───────────────────────────────────────────────────────────
  function onTouchStart(e) {
    if (!isOpen) return;
    var t = e.changedTouches[0];
    startX = t.clientX;
    startY = t.clientY;
    isSwiping = false;
  }

  function onTouchMove(e) {
    if (!isOpen || images.length < 2) return;
    var t = e.changedTouches[0];
    var dx = t.clientX - startX;
    var dy = t.clientY - startY;
    if (Math.abs(dx) > 10 && Math.abs(dx) > Math.abs(dy) * 1.5) {
      isSwiping = true;
      var img = viewerImg();
      if (img) img.style.transform = 'translateX(' + dx * 0.4 + 'px) scale(0.96)';
    }
  }

  function onTouchEnd(e) {
    if (!isOpen || images.length < 2) return;
    if (!isSwiping) return;
    var t = e.changedTouches[0];
    var dx = t.clientX - startX;
    var img = viewerImg();
    if (img) img.style.transform = '';
    if (Math.abs(dx) > 60) {
      navigate(dx > 0 ? -1 : 1);
    }
    isSwiping = false;
  }

  // ── Click on background closes ────────────────────────────────────────────
  function onViewerClick(e) {
    if (e.target === viewer || e.target === viewerImg()) {
      // Click on the image itself does NOT close (image click toggles zoom)
      if (e.target === viewer) closeViewer();
    }
  }

  // ── Event delegation: watch for image clicks on body ──────────────────────
  function onBodyClick(e) {
    // Ignore if something else already handled this click
    if (e.defaultPrevented) return;

    var target = e.target.closest(SELECTOR);
    if (!target) return;

    // Exclude editor/avatar/emoji areas
    if (target.closest(EXCLUDE)) return;

    // Check if it's inside an interactive element (link, button)
    if (target.closest('a[href], button, [data-modal-url], [data-href]')) return;

    e.preventDefault();
    openViewer(target);
  }

  // ── Init: one-time event binding ──────────────────────────────────────────
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      document.body.addEventListener('click', onBodyClick, true);
    });
  } else {
    document.body.addEventListener('click', onBodyClick, true);
  }

  // Click on the overlay background (not the image) closes
  document.addEventListener('click', function (e) {
    if (!isOpen || !viewer) return;
    if (e.target === viewer) closeViewer();
  });

  // ── Expose public API ─────────────────────────────────────────────────────
  window.WellCMSImageviewer = {
    config: {
      get SELECTOR() { return SELECTOR; },
      get EXCLUDE() { return EXCLUDE; },
    },
    addSelector: function (s) { if (_selectors.indexOf(s) === -1) { _selectors.push(s); SELECTOR = _selectors.join(', '); } },
    addExclude: function (s) { if (_excludes.indexOf(s) === -1) { _excludes.push(s); EXCLUDE = _excludes.join(', '); } },
    onOpen: function (cb) { _hooks.onOpen.push(cb); },
    onClose: function (cb) { _hooks.onClose.push(cb); },
    onNavigate: function (cb) { _hooks.onNavigate.push(cb); },
    open: openViewer,
    close: closeViewer,
    get isOpen() { return isOpen; },
  };

})();
