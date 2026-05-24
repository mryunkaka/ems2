<?php
require_once __DIR__ . '/../config/helpers.php';

$footerLoginUrl = ems_url('/auth/login.php');
if (isset($pdo) && function_exists('ems_effective_unit')) {
    $footerUnit = ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []);
    $footerLoginUrl = ems_url('/auth/login.php?unit=' . urlencode($footerUnit));
}

$realtimeChatConfig = require __DIR__ . '/../config/realtime_chat.php';
$realtimeChatViewer = [
    'userId' => (string)($_SESSION['user_rh']['id'] ?? ''),
    'name' => (string)(($_SESSION['user_rh']['full_name'] ?? $_SESSION['user_rh']['name'] ?? '')),
    'role' => (string)($_SESSION['user_rh']['role'] ?? ''),
    'unit' => (string)($_SESSION['user_rh']['unit_code'] ?? ''),
    'pageTitle' => (string)($pageTitle ?? ''),
];
?>
</main>
</div>

<div id="globalUploadOverlay" class="global-upload-overlay hidden" aria-hidden="true">
    <div class="global-upload-overlay-box">
        <div class="global-upload-spinner" aria-hidden="true"></div>
        <div class="global-upload-title">Upload sedang diproses</div>
        <div class="global-upload-copy">Mohon tunggu. File besar mungkin memerlukan waktu lebih lama untuk diproses dan dikirim.</div>
    </div>
</div>

<style>
    .global-upload-overlay {
        position: fixed;
        inset: 0;
        z-index: 999999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
        background: rgba(15, 23, 42, 0.72);
        backdrop-filter: blur(6px);
    }

    .global-upload-overlay.hidden {
        display: none;
    }

    .global-upload-overlay-box {
        width: min(100%, 420px);
        border-radius: 24px;
        background: #ffffff;
        padding: 24px;
        text-align: center;
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.25);
    }

    .global-upload-spinner {
        width: 52px;
        height: 52px;
        margin: 0 auto 16px;
        border-radius: 999px;
        border: 4px solid #dbeafe;
        border-top-color: #0284c7;
        animation: ems-upload-spin 0.9s linear infinite;
    }

    .global-upload-title {
        font-size: 18px;
        font-weight: 800;
        color: #0f172a;
    }

    .global-upload-copy {
        margin-top: 8px;
        font-size: 13px;
        line-height: 1.6;
        color: #475569;
    }

    @keyframes ems-upload-spin {
        to {
            transform: rotate(360deg);
        }
    }
</style>

<?php if (!empty($realtimeChatConfig['enabled'])): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(ems_asset('/assets/css/realtime-chat-widget.css'), ENT_QUOTES, 'UTF-8') ?>">

    <div id="emsLiveChat" class="ems-live-chat" aria-live="polite">
        <div id="emsLiveChatPanel" class="ems-live-chat-panel">
            <div class="ems-live-chat-panel-head">
                <div>
                    <div class="ems-live-chat-panel-title">Live Chat</div>
                    <div class="ems-live-chat-panel-subtitle">Online sekarang</div>
                </div>
                <button id="emsLiveChatClose" class="ems-live-chat-close" type="button" aria-label="Tutup live chat">
                    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
                        <path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>

            <div class="ems-live-chat-status">
                <div>
                    <div id="emsLiveChatOnlineLabel" class="ems-live-chat-status-label">0 visitor online</div>
                    <div id="emsLiveChatViewersMeta" class="ems-live-chat-status-meta">Menghubungkan...</div>
                </div>
                <div class="ems-live-chat-online-pill" data-ems-live-chat-online-count>0</div>
            </div>

            <div id="emsLiveChatMessages" class="ems-live-chat-messages">
                <div id="emsLiveChatStatus" class="ems-live-chat-empty">Menghubungkan live chat...</div>
            </div>

            <form id="emsLiveChatForm" class="ems-live-chat-form">
                <div class="ems-live-chat-composer">
                    <textarea id="emsLiveChatInput" class="ems-live-chat-input" maxlength="500" placeholder="Tulis pesan..."></textarea>
                    <button id="emsLiveChatSend" class="ems-live-chat-send" type="submit" aria-label="Kirim pesan">
                        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
                            <path d="M3 20l18-8L3 4v6l12 2-12 2z" fill="currentColor"/>
                        </svg>
                    </button>
                </div>
                <div class="ems-live-chat-note">
                    <span>Maks. 500 karakter</span>
                    <span>Live</span>
                </div>
            </form>

            <div id="emsLiveChatViewers" class="ems-live-chat-viewers"></div>
        </div>

        <button id="emsLiveChatToggle" class="ems-live-chat-toggle" type="button" aria-label="Buka live chat">
            <span class="ems-live-chat-toggle-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="20" height="20">
                    <path d="M6 9h12M6 13h8m-8 8l-2-4H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-9l-5 4Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span class="ems-live-chat-toggle-dot"></span>
            </span>
            <span class="ems-live-chat-toggle-copy">
                <span class="ems-live-chat-toggle-title">Live Chat</span>
                <span class="ems-live-chat-toggle-meta">
                    <span class="ems-live-chat-online-pill" data-ems-live-chat-online-count>0</span>
                    <span>Online</span>
                </span>
            </span>
        </button>
    </div>
<?php endif; ?>

<script src="<?= htmlspecialchars(ems_asset('/assets/js/app.js?refresh=20260501-setting-akun-fast'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(ems_asset('/assets/design/js/app-shell.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/photoswipe/photoswipe.umd.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/photoswipe/photoswipe-lightbox.umd.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(ems_asset('/assets/design/js/photoswipe-init.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/jquery/jquery.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
if (!window.jQuery) {
    document.write('<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/jquery/jquery.min.js?refresh=20260501'), ENT_QUOTES, 'UTF-8') ?>"><\/script>');
}
</script>
<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/datatables/dataTables.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.dataTable) {
    document.write('<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/datatables/dataTables.min.js?refresh=20260501'), ENT_QUOTES, 'UTF-8') ?>"><\/script>');
}
</script>
<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/jszip/jszip.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
if (!window.JSZip) {
    document.write('<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/jszip/jszip.min.js?refresh=20260501'), ENT_QUOTES, 'UTF-8') ?>"><\/script>');
}
</script>
<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/datatables/dataTables.buttons.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.dataTable || !window.jQuery.fn.dataTable.Buttons) {
    document.write('<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/datatables/dataTables.buttons.min.js?refresh=20260501'), ENT_QUOTES, 'UTF-8') ?>"><\/script>');
}
</script>
<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/datatables/buttons.html5.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.dataTable || !window.jQuery.fn.dataTable.ext || !window.jQuery.fn.dataTable.ext.buttons) {
    document.write('<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/datatables/buttons.html5.min.js?refresh=20260501'), ENT_QUOTES, 'UTF-8') ?>"><\/script>');
}
</script>
<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/chartjs/chart.umd.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
if (!window.Chart) {
    document.write('<script src="<?= htmlspecialchars(ems_asset('/assets/vendor/chartjs/chart.umd.js?refresh=20260501'), ENT_QUOTES, 'UTF-8') ?>"><\/script>');
}
</script>

<!-- Sidebar Toggle Script -->
<script>
// Close sidebar on window resize to desktop
window.addEventListener('resize', function() {
    if (window.innerWidth >= 768) {
        document.body.classList.remove('sidebar-open');
    }
});
</script>

<script>
    (function setupGlobalUploadOverlay() {
        const overlay = document.getElementById('globalUploadOverlay');
        if (!overlay) {
            return;
        }

        function showOverlay() {
            overlay.classList.remove('hidden');
            overlay.setAttribute('aria-hidden', 'false');
            document.body.classList.add('modal-open');
        }

        function hideOverlay() {
            overlay.classList.add('hidden');
            overlay.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('modal-open');
        }

        window.emsShowUploadOverlay = showOverlay;
        window.emsHideUploadOverlay = hideOverlay;

        window.addEventListener('pageshow', hideOverlay);
        window.addEventListener('pagehide', hideOverlay);

        document.addEventListener('submit', function(event) {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            const hasFileInput = form.matches('[enctype="multipart/form-data"]') || !!form.querySelector('input[type="file"]');
            if (!hasFileInput) {
                return;
            }

            const fileInputs = Array.from(form.querySelectorAll('input[type="file"]'));
            const hasSelectedFiles = fileInputs.some(function(input) {
                return input.files && input.files.length > 0;
            });

            if (!hasSelectedFiles) {
                return;
            }

            const submitButtons = Array.from(form.querySelectorAll('button[type="submit"], input[type="submit"]'));
            submitButtons.forEach(function(button) {
                button.disabled = true;
            });

            window.requestAnimationFrame(function() {
                if (event.defaultPrevented) {
                    submitButtons.forEach(function(button) {
                        button.disabled = false;
                    });
                    return;
                }

                showOverlay();
            });
        }, true);
    })();
</script>

<script>
    (function realtimeSessionCheck() {
	        let timer = null;
	        let failCount = 0;
	        let inFlight = false;
            let pauseUntil = 0;

	        async function safeParseJSONResponse(res) {
	            if (!res || !res.ok) return null;

	            const contentType = String(res.headers.get('content-type') || '').toLowerCase();
	            const raw = await res.text();
	            if (!raw) return null;

	            if (contentType.includes('application/json')) {
	                try {
	                    return JSON.parse(raw);
	                } catch (e) {
	                    return null;
	                }
	            }

	            const trimmed = raw.trim();
	            if (!trimmed || trimmed.startsWith('<')) return null;

	            try {
	                return JSON.parse(trimmed);
	            } catch (e) {
	                return null;
	            }
	        }

	        async function safeFetchJSON(url, options = {}, timeoutMs = 6000) {
	            if (navigator.onLine === false) return null;

	            const controller = new AbortController();
	            const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

	            try {
	                const res = await fetch(url, {
	                    credentials: 'same-origin',
	                    cache: 'no-store',
	                    ...options,
	                    signal: controller.signal
	                });
	                return await safeParseJSONResponse(res);
	            } catch (e) {
	                return null;
	            } finally {
	                clearTimeout(timeoutId);
	            }
	        }

	        function schedule(ms) {
	            if (timer) clearTimeout(timer);
	            timer = setTimeout(runOnce, ms);
	        }

	        async function runOnce() {
	            const pauseRemaining = Math.max(0, pauseUntil - Date.now());
	            if (pauseRemaining > 0) {
	                schedule(pauseRemaining);
	                return;
	            }

	            if (document.hidden) {
	                schedule(60000);
	                return;
	            }

	            if (inFlight) return;
	            inFlight = true;

	            const beforeFail = failCount;
	            try {
		                const data = await safeFetchJSON(window.emsUrl('/auth/check_session.php'));
		                if (!data) {
		                    failCount++;
                            pauseUntil = Date.now() + 300000;
		                    if (beforeFail === 0) {
		                        window.emsLogOnce('session-check-backoff', 'Session check sementara gagal dimuat, polling dibackoff.');
		                    }
		                    return;
		                }

	                failCount = 0;
                    pauseUntil = 0;

	                if (!data.valid) {
	                    document.body.innerHTML = `
	                        <div style="
	                            position:fixed;inset:0;
	                            display:flex;
	                            align-items:center;
	                            justify-content:center;
	                            background:rgba(0,0,0,.6);
	                            color:#fff;
	                            font-size:18px;
	                            z-index:99999">
	                            <div>
	                                <p>Akun Anda login di device lain</p>
	                                <p>Anda akan logout...</p>
	                            </div>
	                        </div>`;
		                    setTimeout(() => {
		                        window.location.href = <?= json_encode($footerLoginUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
		                    }, 1500);
	                }
	            } finally {
	                inFlight = false;
	                const base = 30000;
	                const backoff = Math.min(300000, 60000 * Math.pow(2, Math.min(Math.max(0, failCount - 1), 3)));
	                schedule(failCount ? backoff : base);
	            }
	        }

	        runOnce();
	        document.addEventListener('visibilitychange', () => {
	            if (!document.hidden) {
                    const pauseRemaining = Math.max(0, pauseUntil - Date.now());
                    if (pauseRemaining > 0) {
                        schedule(pauseRemaining);
                        return;
                    }
	                runOnce();
	            }
	        });
	        window.addEventListener('online', () => {
	            failCount = 0;
	            runOnce();
	        });
	    })();
	</script>

<script>
    setInterval(function() {
        if (document.hidden) {
            return;
        }

        var elements = document.querySelectorAll('.realtime-duration');
        if (!elements.length) {
            return;
        }

        for (var index = 0; index < elements.length; index++) {
            var el = elements[index];
            var timestamp = parseInt(el.getAttribute('data-start-timestamp'));

            if (!timestamp) {
                continue;
            }

            var now = Math.floor(Date.now() / 1000);
            var elapsed = now - timestamp;

            if (elapsed < 0) elapsed = 0;

            var hours = Math.floor(elapsed / 3600);
            var minutes = Math.floor((elapsed % 3600) / 60);
            var seconds = elapsed % 60;

            var display =
                (hours < 10 ? '0' : '') + hours + ':' +
                (minutes < 10 ? '0' : '') + minutes + ':' +
                (seconds < 10 ? '0' : '') + seconds;

            el.textContent = display;
        }
    }, 1000);
</script>

<?php if (!empty($realtimeChatConfig['enabled'])): ?>
    <script>
        window.EMS_REALTIME_CHAT_CONFIG = <?= json_encode([
            'enabled' => true,
            'firebase' => $realtimeChatConfig['firebase'],
            'paths' => $realtimeChatConfig['paths'],
            'ui' => $realtimeChatConfig['ui'],
            'viewer' => $realtimeChatViewer,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    </script>
    <script type="module" src="<?= htmlspecialchars(ems_asset('/assets/js/realtime-chat-widget.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endif; ?>

</body>

</html>
