document.addEventListener("alpine:init", () => {
  Alpine.store("shell", {
    sidebarOpen: false,
    toggleSidebar() {
      this.sidebarOpen = !this.sidebarOpen;
      document.body.classList.toggle("sidebar-open", this.sidebarOpen);
    },
    closeSidebar() {
      this.sidebarOpen = false;
      document.body.classList.remove("sidebar-open");
    },
  });
});

document.addEventListener("DOMContentLoaded", () => {
  // Global clock in WIB (Asia/Jakarta). UI-only, no backend coupling.
  (function initTopbarClockWIB() {
    const root = document.getElementById("topbarClock");
    if (!root) return;

    const elDate = root.querySelector("[data-clock-date]");
    const elTime = root.querySelector("[data-clock-time]");
    if (!elDate || !elTime) return;

    const tz = "Asia/Jakarta";

    const fmtDateLong = new Intl.DateTimeFormat("id-ID", {
      weekday: "long",
      day: "2-digit",
      month: "long",
      year: "numeric",
      timeZone: tz,
    });

    const fmtDateShort = new Intl.DateTimeFormat("id-ID", {
      weekday: "short",
      day: "2-digit",
      month: "short",
      year: "numeric",
      timeZone: tz,
    });

    const mq = window.matchMedia ? window.matchMedia("(min-width: 768px)") : null;

    function formatTimeDot(date) {
      // Always render HH.mm.ss
      const parts = new Intl.DateTimeFormat("id-ID", {
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
        hour12: false,
        timeZone: tz,
      }).formatToParts(date);

      const hh = parts.find((p) => p.type === "hour")?.value ?? "00";
      const mm = parts.find((p) => p.type === "minute")?.value ?? "00";
      const ss = parts.find((p) => p.type === "second")?.value ?? "00";
      return `${hh}.${mm}.${ss}`;
    }

    function tick() {
      const now = new Date();
      const useLong = mq ? mq.matches : true;
      elDate.textContent = (useLong ? fmtDateLong : fmtDateShort).format(now);
      elTime.textContent = `${formatTimeDot(now)} WIB`;
    }

    tick();
    setInterval(tick, 1000);
  })();

  if (window.jQuery && window.JSZip) {
    window.jQuery.fn.dataTable.Buttons.jszip(window.JSZip);
  }

  if (window.jQuery && window.jQuery.fn && window.jQuery.fn.dataTable) {
    window.jQuery.extend(true, window.jQuery.fn.dataTable.defaults, {
      language: {
        search: "Cari:",
        emptyTable: "Belum ada data",
        zeroRecords: "Data tidak ditemukan",
        info: "Menampilkan _START_ sampai _END_ dari _TOTAL_ data",
        infoEmpty: "Menampilkan 0 sampai 0 dari 0 data",
        infoFiltered: "(difilter dari _MAX_ data)",
        lengthMenu: "Tampilkan _MENU_ data",
        paginate: {
          first: "Awal",
          last: "Akhir",
          next: "Berikut",
          previous: "Sebelum",
        },
      },
    });
  }
});
