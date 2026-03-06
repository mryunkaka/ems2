document.addEventListener("DOMContentLoaded", () => {
  /* =========================
     SIDEBAR (HANYA JIKA ADA)
     ========================= */
  const btn = document.getElementById("menuToggle");
  const sidebar = document.getElementById("sidebar");
  const overlay = document.getElementById("sidebarOverlay");

  if (btn && sidebar && overlay) {
    // Toggle sidebar
    btn.addEventListener("click", (e) => {
      e.stopPropagation(); // ⬅️ PENTING
      sidebar.classList.toggle("open");
      overlay.classList.toggle("active");
      document.body.classList.toggle("sidebar-open");
    });

    // Klik overlay → tutup
    overlay.addEventListener("click", () => {
      closeSidebar();
    });

    // Klik DI LUAR sidebar → tutup
    document.addEventListener("click", (e) => {
      if (
        sidebar.classList.contains("open") &&
        !sidebar.contains(e.target) &&
        !btn.contains(e.target)
      ) {
        closeSidebar();
      }
    });
  }

  function closeSidebar() {
    sidebar.classList.remove("open");
    overlay.classList.remove("active");
    document.body.classList.remove("sidebar-open");
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
