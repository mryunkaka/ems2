document.addEventListener("DOMContentLoaded", () => {
  /* =========================
     SIDEBAR (HANYA JIKA ADA)
     ========================= */
  const btn = document.getElementById("menuToggle");
  const sidebar = document.getElementById("sidebar");
  const overlay = document.getElementById("sidebarOverlay");

  if (btn && sidebar && overlay) {
    const STORAGE_KEY = "ems_sidebar_open";

    const isDesktop = () => {
      try {
        return window.matchMedia && window.matchMedia("(min-width: 768px)").matches;
      } catch (e) {
        return false;
      }
    };

    const isMobile = () => !isDesktop();

    const scrollMenuToActive = (behavior = "smooth") => {
      const menu = sidebar.querySelector(".sidebar-menu");
      const active = sidebar.querySelector(".sidebar-menu a.active");
      if (!menu || !active) return;

      const targetTop =
        active.offsetTop - menu.clientHeight / 2 + active.clientHeight / 2;

      try {
        menu.scrollTo({
          top: Math.max(0, targetTop),
          behavior,
        });
      } catch (e) {
        menu.scrollTop = Math.max(0, targetTop);
      }
    };

    const setSidebarOpen = (open, opts = {}) => {
      const { persist = true, scroll = true } = opts;

      sidebar.classList.toggle("open", open);
      document.body.classList.toggle("sidebar-open", open);
      overlay.classList.toggle("active", open && isMobile());

      if (persist) {
        try {
          sessionStorage.setItem(STORAGE_KEY, open ? "1" : "0");
        } catch (e) {}
      }

      if (open && scroll) {
        scrollMenuToActive();
      }
    };

    // Default: closed (termasuk di desktop). Jika sebelumnya user membuka sidebar pada tab ini,
    // restore state dari sessionStorage.
    let shouldOpen = false;
    try {
      shouldOpen = sessionStorage.getItem(STORAGE_KEY) === "1";
    } catch (e) {}

    setSidebarOpen(shouldOpen, { persist: false, scroll: false });
    if (shouldOpen) scrollMenuToActive("auto");

    // Toggle sidebar
    btn.addEventListener("click", (e) => {
      e.stopPropagation(); // penting: cegah klik dokumen menutup sidebar langsung
      setSidebarOpen(!sidebar.classList.contains("open"));
    });

    // Klik overlay → tutup
    overlay.addEventListener("click", () => {
      setSidebarOpen(false);
    });

    // Klik DI LUAR sidebar → tutup
    document.addEventListener("click", (e) => {
      if (
        sidebar.classList.contains("open") &&
        !sidebar.contains(e.target) &&
        !btn.contains(e.target)
      ) {
        setSidebarOpen(false);
      }
    });

    // Setelah klik menu navigasi: sidebar langsung menutup (semua ukuran layar),
    // dan halaman berikutnya akan load dalam kondisi hide.
    sidebar.addEventListener("click", (e) => {
      const link = e.target.closest("a");
      if (!link) return;

      // jangan ganggu shortcut/open new tab
      if (link.target === "_blank" || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

      const href = link.getAttribute("href") || "";
      if (!href || href.startsWith("#") || href.startsWith("javascript:")) return;

      // kalau link dicegat (confirm/cancel), jangan paksa tutup
      setTimeout(() => {
        if (e.defaultPrevented) return;
        setSidebarOpen(false);
      }, 0);
    });
  }

  /* =========================
     AUTO HIDE NOTIFICATION
     ========================= */
  const notifications = document.querySelectorAll(".notif");

  if (notifications.length) {
    setTimeout(() => {
      notifications.forEach((notif) => {
        notif.style.transition = "opacity 0.4s ease, transform 0.4s ease";
        notif.style.opacity = "0";
        notif.style.transform = "translateY(-6px)";

        setTimeout(() => {
          notif.remove();
        }, 400);
      });
    }, 5000);
  }

  /* =========================
     CLEAN URL (error/success)
     ========================= */
  if (
    window.location.search.includes("error") ||
    window.location.search.includes("success")
  ) {
    const cleanUrl = window.location.origin + window.location.pathname;
    window.history.replaceState({}, document.title, cleanUrl);
  }
});

/* =========================================
   HEARTBEAT — UPDATE LAST ACTIVITY
   ========================================= */
setInterval(() => {
  fetch("/actions/ping_farmasi_activity.php", {
    method: "POST",
    credentials: "same-origin",
  }).catch(() => {});
}, 30000); // tiap 30 detik
