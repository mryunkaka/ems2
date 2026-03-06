// Global PhotoSwipe integration (no CDN).
// Opens any image attachment link across dashboard:
// - <a class="btn-preview-doc" data-src="/path/to/file.png">
// - <a data-src="/path/to/file.jpg">
// - <img class="identity-photo" src="...">
//
// The handler runs in capture phase and stops other page-specific preview handlers,
// so legacy modals/lightboxes won't interfere.
(function () {
  if (!window.PhotoSwipeLightbox || !window.PhotoSwipe) return;

  var lightbox = new window.PhotoSwipeLightbox({
    pswpModule: window.PhotoSwipe,
    bgOpacity: 0.85,
    showHideAnimationType: "fade",
    // Behavior:
    // - Left click: zoom in/out (toggle)
    // - Drag: pan when zoomed
    // - Pinch: zoom on touch devices
    // - Close only via X or ESC (no background click close)
    closeOnVerticalDrag: false,
    closeOnScroll: false,
    clickToCloseNonZoomable: false,
    imageClickAction: "zoom",
    bgClickAction: false,
    tapAction: "toggle-controls",
    doubleTapAction: "zoom",
    // Ensure click-to-zoom always has a higher zoom target, even for small images.
    secondaryZoomLevel: function (zl) {
      var base = Math.max(zl.initial || 1, zl.fit || 1);
      return Math.max(base * 2, 1.6);
    },
    maxZoomLevel: function (zl) {
      var base = Math.max(zl.initial || 1, zl.fit || 1);
      return Math.max(base * 4, 3);
    },
  });
  lightbox.init();

  // Enforce options on the actual PhotoSwipe instance too (guards against partial option application).
  lightbox.on("open", function () {
    var pswp = lightbox.pswp;
    if (!pswp) return;

    pswp.options.clickToCloseNonZoomable = false;
    pswp.options.imageClickAction = "zoom";
    pswp.options.bgClickAction = false;
    pswp.options.closeOnVerticalDrag = false;
    pswp.options.closeOnScroll = false;
  });

  // Prevent context menu from closing or interfering while PhotoSwipe is open.
  // (Some browsers treat right-click as a tap/click sequence in overlays.)
  document.addEventListener(
    "contextmenu",
    function (e) {
      var pswpRoot = document.querySelector(".pswp");
      if (!pswpRoot || pswpRoot.style.display === "none") return;
      if (e.target && (e.target.closest(".pswp") || e.target.classList?.contains("pswp"))) {
        e.preventDefault();
      }
    },
    true
  );

  function isImageUrl(url) {
    if (!url) return false;
    // Support local previews (e.g., <input type="file"> object URLs) and inline images.
    if (/^(blob:|data:image\/)/i.test(url)) return true;
    return /\.(png|jpe?g|webp|gif)(\?.*)?$/i.test(url);
  }

  function getImageSize(src) {
    return new Promise(function (resolve) {
      var img = new Image();
      img.onload = function () {
        resolve({
          width: img.naturalWidth || 1600,
          height: img.naturalHeight || 1200,
        });
      };
      img.onerror = function () {
        resolve({ width: 1600, height: 1200 });
      };
      img.src = src;
    });
  }

  function stopEvent(e) {
    try {
      e.preventDefault();
    } catch (_) {}
    try {
      e.stopPropagation();
    } catch (_) {}
    if (typeof e.stopImmediatePropagation === "function") {
      e.stopImmediatePropagation();
    }
  }

  async function openPhotoSwipe(src, title, initialPoint) {
    var size = await getImageSize(src);
    // PhotoSwipe v5 accepts dataSource as an array of items.
    lightbox.loadAndOpen(
      0,
      [
        {
          src: src,
          width: size.width,
          height: size.height,
          alt: title || "Gambar",
        },
      ],
      initialPoint || null
    );
  }

  document.addEventListener(
    "click",
    function (e) {
      // Links with dataset src
      var link =
        e.target.closest(".btn-preview-doc") ||
        e.target.closest("a[data-src]") ||
        e.target.closest("a[data-pswp-src]");

      // Images used as thumbnails (identity-photo)
      var imgEl = e.target.closest("img.identity-photo");

      var src = "";
      var title = "";

      if (imgEl && imgEl.getAttribute("src")) {
        src = imgEl.getAttribute("src");
        title = imgEl.getAttribute("alt") || "Gambar";
      } else if (link) {
        src = (link.dataset && (link.dataset.pswpSrc || link.dataset.src)) || "";
        if (!src && link.getAttribute("href")) {
          src = link.getAttribute("href");
        }
        title = (link.dataset && link.dataset.title) || link.getAttribute("title") || "Gambar";
      } else {
        return;
      }

      if (!isImageUrl(src)) return;

      stopEvent(e);
      openPhotoSwipe(src, title, { x: e.clientX, y: e.clientY });
    },
    true
  );
})();
