document.addEventListener("DOMContentLoaded", () => {
  const GLOBAL_UPLOAD_LIMIT_BYTES = 1024 * 1024;
  const globalUploadNotice =
    "Maksimal ukuran upload adalah 1 MB per file. Gambar akan dicoba dikompres otomatis, sedangkan dokumen non-gambar di atas 1 MB akan ditolak.";
  const TOAST_CONTAINER_ID = "emsToastContainer";

  function ensureToastContainer() {
    let container = document.getElementById(TOAST_CONTAINER_ID);
    if (container) {
      return container;
    }

    container = document.createElement("div");
    container.id = TOAST_CONTAINER_ID;
    container.className = "ems-toast-container";
    container.setAttribute("aria-live", "polite");
    container.setAttribute("aria-atomic", "true");
    document.body.appendChild(container);
    return container;
  }

  function normalizeToastType(type) {
    const safeType = String(type || "info").toLowerCase();
    if (["success", "error", "warning", "info"].includes(safeType)) {
      return safeType;
    }
    return "info";
  }

  function toastTitle(type) {
    switch (normalizeToastType(type)) {
      case "success":
        return "Berhasil";
      case "error":
        return "Gagal";
      case "warning":
        return "Perhatian";
      default:
        return "Informasi";
    }
  }

  window.emsToast =
    window.emsToast ||
    function (message, type = "info", options = {}) {
      const text = String(message || "").trim();
      if (!text) {
        return null;
      }

      const safeType = normalizeToastType(type);
      const container = ensureToastContainer();
      const toast = document.createElement("div");
      const duration = Number(options.duration || 5200);
      const title = String(options.title || toastTitle(safeType));

      toast.className = "ems-toast ems-toast-" + safeType;
      toast.setAttribute("role", safeType === "error" ? "alert" : "status");
      toast.innerHTML =
        '<div class="ems-toast-head">' +
        '<div class="ems-toast-title">' +
        title +
        "</div>" +
        '<button type="button" class="ems-toast-close" aria-label="Tutup notifikasi">&times;</button>' +
        "</div>" +
        '<div class="ems-toast-body"></div>';

      const body = toast.querySelector(".ems-toast-body");
      const closeButton = toast.querySelector(".ems-toast-close");
      if (body) {
        body.textContent = text;
      }

      let removed = false;
      let timerId = null;

      const removeToast = () => {
        if (removed) {
          return;
        }
        removed = true;
        if (timerId) {
          window.clearTimeout(timerId);
        }
        toast.classList.remove("is-visible");
        toast.classList.add("is-leaving");
        window.setTimeout(() => {
          toast.remove();
        }, 220);
      };

      if (closeButton) {
        closeButton.addEventListener("click", removeToast);
      }

      container.appendChild(toast);
      window.requestAnimationFrame(() => {
        toast.classList.add("is-visible");
      });

      timerId = window.setTimeout(removeToast, Math.max(2200, duration));
      return toast;
    };

  if (!window.__emsNativeAlertWrapped) {
    const nativeAlert =
      typeof window.alert === "function" ? window.alert.bind(window) : null;
    window.emsNativeAlert = nativeAlert;
    window.alert = function (message) {
      if (typeof window.emsToast === "function") {
        window.emsToast(message, "warning");
        return;
      }
      if (nativeAlert) {
        nativeAlert(message);
      }
    };
    window.__emsNativeAlertWrapped = true;
  }

  document
    .querySelectorAll(
      ".alert, .notif, .alert-info, .alert-error, .alert-warning, .alert-success"
    )
    .forEach((node) => {
      const text = String(node.textContent || "").trim();
      if (!text) {
        node.remove();
        return;
      }

      let type = "info";
      if (
        node.classList.contains("alert-error") ||
        node.classList.contains("notif-danger") ||
        node.classList.contains("notif-error")
      ) {
        type = "error";
      } else if (
        node.classList.contains("alert-success") ||
        node.classList.contains("notif-success")
      ) {
        type = "success";
      } else if (
        node.classList.contains("alert-warning") ||
        node.classList.contains("notif-warning")
      ) {
        type = "warning";
      }

      window.emsToast(text, type);
      node.remove();
    });

  async function fileToImageBitmap(file) {
    if ("createImageBitmap" in window) {
      try {
        return await createImageBitmap(file);
      } catch (e) {}
    }

    return await new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onerror = () => reject(new Error("read_failed"));
      reader.onload = () => {
        const img = new Image();
        img.onerror = () => reject(new Error("image_failed"));
        img.onload = () => resolve(img);
        img.src = reader.result;
      };
      reader.readAsDataURL(file);
    });
  }

  async function compressImageForUpload(file) {
    const image = await fileToImageBitmap(file);
    const width = image.width || 0;
    const height = image.height || 0;
    if (!width || !height) {
      return file;
    }

    const maxEdge = 1600;
    const longest = Math.max(width, height, 1);
    const scale = longest > maxEdge ? maxEdge / longest : 1;
    const targetWidth = Math.max(1, Math.round(width * scale));
    const targetHeight = Math.max(1, Math.round(height * scale));

    const canvas = document.createElement("canvas");
    canvas.width = targetWidth;
    canvas.height = targetHeight;
    const ctx = canvas.getContext("2d", { alpha: false });
    if (!ctx) {
      return file;
    }

    ctx.fillStyle = "#ffffff";
    ctx.fillRect(0, 0, targetWidth, targetHeight);
    ctx.drawImage(image, 0, 0, targetWidth, targetHeight);

    const qualitySteps = [0.86, 0.8, 0.74, 0.68, 0.62, 0.56, 0.5];
    let blob = null;

    for (const quality of qualitySteps) {
      blob = await new Promise((resolve) =>
        canvas.toBlob(resolve, "image/jpeg", quality)
      );
      if (blob && blob.size <= GLOBAL_UPLOAD_LIMIT_BYTES) {
        break;
      }
    }

    if (!blob) {
      return file;
    }

    const baseName = String(file.name || "upload").replace(/\.[^.]+$/, "");
    return new File([blob], baseName + ".jpg", {
      type: "image/jpeg",
      lastModified: Date.now(),
    });
  }

  async function normalizeFileInput(input) {
    if (!(input instanceof HTMLInputElement) || input.type !== "file") {
      return;
    }

    const originalFiles = Array.from(input.files || []);
    if (!originalFiles.length) {
      return;
    }

    const nextFiles = [];
    let rejected = false;
    let compressed = false;

    for (const file of originalFiles) {
      const type = String(file.type || "").toLowerCase();
      const isImage = type.startsWith("image/");

      if (file.size <= GLOBAL_UPLOAD_LIMIT_BYTES) {
        nextFiles.push(file);
        continue;
      }

      if (!isImage) {
        rejected = true;
        continue;
      }

      try {
        const normalized = await compressImageForUpload(file);
        if (normalized.size > GLOBAL_UPLOAD_LIMIT_BYTES) {
          rejected = true;
          continue;
        }
        compressed = true;
        nextFiles.push(normalized);
      } catch (e) {
        rejected = true;
      }
    }

    const dt = new DataTransfer();
    nextFiles.forEach((file) => dt.items.add(file));
    input.files = dt.files;

    if (compressed) {
      input.dispatchEvent(new Event("change", { bubbles: true }));
    }

    if (rejected) {
      window.alert(globalUploadNotice);
    }
  }

  document.addEventListener(
    "change",
    (event) => {
      const target = event.target;
      if (!(target instanceof HTMLInputElement) || target.type !== "file") {
        return;
      }

      if (target.dataset.emsUploadNormalizing === "1") {
        target.dataset.emsUploadNormalizing = "0";
        return;
      }

      target.dataset.emsUploadNormalizing = "1";
      normalizeFileInput(target).finally(() => {
        target.dataset.emsUploadNormalizing = "0";
      });
    },
    true
  );

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
      const menu = sidebar.querySelector(".sidebar-menu-scroll");
      const active = sidebar.querySelector(".sidebar-menu a.active");
      if (!menu || !active) return;

      const menuRect = menu.getBoundingClientRect();
      const activeRect = active.getBoundingClientRect();
      const relativeTop = activeRect.top - menuRect.top + menu.scrollTop;
      const targetTop =
        relativeTop - menu.clientHeight / 2 + activeRect.height / 2;

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
    notifications.forEach((notif) => notif.remove());
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
      const parts = [message, details].filter(Boolean);
      window.emsToast(parts.join(" "), type === "error" ? "error" : "info", {
        title: type === "error" ? "Validasi Akun" : "Setting Akun",
        duration: type === "error" ? 6800 : 5600,
      });
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
   Hanya aktif di halaman farmasi yang relevan.
   ========================================= */
document.addEventListener("DOMContentLoaded", () => {
  const farmasiPagePattern = /\/dashboard\/rekap_farmasi(?:_v2)?\.php(?:$|\?)/;
  const currentLocation = window.location.pathname + window.location.search;

  if (!farmasiPagePattern.test(currentLocation)) {
    return;
  }

  const statusBadge = document.getElementById("farmasiStatusBadge");
  let inFlight = false;

  const shouldPing = () => {
    if (document.hidden) {
      return false;
    }

    if (!statusBadge) {
      return false;
    }

    return String(statusBadge.dataset.status || "").toLowerCase() === "online";
  };

  const sendHeartbeat = async () => {
    if (inFlight || !shouldPing()) {
      return;
    }

    inFlight = true;

    try {
      await fetch("/actions/ping_farmasi_activity.php", {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
        },
      });
    } catch (e) {
      // Diamkan: endpoint ini memang best-effort.
    } finally {
      inFlight = false;
    }
  };

  setInterval(sendHeartbeat, 120000);
});
