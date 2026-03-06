</main>

</div>

<script src="/assets/js/app.js"></script>
<script src="/assets/design/js/app-shell.js"></script>
<script src="/assets/vendor/photoswipe/photoswipe.umd.min.js"></script>
<script src="/assets/vendor/photoswipe/photoswipe-lightbox.umd.min.js"></script>
<script src="/assets/design/js/photoswipe-init.js"></script>
<script src="/assets/vendor/jquery/jquery.min.js"></script>
<script src="/assets/vendor/datatables/dataTables.min.js"></script>
<script src="/assets/vendor/jszip/jszip.min.js"></script>
<script src="/assets/vendor/datatables/dataTables.buttons.min.js"></script>
<script src="/assets/vendor/datatables/buttons.html5.min.js"></script>
<script src="/assets/vendor/chartjs/chart.umd.js"></script>
<script>
    (function realtimeSessionCheck() {
	        let timer = null;
	        let failCount = 0;
	        let inFlight = false;

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
	                if (!res.ok) return null;
	                return await res.json();
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
	            if (inFlight) return;
	            inFlight = true;

	            const beforeFail = failCount;
	            try {
	                const data = await safeFetchJSON('/auth/check_session.php');
	                if (!data) {
	                    failCount++;
	                    if (beforeFail === 0) console.warn('Session check: gagal fetch, polling dibackoff.');
	                    return;
	                }

	                failCount = 0;

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
	                        window.location.href = '/auth/login.php';
	                    }, 1500);
	                }
	            } finally {
	                inFlight = false;
	                const base = 5000;
	                const backoff = Math.min(300000, 60000 * Math.pow(2, Math.min(Math.max(0, failCount - 1), 3)));
	                schedule(failCount ? backoff : base);
	            }
	        }

	        runOnce();
	        window.addEventListener('online', () => {
	            failCount = 0;
	            runOnce();
	        });
	    })();
	</script>

<script>
    setInterval(function() {
        var elements = document.querySelectorAll('.realtime-duration');

        elements.forEach(function(el) {
            var timestamp = parseInt(el.getAttribute('data-start-timestamp'));


            if (!timestamp) {
                return;
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
        });
    }, 1000);
</script>

</body>

</html>

