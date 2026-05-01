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

  /* =========================
     SETTING AKUN QUICK SAVE
     ========================= */
  (function initSettingAkunQuickSave() {
    if (window.__settingAkunQuickSaveBound) return;
    if (!/\/dashboard\/setting_akun\.php(?:$|\?)/.test(window.location.pathname + window.location.search)) {
      return;
    }

    const form = document.querySelector('form[action="setting_akun_action.php"]');
    const submitButton = document.querySelector(".btn-submit");
    if (!form || !submitButton) return;

    window.__settingAkunQuickSaveBound = true;

    const hasAnySelectedFiles = () =>
      Array.from(form.querySelectorAll('input[type="file"]')).some(
        (input) => input.files && input.files.length > 0
      );

    const withIgnoredFileRequired = (checker) => {
      const toggled = [];

      if (!hasAnySelectedFiles()) {
        form.querySelectorAll('input[type="file"][required]').forEach((input) => {
          toggled.push(input);
          input.required = false;
        });
      }

      const result = checker();

      toggled.forEach((input) => {
        input.required = true;
      });

      return result;
    };

    const validateQuickSaveFields = () => {
      const requiredFields = [
        {
          selector: 'input[name="full_name"]',
          message: "Nama Medis wajib diisi.",
        },
        {
          selector: 'input[name="citizen_id"]',
          message: "Citizen ID wajib diisi.",
        },
        {
          selector: 'input[name="tanggal_masuk"]',
          message: "Tanggal Masuk wajib diisi.",
        },
        {
          selector: 'select[name="jenis_kelamin"]',
          message: "Jenis Kelamin wajib dipilih.",
        },
        {
          selector: 'input[name="no_hp_ic"]',
          message: "No HP IC wajib diisi.",
        },
      ];

      for (const fieldRule of requiredFields) {
        const field = form.querySelector(fieldRule.selector);
        if (!field) {
          continue;
        }

        if (!String(field.value || "").trim()) {
          try {
            field.focus({ preventScroll: false });
          } catch (e) {}

          showInlineAlert("error", fieldRule.message);
          return false;
        }
      }

      return true;
    };

    const showInlineAlert = (type, message, details = "") => {
      const pageShell = document.querySelector(".page.page-shell-sm");
      if (!pageShell) return;

      pageShell
        .querySelectorAll("[data-setting-akun-runtime-alert]")
        .forEach((node) => node.remove());

      const alert = document.createElement("div");
      alert.className = type === "error" ? "alert alert-error" : "alert alert-info";
      alert.setAttribute("data-setting-akun-runtime-alert", "1");
      alert.textContent = message;

      if (details) {
        const meta = document.createElement("div");
        meta.style.marginTop = "8px";
        meta.style.fontSize = "13px";
        meta.style.lineHeight = "1.6";
        meta.textContent = details;
        alert.appendChild(meta);
      }

      const firstCard = pageShell.querySelector(".card");
      pageShell.insertBefore(alert, firstCard || pageShell.firstChild);
      window.scrollTo({ top: 0, behavior: "smooth" });
    };

    const startQuickSaveState = () => {
      submitButton.disabled = true;
      submitButton.setAttribute("aria-disabled", "true");
      submitButton.dataset.originalText =
        submitButton.dataset.originalText || submitButton.textContent.trim();
      submitButton.textContent = "Menyimpan...";
    };

    const stopQuickSaveState = () => {
      submitButton.disabled = false;
      submitButton.removeAttribute("aria-disabled");
      if (submitButton.dataset.originalText) {
        submitButton.textContent = submitButton.dataset.originalText;
      }
    };

    const buildQuickSavePayload = () => {
      const params = new URLSearchParams();

      Array.from(form.elements).forEach((field) => {
        if (!field || !field.name || field.disabled) {
          return;
        }

        if (field.type === "file") {
          return;
        }

        if ((field.type === "checkbox" || field.type === "radio") && !field.checked) {
          return;
        }

        if (field.tagName === "SELECT" && field.multiple) {
          Array.from(field.selectedOptions).forEach((option) => {
            params.append(field.name, option.value);
          });
          return;
        }

        params.append(field.name, field.value ?? "");
      });

      return params;
    };

    form.addEventListener(
      "submit",
      async (event) => {
        if (hasAnySelectedFiles()) {
          return;
        }

        if (!validateQuickSaveFields()) {
          event.preventDefault();
          event.stopImmediatePropagation();
          return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        startQuickSaveState();
        const startedAt = Date.now();

        try {
          const payload = buildQuickSavePayload();
          const response = await fetch("setting_akun_quick_save.php", {
            method: "POST",
            body: payload.toString(),
            credentials: "same-origin",
            headers: {
              "X-Requested-With": "XMLHttpRequest",
              Accept: "application/json",
              "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
            },
          });

          const payloadJson = await response.json();
          stopQuickSaveState();

          if (!response.ok || !payloadJson.ok) {
            showInlineAlert("error", payloadJson.message || "Gagal menyimpan perubahan akun.");
            return;
          }

          Array.from(form.elements).forEach((field) => {
            if (!field || !field.name) return;
            if (field.type === "checkbox" || field.type === "radio") {
              field.defaultChecked = field.checked;
              return;
            }
            if (
              field.tagName === "SELECT" ||
              field.tagName === "TEXTAREA" ||
              field.tagName === "INPUT"
            ) {
              field.defaultValue = field.value;
            }
          });

          const elapsedMs = Date.now() - startedAt;
          let details =
            "Quick save selesai dalam " + (elapsedMs / 1000).toFixed(2) + " detik tanpa reload halaman.";

          if (payloadJson.perf && Array.isArray(payloadJson.perf.marks) && payloadJson.perf.marks.length > 0) {
            details +=
              " Server: " +
              payloadJson.perf.marks
                .map((mark) => mark.label + " " + (mark.delta_ms / 1000).toFixed(2) + " dtk")
                .join(" | ");
          }

          showInlineAlert("info", payloadJson.message || "Akun berhasil diperbarui.", details);
        } catch (error) {
          stopQuickSaveState();
          showInlineAlert(
            "error",
            "Gagal menyimpan perubahan akun.",
            error && error.message ? error.message : ""
          );
        }
      },
      true
    );
  })();

  /* =========================
     USER AUTOCOMPLETE
     ========================= */
  window.emsInitUserAutocomplete =
    window.emsInitUserAutocomplete ||
    function (root = document) {
      const wrappers = Array.from(
        root.querySelectorAll("[data-user-autocomplete]:not([data-autocomplete-ready])")
      );

      wrappers.forEach((wrapper) => {
        const input = wrapper.querySelector("[data-user-autocomplete-input]");
        const hidden = wrapper.querySelector("[data-user-autocomplete-hidden]");
        const list = wrapper.querySelector("[data-user-autocomplete-list]");
        const scope = (wrapper.getAttribute("data-autocomplete-scope") || "all").trim();
        const minChars = Number(wrapper.getAttribute("data-autocomplete-min") || "2");
        const required = wrapper.hasAttribute("data-autocomplete-required");
        let timer = null;

        if (!input || !hidden || !list) return;
        wrapper.setAttribute("data-autocomplete-ready", "1");

        function clearList() {
          list.innerHTML = "";
          list.style.display = "none";
        }

        function setValidation() {
          if (required && input.value.trim() !== "" && !hidden.value) {
            input.setCustomValidity("Pilih nama dari daftar autocomplete.");
          } else {
            input.setCustomValidity("");
          }
        }

        function renderItems(items) {
          list.innerHTML = "";
          if (!Array.isArray(items) || !items.length) {
            clearList();
            return;
          }

          items.forEach((item) => {
            const option = document.createElement("div");
            option.className = "medic-suggestion-item";
            option.innerHTML =
              '<div><strong>' +
              String(item.full_name || "") +
              "</strong></div>" +
              '<small style="color:#64748b;">' +
              [item.position, item.division].filter(Boolean).join(" | ") +
              "</small>";

            option.addEventListener("click", () => {
              hidden.value = String(item.id || "");
              input.value = String(item.full_name || "");
              setValidation();
              clearList();
            });

            list.appendChild(option);
          });

          list.style.display = "block";
        }

        async function searchUsers(query) {
          if (!query || query.length < minChars) {
            clearList();
            return;
          }

          try {
            const url =
              window.emsUrl("/ajax/search_user_rh.php") +
              "?q=" +
              encodeURIComponent(query) +
              "&scope=" +
              encodeURIComponent(scope);
            const response = await fetch(url, {
              credentials: "same-origin",
              headers: { Accept: "application/json" },
            });
            if (!response.ok) {
              clearList();
              return;
            }

            const data = await response.json();
            renderItems(data);
          } catch (e) {
            clearList();
          }
        }

        input.addEventListener("focus", () => {
          if (list.children.length > 0) {
            list.style.display = "block";
          }
        });

        input.addEventListener("input", () => {
          hidden.value = "";
          setValidation();
          clearTimeout(timer);
          const query = input.value.trim();
          timer = setTimeout(() => searchUsers(query), 180);
        });

        input.addEventListener("blur", () => {
          setTimeout(() => {
            clearList();
            setValidation();
          }, 150);
        });

        const form = input.closest("form");
        if (form) {
          form.addEventListener("submit", (event) => {
            setValidation();
            if (!form.checkValidity()) {
              event.preventDefault();
              try {
                form.reportValidity();
              } catch (e) {}
            }
          });
        }
      });
    };

  window.emsInitUserAutocomplete(document);
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
