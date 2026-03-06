<?php
session_start();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>Farmasi EMS - Login</title>

    <!-- CSS LOAD ORDER (PENTING!) -->
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/login.css">
</head>

<body>

    <div class="login-wrapper">
        <div class="login-card">

            <!-- BRAND / LOGO -->
            <div class="brand">
                <img src="/assets/logo.png" alt="Farmasi EMS Logo">
                <h2>ROXWOOD HOSPITAL</h2>
                <p>Emergency Medical System</p>
            </div>

            <!-- NOTIFIKASI ERROR -->
            <?php if (isset($_SESSION['error'])): ?>
                <div class="notif error">
                    <?= htmlspecialchars($_SESSION['error']) ?>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <!-- NOTIFIKASI SUCCESS -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="notif success">
                    <?= htmlspecialchars($_SESSION['success']) ?>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <!-- ============================================
                 LOGIN FORM
                 ============================================ -->
            <form id="loginForm" method="POST" action="/auth/login_process.php">
                <?php if (isset($_GET['confirm'])): ?>
                    <div class="modal-overlay" id="confirmModal">
                        <div class="modal-card">
                            <h4>⚠️ Login di Device Lain</h4>
                            <p>
                                Akun ini sedang aktif di device lain.<br>
                                Jika Anda melanjutkan, maka device sebelumnya akan otomatis logout.
                            </p>

                            <div class="modal-actions">
                                <a href="login.php" class="btn-cancel">Batal</a>

                                <button
                                    type="submit"
                                    name="force_login"
                                    value="1"
                                    class="btn-confirm"
                                    formnovalidate>
                                    Login di device ini
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <h3>Login</h3>

                <input
                    type="text"
                    name="full_name"
                    placeholder="Full Name"
                    required
                    autocomplete="username"
                    autocorrect="off"
                    autocapitalize="words">

                <input
                    type="password"
                    name="pin"
                    placeholder="4 Digit PIN"
                    maxlength="4"
                    pattern="[0-9]{4}"
                    inputmode="numeric"
                    required
                    autocomplete="current-password">

                <button type="submit">Login</button>

                <p class="switch">
                    Belum punya akun?
                    <a href="javascript:void(0)" onclick="showRegister()">Daftar</a>
                </p>
            </form>

            <!-- ============================================
                 REGISTER FORM
                 ============================================ -->
            <form id="registerForm"
                method="POST"
                action="/auth/register_process.php"
                class="hidden"
                enctype="multipart/form-data">
                <h3>Register</h3>

                <input
                    type="text"
                    name="full_name"
                    placeholder="Full Name"
                    required
                    autocomplete="name"
                    autocorrect="off"
                    autocapitalize="words">

                <input
                    type="password"
                    name="pin"
                    placeholder="4 Digit PIN"
                    maxlength="4"
                    pattern="[0-9]{4}"
                    inputmode="numeric"
                    required
                    autocomplete="new-password">

                <input
                    type="number"
                    name="batch"
                    placeholder="Batch"
                    min="1"
                    max="26"
                    required>

                <hr>
                <h4>Data Pribadi</h4>

                <input
                    type="text"
                    name="citizen_id"
                    placeholder="Citizen ID (contoh: RH39IQLC)"
                    required>

                <input
                    type="number"
                    name="no_hp_ic"
                    placeholder="No HP IC"
                    required>

                <select name="jenis_kelamin" required>
                    <option value="">-- Jenis Kelamin --</option>
                    <option value="Laki-laki">Laki-laki</option>
                    <option value="Perempuan">Perempuan</option>
                </select>

                <hr>
                <h4>Dokumen Identitas</h4>

                <label>KTP <small style="color:red;">(Wajib)</small></label>
                <input type="file"
                    name="file_ktp"
                    accept="image/png,image/jpeg"
                    required>

                <label>SKB <small style="color:red;">(Wajib)</small></label>
                <input type="file"
                    name="file_skb"
                    accept="image/png,image/jpeg"
                    required>

                <label>SIM <small>(Opsional)</small></label>
                <input type="file"
                    name="file_sim"
                    accept="image/png,image/jpeg">

                <select name="role" required>
                    <option value="Staff">Staff</option>
                    <option value="Staff Manager">Staff Manager</option>
                    <option value="Manager">Manager</option>
                    <option value="Vice Director">Vice Director</option>
                    <option value="Director">Director</option>
                </select>

                <button type="submit">Daftar</button>

                <p class="switch">
                    Sudah punya akun?
                    <a href="javascript:void(0)" onclick="showLogin()">Login</a>
                </p>
            </form>

        </div>
    </div>

    <script>
        // ============================================
        // TOGGLE LOGIN / REGISTER FORM
        // ============================================
        function showRegister() {
            document.getElementById('loginForm').classList.add('hidden');
            document.getElementById('registerForm').classList.remove('hidden');
        }

        function showLogin() {
            document.getElementById('registerForm').classList.add('hidden');
            document.getElementById('loginForm').classList.remove('hidden');
        }

        // ============================================
        // PREVENT DOUBLE-TAP ZOOM (iOS)
        // ============================================
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function(event) {
            const now = (new Date()).getTime();
            if (now - lastTouchEnd <= 300) {
                event.preventDefault();
            }
            lastTouchEnd = now;
        }, false);

        // ============================================
        // AUTO-HIDE NOTIFICATIONS
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            const notifications = document.querySelectorAll('.notif');
            notifications.forEach(function(notif) {
                setTimeout(function() {
                    notif.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    notif.style.opacity = '0';
                    notif.style.transform = 'translateY(-10px)';
                    setTimeout(function() {
                        notif.remove();
                    }, 500);
                }, 5000); // Hilang setelah 5 detik
            });
        });

        // ============================================
        // FORM VALIDATION ENHANCEMENT
        // ============================================
        const loginForm = document.getElementById('loginForm');
        const registerForm = document.getElementById('registerForm');

        // Validasi PIN harus 4 digit angka
        function validatePIN(input) {
            input.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
                if (this.value.length > 4) {
                    this.value = this.value.slice(0, 4);
                }
            });
        }

        // Apply validasi ke semua input PIN
        document.querySelectorAll('input[name="pin"]').forEach(validatePIN);

        // Prevent form resubmission
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>

</html>