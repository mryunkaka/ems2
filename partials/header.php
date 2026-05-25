<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

$pushConfig = require __DIR__ . '/../config/push.php';
$realtimeSyncConfig = require __DIR__ . '/../config/realtime_chat.php';

$user = $_SESSION['user_rh'] ?? [];
$resolvedPdo = (isset($pdo) && $pdo instanceof PDO) ? $pdo : null;
$currentUnit = $resolvedPdo instanceof PDO
    ? ems_effective_unit($resolvedPdo, $user)
    : ems_normalize_unit_code($user['unit_code'] ?? 'roxwood');
$hideAltaTopbarUtilities = $currentUnit === 'alta';
$realtimeMusicEnabled = !$hideAltaTopbarUtilities && !empty($realtimeSyncConfig['enabled']);
$currentHospitalName = ems_unit_hospital_name($currentUnit);
$currentLogoPath = ems_unit_logo_path($currentUnit);
$currentSystemName = ems_unit_system_name($currentUnit);

$medicName    = $user['name'] ?? 'User';
$medicJabatan = ems_position_label($user['position'] ?? '-');
$medicRole    = $user['role'] ?? null;
$csrfToken = function_exists('generateCsrfToken') ? generateCsrfToken() : '';

$avatarInitials = initialsFromName($medicName);
$avatarColor    = avatarColorFromName($medicName);

// ======================================================
// CEK NOTIFIKASI FARMASI (ANTI ERROR)
// ======================================================
$userId = $user['id'] ?? 0;
$notif  = null;

if ($userId && !$hideAltaTopbarUtilities && $resolvedPdo instanceof PDO) {
    try {
        $stmt = $resolvedPdo->prepare("
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

?><!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle ?? 'Farmasi EMS') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    <link rel="icon" type="image/png" href="<?= htmlspecialchars(ems_asset($currentLogoPath), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars(ems_asset($currentLogoPath), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(ems_asset('/assets/vendor/datatables/dataTables.dataTables.min.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(ems_asset('/assets/vendor/datatables/buttons.dataTables.min.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(ems_asset('/assets/vendor/photoswipe/photoswipe.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(ems_asset('/assets/design/tailwind/build.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(ems_asset('/assets/design/tailwind/inbox-modal.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(ems_asset('/assets/css/overrides.css'), ENT_QUOTES, 'UTF-8') ?>">
    <?php if ($realtimeMusicEnabled): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars(ems_asset('/assets/css/realtime-music-widget.css'), ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <script defer src="<?= htmlspecialchars(ems_asset('/assets/vendor/alpine/alpine.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>

</head>

<body x-data>
    <?php if (!$hideAltaTopbarUtilities): ?>
        <audio id="inboxSound" preload="auto">
            <source src="<?= htmlspecialchars(ems_asset('/assets/sound/notification.mp3'), ENT_QUOTES, 'UTF-8') ?>" type="audio/mpeg">
        </audio>
    <?php endif; ?>
    <script>
        window.EMS_BASE_URL = <?= json_encode(ems_base_path(), JSON_UNESCAPED_SLASHES) ?>;
        window.EMS_CSRF_TOKEN = <?= json_encode($csrfToken, JSON_UNESCAPED_SLASHES) ?>;
        window.EMS_USER_ID = <?= json_encode((int)($user['id'] ?? 0), JSON_UNESCAPED_SLASHES) ?>;
        window.emsUrl = window.emsUrl || function(path) {
            const normalized = '/' + String(path || '').replace(/^\/+/, '');
            if (!window.EMS_BASE_URL) {
                return normalized;
            }
            if (normalized === window.EMS_BASE_URL || normalized.indexOf(window.EMS_BASE_URL + '/') === 0) {
                return normalized;
            }
            return window.EMS_BASE_URL + normalized;
        };
        window.emsLogOnce = window.emsLogOnce || (function() {
            const emitted = {};
            return function(key, message, detail) {
                if (emitted[key]) {
                    return;
                }
                emitted[key] = true;
                if (typeof detail === 'undefined') {
                    console.warn(message);
                    return;
                }
                console.warn(message, detail);
            };
        })();
    </script>

	    <div class="ems-app">
	        <header class="topbar">
	            <div class="topbar-left">
	                <button id="menuToggle" class="menu-btn" type="button" aria-label="Buka navigasi" onclick="document.body.classList.toggle('sidebar-open'); return false;"><?= ems_icon('bars-3', 'h-7 w-7', '2.2') ?></button>

	                <div class="topbar-brand">
	                    <img src="<?= htmlspecialchars(ems_asset($currentLogoPath), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($currentHospitalName, ENT_QUOTES, 'UTF-8') ?>" class="topbar-logo">
	                    <div class="topbar-text">
	                        <div class="topbar-title"><?= htmlspecialchars($currentHospitalName) ?></div>
	                        <div class="topbar-subtitle"><?= htmlspecialchars($currentSystemName) ?></div>
	                    </div>
	                </div>
	            </div>
	
	            <div class="topbar-actions">
                    <div id="topbarClock" class="topbar-clock" aria-label="Waktu WIB">
                        <div class="topbar-clock-date" data-clock-date></div>
                        <div class="topbar-clock-time" data-clock-time></div>
                    </div>
                    <?php if (!$hideAltaTopbarUtilities): ?>
	                    <div class="notif-wrapper">
	                        <button id="enableNotif" class="notif-btn" title="Aktifkan Notifikasi" type="button"><?= ems_icon('bell', 'h-7 w-7', '2.2') ?><span class="notif-indicator hidden"></span></button>
	                    </div>
	
	                    <div class="inbox-wrapper">
	                        <button id="inboxBtn" class="inbox-btn" type="button" aria-label="Buka kotak masuk"><?= ems_icon('inbox', 'h-7 w-7', '2.2') ?><span id="inboxBadge" class="inbox-badge" style="display:none">0</span></button>
	
	                        <div id="inboxDropdown" class="inbox-dropdown hidden">
	                            <div class="inbox-header">
                                    <span>Kotak Masuk</span>
                                    <div class="inbox-header-actions">
                                        <button id="inboxMarkAllBtn" type="button" class="inbox-header-btn">Baca Semua</button>
                                        <button id="inboxDeleteAllBtn" type="button" class="inbox-header-btn is-danger">Hapus Semua</button>
                                    </div>
                                </div>
	                            <ul id="inboxList"></ul>
                            </div>
                        </div>

                        <?php if ($realtimeMusicEnabled): ?>
                            <div class="music-wrapper">
                                <button id="emsLiveMusicBtn" class="inbox-btn ems-live-music-btn" type="button" aria-label="Buka live music"><?= ems_icon('play-circle', 'h-7 w-7', '2.2') ?><span id="emsLiveMusicBadge" class="inbox-badge ems-live-music-badge" style="display:none">0</span></button>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
            </div>

        </header>

        <?php if (!$hideAltaTopbarUtilities): ?>
            <div id="inboxModal" class="hidden inbox-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
                <div class="inbox-modal-box modal-shell modal-frame-md">
                    <div class="modal-head">
                        <div class="min-w-0">
                            <div id="modalTitle" class="modal-title"></div>
                            <div id="modalMeta" class="meta-text-xs mt-1 text-slate-500"></div>
                        </div>
                        <button onclick="closeInboxModal()" type="button" class="modal-close-btn" aria-label="Tutup modal">
                            <?= ems_icon('x-mark', 'h-5 w-5') ?>
                        </button>
                    </div>

                    <div class="modal-content">
                        <div id="modalMessage" class="inbox-modal-message"></div>
                    </div>

                    <div class="modal-foot">
                        <div class="inbox-modal-actions">
                            <button onclick="closeInboxModal()" type="button" class="btn-secondary">Tutup</button>
                            <button onclick="deleteInbox()" type="button" class="btn-danger">Hapus</button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($realtimeMusicEnabled): ?>
            <div id="emsLiveMusicModal" class="hidden modal-overlay ems-live-music-overlay" role="dialog" aria-modal="true" aria-labelledby="emsLiveMusicTitle">
                <div class="modal-box modal-shell modal-frame-lg ems-live-music-modal">
                    <div class="modal-head ems-live-music-head">
                        <div class="min-w-0">
                            <div id="emsLiveMusicTitle" class="modal-title">Live Music</div>
                            <div id="emsLiveMusicMeta" class="meta-text-xs mt-1 ems-live-music-meta">Sinkron untuk audio direct dan YouTube.</div>
                        </div>
                        <button id="emsLiveMusicClose" type="button" class="modal-close-btn" aria-label="Tutup live music">
                            <?= ems_icon('x-mark', 'h-5 w-5') ?>
                        </button>
                    </div>

                    <div class="modal-content ems-live-music-content">
                        <div class="ems-live-music-now">
                            <div class="ems-live-music-now-copy">
                                <div id="emsLiveMusicNowLabel" class="ems-live-music-now-label">Belum ada musik aktif</div>
                                <div id="emsLiveMusicNowMeta" class="ems-live-music-now-meta">Tambahkan link audio direct atau YouTube untuk mulai siaran.</div>
                            </div>
                            <div class="ems-live-music-now-actions">
                                <button id="emsLiveMusicEnableAudio" type="button" class="btn-secondary ems-live-music-enable">Aktifkan Audio</button>
                                <button id="emsLiveMusicPrimaryAction" type="button" class="btn-secondary ems-live-music-action" disabled>Putar</button>
                                <button id="emsLiveMusicSkip" type="button" class="btn-secondary ems-live-music-action" disabled>Lewati</button>
                            </div>
                        </div>

                        <div class="ems-live-music-layout">
                            <div class="ems-live-music-queue-block">
                                <div class="ems-live-music-queue-head">
                                    <div class="ems-live-music-queue-title">Antrian Music</div>
                                    <div id="emsLiveMusicQueueCount" class="ems-live-music-queue-count">0 item</div>
                                </div>
                                <div id="emsLiveMusicQueue" class="ems-live-music-queue-list"></div>
                            </div>

                            <div id="emsLiveMusicPlayerShell" class="ems-live-music-player-shell">
                                <audio id="emsLiveMusicAudio" class="ems-live-music-audio" controls preload="none"></audio>
                                <div id="emsLiveMusicEmbedWrap" class="ems-live-music-embed-wrap hidden"></div>
                                <div id="emsLiveMusicHint" class="ems-live-music-hint">Link Spotify atau TikTok akan masuk antrian sebagai referensi. Audio sinkron realtime saat ini hanya untuk audio direct dan YouTube.</div>
                            </div>

                            <form id="emsLiveMusicForm" class="ems-live-music-form">
                                <div class="ems-live-music-inputs">
                                    <input id="emsLiveMusicUrl" type="url" placeholder="Tempel link YouTube, Spotify, TikTok, atau URL audio direct" autocomplete="off">
                                    <button id="emsLiveMusicAdd" type="submit" class="btn-primary ems-live-music-add">Tambah</button>
                                </div>
                                <div id="emsLiveMusicFormNote" class="ems-live-music-form-note">Antrian realtime terlihat untuk semua visitor yang membuka website.</div>
                            </form>

                            <div class="ems-live-music-bottom-actions">
                                <button id="emsLiveMusicCloseBottom" type="button" class="btn-secondary ems-live-music-close-bottom">Tutup</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="emsLiveMusicTutorialModal" class="hidden modal-overlay ems-live-music-overlay" role="dialog" aria-modal="true" aria-labelledby="emsLiveMusicTutorialTitle">
                <div class="modal-box modal-shell modal-frame-md ems-live-music-tutorial-modal">
                    <div class="modal-head ems-live-music-head">
                        <div class="min-w-0">
                            <div id="emsLiveMusicTutorialTitle" class="modal-title">Live Music Aktif</div>
                            <div class="meta-text-xs mt-1 ems-live-music-meta">Pemberitahuan ini hanya muncul sekali di browser ini.</div>
                        </div>
                        <button id="emsLiveMusicTutorialClose" type="button" class="modal-close-btn" aria-label="Tutup tutorial live music">
                            <?= ems_icon('x-mark', 'h-5 w-5') ?>
                        </button>
                    </div>

                    <div class="modal-content ems-live-music-tutorial-content">
                        <div class="ems-live-music-tutorial-copy">
                            Saat ada siaran live music, audio akan otomatis aktif di browser ini.
                        </div>
                        <div class="ems-live-music-tutorial-steps">
                            <div class="ems-live-music-tutorial-step">
                                <span class="ems-live-music-tutorial-step-no">1</span>
                                <span>Buka menu <strong>Live Music</strong> dari tombol musik di kanan atas.</span>
                            </div>
                            <div class="ems-live-music-tutorial-step">
                                <span class="ems-live-music-tutorial-step-no">2</span>
                                <span>Jika ingin mematikan suara, klik tombol <strong>Audio Nonaktif</strong>.</span>
                            </div>
                            <div class="ems-live-music-tutorial-step">
                                <span class="ems-live-music-tutorial-step-no">3</span>
                                <span>Jika ingin menyalakan lagi, klik tombol <strong>Audio Aktif</strong>.</span>
                            </div>
                        </div>
                    </div>

                    <div class="modal-foot">
                        <div class="modal-actions justify-end">
                            <button id="emsLiveMusicTutorialOpen" type="button" class="btn-primary">Buka Live Music</button>
                            <button id="emsLiveMusicTutorialDismiss" type="button" class="btn-secondary">Mengerti</button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div id="birthdayTodayModal" class="hidden inbox-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="birthdayTodayTitle">
            <div class="inbox-modal-box modal-shell modal-frame-md">
                <div class="modal-head">
                    <div class="min-w-0">
                        <div id="birthdayTodayTitle" class="modal-title">Ulang Tahun Hari Ini</div>
                        <div class="meta-text-xs mt-1 text-slate-500">Modal ini hanya muncul sekali sampai Anda menutupnya.</div>
                    </div>
                    <button onclick="closeBirthdayTodayModal()" type="button" class="modal-close-btn" aria-label="Tutup modal">
                        <?= ems_icon('x-mark', 'h-5 w-5') ?>
                    </button>
                </div>
                <div class="modal-content">
                    <div id="birthdayTodayList" class="space-y-3"></div>
                </div>
                <div class="modal-foot">
                    <div class="modal-actions justify-end">
                        <button onclick="closeBirthdayTodayModal()" type="button" class="btn-secondary">Tutup</button>
                    </div>
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
                fetch(window.emsUrl('/actions/confirm_farmasi_online.php'), {
                        method: 'POST',
                        headers: {
                            'X-CSRF-Token': String(window.EMS_CSRF_TOKEN || '')
                        }
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

                fetch(window.emsUrl('/actions/set_farmasi_offline.php'), {
                        method: 'POST',
                        headers: {
                            'X-CSRF-Token': String(window.EMS_CSRF_TOKEN || '')
                        }
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
                if (!trimmed || trimmed.startsWith('<')) {
                    return null;
                }

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
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-Token': String(window.EMS_CSRF_TOKEN || ''),
                            ...(options.headers || {})
                        },
                        signal: controller.signal
                    });

                    return await safeParseJSONResponse(res);
                } catch (e) {
                    return null;
                } finally {
                    clearTimeout(timeoutId);
                }
            }

            async function checkFarmasiNotif() {
                try {
                    const notif = await safeFetchJSON(window.emsUrl('/actions/check_farmasi_notif.php'));
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

                    const dl = await safeFetchJSON(window.emsUrl('/actions/get_farmasi_deadline.php'));
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
            let farmasiNotifPauseUntil = 0;

            function scheduleFarmasiNotif(nextMs) {
                if (notifTimer) clearTimeout(notifTimer);
                notifTimer = setTimeout(runFarmasiNotifOnce, nextMs);
            }

            async function runFarmasiNotifOnce() {
                const pauseRemaining = Math.max(0, farmasiNotifPauseUntil - Date.now());
                if (pauseRemaining > 0) {
                    scheduleFarmasiNotif(pauseRemaining);
                    return;
                }

                if (document.hidden) {
                    scheduleFarmasiNotif(60000);
                    return;
                }

                if (farmasiNotifInFlight) return;
                farmasiNotifInFlight = true;

                const beforeFail = farmasiNotifFailCount;
                try {
                    const notif = await safeFetchJSON(window.emsUrl('/actions/check_farmasi_notif.php'));
                    if (!notif) {
                        farmasiNotifFailCount++;
                        farmasiNotifPauseUntil = Date.now() + 300000;
                        if (beforeFail === 0) {
                            window.emsLogOnce('farmasi-notif-backoff', 'Farmasi notif sementara gagal dimuat, polling dibackoff.');
                        }
                        return;
                    }

                    farmasiNotifFailCount = 0;
                    farmasiNotifPauseUntil = 0;

                    if (notif.status && notif.status === 'offline') {
                        removeModal();
                        return;
                    }

                    if (!notif.has_notif) return;

                    const dl = await safeFetchJSON(window.emsUrl('/actions/get_farmasi_deadline.php'));
                    if (dl && dl.active && dl.remaining && !onlineModalActive) {
                        showOnlineModal(notif.message, dl.remaining);
                    }
                } finally {
                    farmasiNotifInFlight = false;
                    const base = 30000;
                    const backoff = Math.min(300000, 60000 * Math.pow(2, Math.min(Math.max(0, farmasiNotifFailCount - 1), 3)));
                    scheduleFarmasiNotif(farmasiNotifFailCount ? backoff : base);
                }
            }

            document.addEventListener('DOMContentLoaded', () => {
                runFarmasiNotifOnce();
            });

            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    const pauseRemaining = Math.max(0, farmasiNotifPauseUntil - Date.now());
                    if (pauseRemaining > 0) {
                        scheduleFarmasiNotif(pauseRemaining);
                        return;
                    }
                    runFarmasiNotifOnce();
                }
            });

            window.addEventListener('online', () => {
                farmasiNotifFailCount = 0;
                runFarmasiNotifOnce();
            });
        </script>
        <script>
            function closeBirthdayTodayModal() {
                const modal = document.getElementById('birthdayTodayModal');
                if (!modal) {
                    return;
                }

                modal.classList.add('hidden');
                document.body.classList.remove('modal-open');
            }

            async function loadBirthdayTodayModal() {
                const modal = document.getElementById('birthdayTodayModal');
                const list = document.getElementById('birthdayTodayList');
                if (!modal || !list || !window.EMS_USER_ID) {
                    return;
                }

                const response = await safeFetchJSON(window.emsUrl('/actions/birthday_notices.php'));
                if (!response || !response.success || !Array.isArray(response.items) || response.items.length === 0) {
                    return;
                }

                const dateKey = String(response.date_key || '');
                const storageKey = 'ems-birthday-modal:' + String(window.EMS_USER_ID) + ':' + dateKey;
                if (window.localStorage && localStorage.getItem(storageKey) === '1') {
                    return;
                }

                list.innerHTML = response.items.map(function(item) {
                    const name = String(item.name || 'Medis');
                    const position = String(item.position || '-');
                    const division = String(item.division || '-');
                    const zodiac = String(item.zodiac || '-');
                    const birthdayLabel = String(item.birthday_label || '-');
                    const age = Number(item.age || 0);
                    const turningAge = Number(item.turning_age || 0);
                    const ageLine = turningAge > 0
                        ? `Hari ini ulang tahun ke-${turningAge}.`
                        : (age > 0 ? `Usia saat ini ${age} tahun.` : 'Hari ini sedang berulang tahun.');
                    return `
                        <div class="card">
                            <div class="text-sm font-semibold text-slate-800">${name}</div>
                            <div class="meta-text-xs mt-1 text-slate-500">${position} • ${division}</div>
                            <div class="mt-2 text-sm text-slate-700">${birthdayLabel}</div>
                            <div class="mt-1 text-sm text-slate-700">${ageLine} Nuansa zodiaknya ${zodiac}.</div>
                        </div>
                    `;
                }).join('');

                const rememberSeen = function() {
                    if (window.localStorage) {
                        localStorage.setItem(storageKey, '1');
                    }
                };

                modal.classList.remove('hidden');
                document.body.classList.add('modal-open');

                modal.addEventListener('click', function(event) {
                    if (event.target === modal) {
                        rememberSeen();
                        closeBirthdayTodayModal();
                    }
                }, { once: true });

                const originalClose = window.closeBirthdayTodayModal;
                window.closeBirthdayTodayModal = function() {
                    rememberSeen();
                    originalClose();
                    window.closeBirthdayTodayModal = originalClose;
                };
            }

            document.addEventListener('DOMContentLoaded', function() {
                loadBirthdayTodayModal();
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
                if (document.hidden) {
                    return;
                }

                try {
                    const data = await safeFetchJSON(window.emsUrl('/actions/heartbeat.php'), {
                        method: 'POST',
                        cache: 'no-store'
                    });

                    if (!data) {
                        return;
                    }

                    // Jika user sudah offline
                    if (!data.active) {
                        stopHeartbeat();

                        // Hapus modal konfirmasi jika masih ada
                        removeModal();
                    }

                } catch (e) {
                    window.emsLogOnce('heartbeat-failed', 'Heartbeat sementara gagal dikirim.', e && e.message ? e.message : e);
                }
            }

            /**
             * Mulai heartbeat
             */
            function startHeartbeat() {
                if (heartbeatTimer) return;

                // Setiap 30 detik, dan hanya saat tab aktif
                heartbeatTimer = setInterval(sendHeartbeat, 30000);
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
            const inboxMarkAllBtn = document.getElementById('inboxMarkAllBtn');
            const inboxDeleteAllBtn = document.getElementById('inboxDeleteAllBtn');

            if (inboxBtn && inboxDropdown && inboxList && inboxBadge) {
                inboxBtn.addEventListener('click', () => {
                    inboxDropdown.classList.toggle('hidden');
                    loadInbox();
                });
            }

            async function loadInbox() {
                const data = await safeFetchJSON(window.emsUrl('/actions/get_inbox.php'));
                if (!inboxList || !inboxBadge) return;

                if (!data) {
                    inboxList.innerHTML = '<li style="padding:12px;color:#888">Inbox tidak bisa dimuat</li>';
                    return;
                }

                inboxBadge.textContent = data.unread;
                inboxBadge.style.display = data.unread > 0 ? 'inline-block' : 'none';
                renderInboxList(data.items || []);
            }
        </script>
        <script>
            let currentInboxId = null;
            let currentInboxType = 'user_inbox';

            function openInboxModal(item) {
                const modal = document.getElementById('inboxModal');
                const deleteButton = modal ? modal.querySelector('.btn-danger') : null;

                currentInboxId = item.item_id || item.id;
                currentInboxType = item.source_type || 'user_inbox';

                document.getElementById('modalTitle').textContent = item.title;
                document.getElementById('modalMeta').textContent = [item.badge || '', item.created_at_label || ''].filter(Boolean).join(' • ');
                document.getElementById('modalMessage').innerHTML = item.message;
                if (deleteButton) {
                    deleteButton.textContent = item.delete_label || 'Hapus';
                }
                if (modal) {
                    modal.classList.remove('hidden');
                    document.body.classList.add('modal-open');
                }

                fetch(window.emsUrl('/actions/read_inbox.php'), {
                    method: 'POST',
                    body: new URLSearchParams({
                        item_id: String(item.item_id || item.id || ''),
                        source_type: String(item.source_type || 'user_inbox'),
                        csrf_token: String(window.EMS_CSRF_TOKEN || '')
                    })
                });

                loadInbox(); // refresh badge
            }

            function closeInboxModal() {
                const modal = document.getElementById('inboxModal');
                if (modal) {
                    modal.classList.add('hidden');
                }
                currentInboxId = null;
                currentInboxType = 'user_inbox';
                document.body.classList.remove('modal-open');
            }

            async function deleteInbox() {
                if (!currentInboxId) return;

                const response = await safeFetchJSON(window.emsUrl('/actions/delete_inbox.php'), {
                    method: 'POST',
                    body: new URLSearchParams({
                        item_id: String(currentInboxId),
                        source_type: String(currentInboxType || 'user_inbox'),
                        csrf_token: String(window.EMS_CSRF_TOKEN || '')
                    })
                });

                if (response && response.success) {
                    closeInboxModal();
                    loadInbox();
                }
            }

            async function markAllInboxRead() {
                const response = await safeFetchJSON(window.emsUrl('/actions/read_inbox.php'), {
                    method: 'POST',
                    body: new URLSearchParams({
                        bulk_action: 'mark_all',
                        csrf_token: String(window.EMS_CSRF_TOKEN || '')
                    })
                });

                if (response && response.success) {
                    loadInbox();
                }
            }

            async function deleteAllInbox() {
                if (!window.confirm('Yakin ingin menyembunyikan atau menghapus semua inbox?')) {
                    return;
                }

                const response = await safeFetchJSON(window.emsUrl('/actions/delete_inbox.php'), {
                    method: 'POST',
                    body: new URLSearchParams({
                        bulk_action: 'delete_all',
                        csrf_token: String(window.EMS_CSRF_TOKEN || '')
                    })
                });

                if (response && response.success) {
                    closeInboxModal();
                    loadInbox();
                }
            }

            inboxMarkAllBtn?.addEventListener('click', function(event) {
                event.preventDefault();
                event.stopPropagation();
                markAllInboxRead();
            });

            inboxDeleteAllBtn?.addEventListener('click', function(event) {
                event.preventDefault();
                event.stopPropagation();
                deleteAllInbox();
            });

            document.getElementById('inboxModal')?.addEventListener('click', function(event) {
                if (event.target === this) {
                    closeInboxModal();
                }
            });

            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closeInboxModal();
                }
            });
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
                if (!inboxBadge || !inboxDropdown || !inboxList) {
                    return;
                }

                try {
                    const data = await safeFetchJSON(window.emsUrl('/actions/get_inbox.php'));
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
                if (!inboxList) {
                    return;
                }

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
            <div style="font-weight:600">${item.title}</div>
            <small>${item.badge ? item.badge + ' • ' : ''}${item.created_at_label}</small>
        `;
                    li.onclick = () => openInboxModal(item);
                    inboxList.appendChild(li);
                });
            }

            /**
             * Event jika inbox baru masuk
             */
            function onNewInbox(count) {
                if (!inboxBadge) {
                    return;
                }

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
                if (!inboxBadge || !inboxDropdown || !inboxList) return;

                let inboxFailCount = 0;
                let inboxInFlight = false;

                const schedule = (ms) => {
                    if (inboxPollingTimer) clearTimeout(inboxPollingTimer);
                    inboxPollingTimer = setTimeout(runOnce, ms);
                };

                const runOnce = async () => {
                    const pauseRemaining = Math.max(0, (window.__emsInboxPauseUntil || 0) - Date.now());
                    if (pauseRemaining > 0) {
                        schedule(pauseRemaining);
                        return;
                    }

                    if (document.hidden) {
                        schedule(60000);
                        return;
                    }

                    if (inboxInFlight) return;
                    inboxInFlight = true;

                    const beforeFail = inboxFailCount;
                    try {
                        const data = await safeFetchJSON(window.emsUrl('/actions/get_inbox.php'));
                        if (!data) {
                            inboxFailCount++;
                            window.__emsInboxPauseUntil = Date.now() + 300000;
                            if (beforeFail === 0) {
                                window.emsLogOnce('inbox-backoff', 'Inbox sementara gagal dimuat, polling dibackoff.');
                            }
                            return;
                        }

                        inboxFailCount = 0;
                        window.__emsInboxPauseUntil = 0;

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
                        const base = 30000;
                        const backoff = Math.min(300000, 60000 * Math.pow(2, Math.min(Math.max(0, inboxFailCount - 1), 3)));
                        schedule(inboxFailCount ? backoff : base);
                    }
                };

                runOnce(); // langsung sekali saat load

                document.addEventListener('visibilitychange', () => {
                    if (!document.hidden) {
                        const pauseRemaining = Math.max(0, (window.__emsInboxPauseUntil || 0) - Date.now());
                        if (pauseRemaining > 0) {
                            schedule(pauseRemaining);
                            return;
                        }
                        inboxFailCount = 0;
                        runOnce();
                    }
                });

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

        <script src="<?= htmlspecialchars(ems_url('/public/push-subscribe.js'), ENT_QUOTES, 'UTF-8') ?>"></script>

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
