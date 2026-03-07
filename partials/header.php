<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pushConfig = require __DIR__ . '/../config/push.php';

$user = $_SESSION['user_rh'] ?? [];

$medicName    = $user['name'] ?? 'User';
$medicJabatan = ems_position_label($user['position'] ?? '-');
$medicRole    = $user['role'] ?? null;

$avatarInitials = initialsFromName($medicName);
$avatarColor    = avatarColorFromName($medicName);

// ======================================================
// CEK NOTIFIKASI FARMASI (ANTI ERROR)
// ======================================================
$userId = $user['id'] ?? 0;
$notif  = null;

if ($userId) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, message
            FROM user_farmasi_notifications
            WHERE user_id = ?
              AND type = 'check_online'
              AND is_read = 0
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $notif = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Jangan matikan halaman karena notif
        // optional: log error
        // error_log($e->getMessage());
        $notif = null;
    }
}

?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle ?? 'Farmasi EMS') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="icon" type="image/png" href="/assets/logo.png">
    <link rel="apple-touch-icon" href="/assets/logo.png">
    <link rel="stylesheet" href="<?= htmlspecialchars(ems_asset('/assets/vendor/datatables/dataTables.dataTables.min.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(ems_asset('/assets/vendor/datatables/buttons.dataTables.min.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(ems_asset('/assets/vendor/photoswipe/photoswipe.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(ems_asset('/assets/design/tailwind/build.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(ems_asset('/assets/css/overrides.css'), ENT_QUOTES, 'UTF-8') ?>">
    <script defer src="<?= htmlspecialchars(ems_asset('/assets/vendor/alpine/alpine.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>

</head>

<body x-data>
    <audio id="inboxSound" preload="auto">
        <source src="/assets/sound/notification.mp3" type="audio/mpeg">
    </audio>

	    <div class="ems-app">
	        <header class="topbar">
	            <div class="topbar-left">
	                <button id="menuToggle" class="menu-btn" type="button" aria-label="Buka navigasi"><?= ems_icon('bars-3', 'h-7 w-7', '2.2') ?></button>
	
	                <div class="topbar-brand">
	                    <img src="/assets/logo.png" alt="EMS Logo" class="topbar-logo">
	                    <div class="topbar-text">
	                        <div class="topbar-title">Roxwood Hospital</div>
	                        <div class="topbar-subtitle">Emergency Medical System</div>
	                    </div>
	                </div>
	            </div>
	
	            <div class="topbar-actions">
                    <div id="topbarClock" class="topbar-clock" aria-label="Waktu WIB">
                        <div class="topbar-clock-date" data-clock-date></div>
                        <div class="topbar-clock-time" data-clock-time></div>
                    </div>
	                <div class="notif-wrapper">
	                    <button id="enableNotif" class="notif-btn" title="Aktifkan Notifikasi" type="button"><?= ems_icon('bell', 'h-7 w-7', '2.2') ?><span class="notif-indicator hidden"></span></button>
	                </div>
	
	                <div class="inbox-wrapper">
	                    <button id="inboxBtn" class="inbox-btn" type="button" aria-label="Buka kotak masuk"><?= ems_icon('inbox', 'h-7 w-7', '2.2') ?><span id="inboxBadge" class="inbox-badge">0</span></button>
	
	                    <div id="inboxDropdown" class="inbox-dropdown hidden">
	                        <div class="inbox-header">Kotak Masuk</div>
	                        <ul id="inboxList"></ul>
                    </div>
                </div>
            </div>

        </header>

        <div id="inboxModal" class="hidden inbox-modal-overlay">
            <div class="inbox-modal-box">
                <h3 id="modalTitle"></h3>
                <p id="modalMessage"></p>

                <div class="inbox-modal-actions">
                    <button onclick="closeInboxModal()" type="button" class="btn-secondary">Batal</button>
                    <button onclick="deleteInbox()" class="btn-danger">Hapus</button>
                </div>
            </div>
        </div>

        <script>
            let notifTimer = null;
            let offlineTimer = null;
            let deadlineTime = null;

            let onlineModalActive = false;

            // =========================
            // UTIL FORMAT WAKTU
            // =========================
            function formatTime(ms) {
                const total = Math.max(0, Math.floor(ms / 1000));
                const m = String(Math.floor(total / 60)).padStart(2, '0');
                const s = String(total % 60).padStart(2, '0');
                return `${m}:${s}`;
            }

            // =========================
            // START COUNTDOWN DARI DEADLINE NYATA
            // =========================
            function startCountdownFromSeconds(seconds) {
                const el = document.getElementById('countdown');
                if (!el || seconds <= 0) {
                    el.textContent = '00:00';
                    return;
                }

                let remaining = seconds;

                if (offlineTimer) clearInterval(offlineTimer);

                el.textContent = formatTime(remaining * 1000);

                offlineTimer = setInterval(() => {
                    remaining--;

                    if (remaining <= 0) {
                        clearInterval(offlineTimer);
                        el.textContent = '00:00';
                        return;
                    }

                    el.textContent = formatTime(remaining * 1000);
                }, 1000);
            }

            // =========================
            // TAMPILKAN MODAL
            // =========================
            function showOnlineModal(message, remainingSeconds) {

                // Jika modal sudah aktif: jangan buat ulang
                if (onlineModalActive) return;

                onlineModalActive = true;

                const modal = document.createElement('div');
                modal.id = 'onlineModal';
                modal.style = `
                    position:fixed;
                    inset:0;
                    background:rgba(0,0,0,.55);
                    display:flex;
                    align-items:center;
                    justify-content:center;
                    z-index:999999;
                `;

                modal.innerHTML = `
                    <div style="
                        background:#fff;
                        padding:20px;
                        border-radius:12px;
                        max-width:360px;
                        width:90%;
                        text-align:center;
                        box-shadow:0 20px 40px rgba(0,0,0,.3);
                    ">
                        <h3>Konfirmasi Status Farmasi</h3>
                        <p>${message}</p>
                        <p style="font-size:13px;color:#6b7280">
                            Akan otomatis offline dalam
                            <strong><span id="countdown">--:--</span></strong>
                        </p>

                        <div style="display:flex;gap:10px;justify-content:center;margin-top:16px;">
                            <button onclick="confirmOnline()" class="btn-success">
                                Ya, masih online
                            </button>
                            <button onclick="setOffline()" class="btn-danger">
                                Saya tidak tersedia
                            </button>
                        </div>
                    </div>
                `;

                document.body.appendChild(modal);

                // START COUNTDOWN (INI YANG SEBELUMNYA ERROR)
                startCountdownFromSeconds(remainingSeconds);
            }


            // =========================
            // KONFIRMASI ONLINE
            // =========================
            function confirmOnline() {
                fetch('/actions/confirm_farmasi_online.php', {
                        method: 'POST'
                    })
                    .then(() => {
                        if (offlineTimer) clearInterval(offlineTimer);
                        removeModal();
                    });
            }

            // =========================
            // SET OFFLINE
            // =========================
            function setOffline(auto = false) {
                if (!auto) {
                    if (!confirm('Anda akan diset OFFLINE dan tidak menerima transaksi farmasi. Lanjutkan?')) {
                        return;
                    }
                }

                fetch('/actions/set_farmasi_offline.php', {
                        method: 'POST'
                    })
                    .then(() => {
                        if (offlineTimer) clearInterval(offlineTimer);
                        removeModal();
                    });
            }

            // =========================
            // HAPUS MODAL
            // =========================
            function removeModal() {
                const modal = document.getElementById('onlineModal');
                if (modal) modal.remove();

                onlineModalActive = false;

                if (offlineTimer) {
                    clearInterval(offlineTimer);
                    offlineTimer = null;
                }
            }

            // =========================
            // CEK NOTIF + DEADLINE NYATA
            // =========================
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

            async function checkFarmasiNotif() {
                try {
                    const notif = await safeFetchJSON('/actions/check_farmasi_notif.php');
                    if (!notif) return;

                    /*
                    |-------------------------------------------------
                    | Jika user sudah offline: paksa hapus modal
                    |-------------------------------------------------
                    */
                    if (notif.status && notif.status === 'offline') {
                        removeModal();
                        return;
                    }

                    if (!notif.has_notif) {
                        return;
                    }

                    const dl = await safeFetchJSON('/actions/get_farmasi_deadline.php');
                    if (!dl) return;

                    if (dl.active && dl.remaining && !onlineModalActive) {
                        showOnlineModal(notif.message, dl.remaining);
                    }

                } catch (e) {
                    // console.error('Farmasi notif error', e);
                }
            }

            // Polling aman (backoff jika server tidak bisa diakses)
            let farmasiNotifFailCount = 0;
            let farmasiNotifInFlight = false;

            function scheduleFarmasiNotif(nextMs) {
                if (notifTimer) clearTimeout(notifTimer);
                notifTimer = setTimeout(runFarmasiNotifOnce, nextMs);
            }

            async function runFarmasiNotifOnce() {
                if (farmasiNotifInFlight) return;
                farmasiNotifInFlight = true;

                const beforeFail = farmasiNotifFailCount;
                try {
                    const notif = await safeFetchJSON('/actions/check_farmasi_notif.php');
                    if (!notif) {
                        farmasiNotifFailCount++;
                        if (beforeFail === 0) console.warn('Farmasi notif: gagal fetch, polling dibackoff.');
                        return;
                    }

                    farmasiNotifFailCount = 0;

                    if (notif.status && notif.status === 'offline') {
                        removeModal();
                        return;
                    }

                    if (!notif.has_notif) return;

                    const dl = await safeFetchJSON('/actions/get_farmasi_deadline.php');
                    if (dl && dl.active && dl.remaining && !onlineModalActive) {
                        showOnlineModal(notif.message, dl.remaining);
                    }
                } finally {
                    farmasiNotifInFlight = false;
                    const base = 5000;
                    const backoff = Math.min(300000, 60000 * Math.pow(2, Math.min(Math.max(0, farmasiNotifFailCount - 1), 3)));
                    scheduleFarmasiNotif(farmasiNotifFailCount ? backoff : base);
                }
            }

            document.addEventListener('DOMContentLoaded', () => {
                runFarmasiNotifOnce();
            });

            window.addEventListener('online', () => {
                farmasiNotifFailCount = 0;
                runFarmasiNotifOnce();
            });
        </script>
        <script>
	            /* ======================================================
	   HEARTBEAT - GLOBAL ACTIVITY TRACKER
	   ====================================================== */

            let heartbeatTimer = null;

            /**
             * Kirim heartbeat ke server
             * HANYA update last_activity_at jika status ONLINE
             */
            async function sendHeartbeat() {
                try {
                    const res = await fetch('/actions/heartbeat.php', {
                        method: 'POST',
                        cache: 'no-store'
                    });

                    const data = await res.json();

                    // Jika user sudah offline
                    if (!data.active) {
                        stopHeartbeat();

                        // Hapus modal konfirmasi jika masih ada
                        removeModal();
                    }

                } catch (e) {
                    console.warn('Heartbeat gagal', e);
                }
            }

            /**
             * Mulai heartbeat
             */
            function startHeartbeat() {
                if (heartbeatTimer) return;

                // Setiap 15 detik (aman dan ringan)
                heartbeatTimer = setInterval(sendHeartbeat, 15000);
            }

            /**
             * Hentikan heartbeat
             */
            function stopHeartbeat() {
                if (heartbeatTimer) {
                    clearInterval(heartbeatTimer);
                    heartbeatTimer = null;
                }
            }

            /* ======================================================
               AUTO START HEARTBEAT SAAT PAGE LOAD
               ====================================================== */
            document.addEventListener('DOMContentLoaded', () => {
                startHeartbeat();
            });
        </script>
        <script>
            const inboxBtn = document.getElementById('inboxBtn');
            const inboxDropdown = document.getElementById('inboxDropdown');
            const inboxList = document.getElementById('inboxList');
            const inboxBadge = document.getElementById('inboxBadge');

            if (inboxBtn && inboxDropdown && inboxList && inboxBadge) {
                inboxBtn.addEventListener('click', () => {
                    inboxDropdown.classList.toggle('hidden');
                    loadInbox();
                });
            }

            async function loadInbox() {
                const data = await safeFetchJSON('/actions/get_inbox.php');
                if (!inboxList || !inboxBadge) return;

                if (!data) {
                    inboxList.innerHTML = '<li style="padding:12px;color:#888">Inbox tidak bisa dimuat</li>';
                    return;
                }

                inboxList.innerHTML = '';
                inboxBadge.textContent = data.unread;

                if (data.items.length === 0) {
                    inboxList.innerHTML = '<li style="padding:12px;color:#888">Tidak ada inbox</li>';
                    return;
                }

                data.items.forEach(item => {
                    const li = document.createElement('li');
                    li.className = 'inbox-item ' + (item.is_read ? 'read' : 'unread');
                    li.innerHTML = `
            <div>${item.title}</div>
            <small>${item.created_at_label}</small>

        `;
                    li.onclick = () => openInboxModal(item);
                    inboxList.appendChild(li);
                });
            }
        </script>
        <script>
            let currentInboxId = null;

            function openInboxModal(item) {
                currentInboxId = item.id;

                document.getElementById('modalTitle').textContent = item.title;
                document.getElementById('modalMessage').innerHTML = item.message;
                document.getElementById('inboxModal').classList.remove('hidden');

                fetch('/actions/read_inbox.php', {
                    method: 'POST',
                    body: new URLSearchParams({
                        id: item.id
                    })
                });

                loadInbox(); // refresh badge
            }

            function closeInboxModal() {
                document.getElementById('inboxModal').classList.add('hidden');
            }

            function deleteInbox() {
                if (!currentInboxId) return;

                fetch('/actions/delete_inbox.php', {
                    method: 'POST',
                    body: new URLSearchParams({
                        id: currentInboxId
                    })
                }).then(() => {
                    closeInboxModal();
                    loadInbox();
                });
            }
        </script>
        <script>
            /* ======================================================
   INBOX AUTO POLLING (REALTIME LIGHT)
   ====================================================== */

            let inboxPollingTimer = null;
            let lastUnreadCount = 0;

            /**
             * Polling inbox (ambil data terbaru)
             */
            async function pollInbox() {
                try {
                    const data = await safeFetchJSON('/actions/get_inbox.php');
                    if (!data) return;

                    // Update badge
                    inboxBadge.textContent = data.unread;
                    inboxBadge.style.display = data.unread > 0 ? 'inline-block' : 'none';

                    // Jika ada inbox baru
                    if (data.unread > lastUnreadCount) {
                        onNewInbox(data.unread - lastUnreadCount);
                    }

                    lastUnreadCount = data.unread;

	                    // Jika dropdown sedang terbuka -> refresh list
                    if (!inboxDropdown.classList.contains('hidden')) {
                        renderInboxList(data.items);
                    }

                } catch (e) {
                    // abaikan error jaringan
                }
            }

            /**
             * Render inbox list (dipakai polling & click)
             */
            function renderInboxList(items) {
                inboxList.innerHTML = '';

                if (!items || items.length === 0) {
                    inboxList.innerHTML =
                        '<li style="padding:12px;color:#888">Tidak ada inbox</li>';
                    return;
                }

                items.forEach(item => {
                    const li = document.createElement('li');
                    li.className = 'inbox-item ' + (item.is_read ? 'read' : 'unread');
                    li.innerHTML = `
            <div>${item.title}</div>
            <small>${item.created_at_label}</small>
        `;
                    li.onclick = () => openInboxModal(item);
                    inboxList.appendChild(li);
                });
            }

            /**
             * Event jika inbox baru masuk
             */
            function onNewInbox(count) {
                // Badge pulse
                inboxBadge.classList.add('pulse');
                setTimeout(() => inboxBadge.classList.remove('pulse'), 800);

                // Bunyi notif (opsional)
                playInboxSound();
            }

            /**
             * Sound notif (safe)
             */
            function playInboxSound() {
                const audio = document.getElementById('inboxSound');
                if (audio) {
                    audio.currentTime = 0;
                    audio.play().catch(() => {});
                }
            }

            /**
             * Start polling
             */
            function startInboxPolling() {
                if (inboxPollingTimer) return;

                let inboxFailCount = 0;
                let inboxInFlight = false;

                const schedule = (ms) => {
                    if (inboxPollingTimer) clearTimeout(inboxPollingTimer);
                    inboxPollingTimer = setTimeout(runOnce, ms);
                };

                const runOnce = async () => {
                    if (inboxInFlight) return;
                    inboxInFlight = true;

                    const beforeFail = inboxFailCount;
                    try {
                        const data = await safeFetchJSON('/actions/get_inbox.php');
                        if (!data) {
                            inboxFailCount++;
                            if (beforeFail === 0) console.warn('Inbox: gagal fetch, polling dibackoff.');
                            return;
                        }

                        inboxFailCount = 0;

                        // Update badge
                        inboxBadge.textContent = data.unread;
                        inboxBadge.style.display = data.unread > 0 ? 'inline-block' : 'none';

                        // Jika ada inbox baru
                        if (data.unread > lastUnreadCount) {
                            onNewInbox(data.unread - lastUnreadCount);
                        }

                        lastUnreadCount = data.unread;

	                        // Jika dropdown sedang terbuka -> refresh list
                        if (!inboxDropdown.classList.contains('hidden')) {
                            renderInboxList(data.items);
                        }
                    } finally {
                        inboxInFlight = false;
                        const base = 10000;
                        const backoff = Math.min(300000, 60000 * Math.pow(2, Math.min(Math.max(0, inboxFailCount - 1), 3)));
                        schedule(inboxFailCount ? backoff : base);
                    }
                };

                runOnce(); // langsung sekali saat load

                window.addEventListener('online', () => {
                    inboxFailCount = 0;
                    runOnce();
                });
            }

            /**
             * Stop polling (optional)
             */
            function stopInboxPolling() {
                if (inboxPollingTimer) {
                    clearTimeout(inboxPollingTimer);
                    inboxPollingTimer = null;
                }
            }

            /* AUTO START */
            document.addEventListener('DOMContentLoaded', startInboxPolling);
        </script>
        <script>
            const PUSH_PUBLIC_KEY = '<?= htmlspecialchars($pushConfig['public_key']) ?>';
        </script>

        <script src="/public/push-subscribe.js"></script>

        <script>
            document.addEventListener("DOMContentLoaded", async () => {
                if (!("serviceWorker" in navigator)) return;

                const reg = await navigator.serviceWorker.ready;
                const sub = await reg.pushManager.getSubscription();

                if (sub) {
                    const indicator = document.querySelector('.notif-indicator');
                    if (indicator) indicator.classList.remove('hidden');
                }
            });

            document.addEventListener('DOMContentLoaded', () => {
                const btn = document.getElementById('enableNotif');
                if (!btn) {
                    console.warn('Tombol enableNotif tidak ditemukan');
                    return;
                }

                btn.addEventListener('click', () => {
                    console.log('Enable notif diklik');
                    initPush();
                });
            });
        </script>


