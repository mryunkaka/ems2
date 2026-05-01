<?php
require_once __DIR__ . '/../config/helpers.php';

$footerLoginUrl = ems_url('/auth/login.php');
if (isset($pdo) && function_exists('ems_effective_unit')) {
    $footerUnit = ems_effective_unit($pdo, $_SESSION['user_rh'] ?? []);
    $footerLoginUrl = ems_url('/auth/login.php?unit=' . urlencode($footerUnit));
}
?>
</main>

</div>

<script src="<?= htmlspecialchars(ems_asset('/assets/js/app.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
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

</body>

</html>
