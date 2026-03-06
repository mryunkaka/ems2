<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../config/database.php';

/*
|--------------------------------------------------------------------------
| AMBIL EVENT AKTIF
|--------------------------------------------------------------------------
*/
$eventId = (int)($_GET['id'] ?? 0);

if ($eventId > 0) {
    // Kalau ada ID, pakai ID tersebut
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ? AND is_active = 1");
    $stmt->execute([$eventId]);
} else {
    // Kalau tidak ada ID, ambil event aktif pertama
    $stmt = $pdo->query("
        SELECT *
        FROM events
        WHERE is_active = 1
        ORDER BY tanggal_event ASC
        LIMIT 1
    ");
}

$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    die('Tidak ada event aktif.');
}

$eventId = (int)$event['id'];

/*
|--------------------------------------------------------------------------
| STATISTIK PESERTA
|--------------------------------------------------------------------------
*/
$statStmt = $pdo->prepare("
    SELECT
        COUNT(ep.id) AS total,
        SUM(CASE WHEN u.jenis_kelamin = 'Laki-laki' THEN 1 ELSE 0 END) AS laki,
        SUM(CASE WHEN u.jenis_kelamin = 'Perempuan' THEN 1 ELSE 0 END) AS perempuan
    FROM event_participants ep
    LEFT JOIN user_rh u ON u.id = ep.user_id
    WHERE ep.event_id = ?
");
$statStmt->execute([$eventId]);
$stat = $statStmt->fetch(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| FLASH MESSAGES
|--------------------------------------------------------------------------
*/
$messages = $_SESSION['flash_messages'] ?? [];
$errors   = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

/*
|--------------------------------------------------------------------------
| PROSES DAFTAR EVENT
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nama   = trim($_POST['nama_lengkap'] ?? '');
    $batch  = trim($_POST['batch'] ?? '');
    $gender = $_POST['jenis_kelamin'] ?? '';

    if ($nama === '' || $batch === '' || !in_array($gender, ['Laki-laki', 'Perempuan'], true)) {
        $_SESSION['flash_errors'][] = 'Semua data wajib diisi.';
        header("Location: events.php?id=$eventId");
        exit;
    }

    try {
        // Cari user berdasarkan nama
        $stmt = $pdo->prepare("
            SELECT id 
            FROM user_rh 
            WHERE full_name = ? 
            LIMIT 1
        ");
        $stmt->execute([$nama]);
        $userId = (int)($stmt->fetchColumn() ?: 0);

        // Buat user baru jika belum ada
        if ($userId === 0) {
        
            $defaultPin = '0000';
            $pinHash = password_hash($defaultPin, PASSWORD_BCRYPT, ['cost' => 12]);
        
            $stmt = $pdo->prepare("
                INSERT INTO user_rh (
                    full_name,
                    batch,
                    jenis_kelamin,
                    position,
                    role,
                    pin,
                    is_active
                ) VALUES (
                    ?, ?, ?, 'Trainee', 'Staff', ?, 1
                )
            ");
            $stmt->execute([$nama, $batch, $gender, $pinHash]);
            $userId = (int)$pdo->lastInsertId();
        
        } else {
        
            // UPDATE batch & gender jika user sudah ada
            $stmt = $pdo->prepare("
                UPDATE user_rh
                SET 
                    batch = ?,
                    jenis_kelamin = ?
                WHERE id = ?
            ");
            $stmt->execute([$batch, $gender, $userId]);
        }


        // Cek apakah nama sudah terdaftar di event ini
        $stmt = $pdo->prepare("
            SELECT 1
            FROM event_participants ep
            JOIN user_rh u ON u.id = ep.user_id
            WHERE ep.event_id = ?
            AND u.full_name = ?
            LIMIT 1
        ");
        $stmt->execute([$eventId, $nama]);

        if ($stmt->fetch()) {
            $_SESSION['flash_errors'][] = 'Nama ini sudah terdaftar di event.';
        } else {
            // Daftar ke event
            $stmt = $pdo->prepare("
                INSERT INTO event_participants (event_id, user_id)
                VALUES (?, ?)
            ");
            $stmt->execute([$eventId, $userId]);
            $_SESSION['flash_messages'][] = 'Pendaftaran event berhasil!';
        }
    } catch (Exception $e) {
        $_SESSION['flash_errors'][] = 'Terjadi kesalahan sistem.';
    }

    header("Location: events.php?id=$eventId");
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Daftar Event</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- EMS CSS -->
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/components.css">
</head>

<body>

    <div class="page" style="max-width:560px;margin:auto;">

        <h1 class="gradient-text">Daftar Event</h1>
        <p class="text-muted">Pendaftaran terbuka tanpa login</p>

        <?php foreach ($messages as $m): ?>
            <div class="alert alert-success"><?= htmlspecialchars($m) ?></div>
        <?php endforeach; ?>

        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>

        <!-- INFO EVENT -->
        <div class="card">
            <div class="card-header"><?= htmlspecialchars($event['nama_event']) ?></div>

            <?php
            $hariMap = [
                'Sunday'    => 'Minggu',
                'Monday'    => 'Senin',
                'Tuesday'   => 'Selasa',
                'Wednesday' => 'Rabu',
                'Thursday'  => 'Kamis',
                'Friday'    => 'Jumat',
                'Saturday'  => 'Sabtu',
            ];

            $dt = new DateTime($event['tanggal_event']);
            $hari = $hariMap[$dt->format('l')] ?? '';
            ?>

            <p class="text-muted">
                <?= $hari ?>, <?= $dt->format('d M Y') ?>
                • <?= htmlspecialchars($event['lokasi'] ?? '-') ?>
            </p>

            <div class="info-notice">
                <strong>Total Peserta:</strong> <?= (int)$stat['total'] ?> orang<br>
                <strong>Laki-laki:</strong> <?= (int)$stat['laki'] ?> orang<br>
                <strong>Perempuan:</strong> <?= (int)$stat['perempuan'] ?> orang
            </div>
        </div>

        <!-- FORM -->
        <div class="card">
            <div class="card-header">Form Pendaftaran</div>

            <form method="POST" class="form" autocomplete="off">

                <!-- NAMA + AUTOCOMPLETE -->
                <div class="row-form-1" style="position:relative;">
                    <label>Nama Lengkap <span class="required">*</span></label>

                    <input
                        type="text"
                        name="nama_lengkap"
                        id="namaInput"
                        placeholder="Ketik nama…"
                        required>

                    <!-- DROPDOWN AUTOCOMPLETE -->
                    <div id="namaDropdown" class="consumer-search-dropdown hidden"></div>

                    <small class="hint-info">
                        Jika nama belum ada, akun akan dibuat otomatis
                    </small>
                </div>

                <!-- BATCH -->
                <div class="row-form-1">
                    <label>Batch <span class="required">*</span></label>
                    <input type="text" name="batch" id="batchInput" required>
                </div>

                <!-- JENIS KELAMIN -->
                <div class="row-form-1">
                    <label>Jenis Kelamin <span class="required">*</span></label>
                    <select name="jenis_kelamin" id="genderSelect" required>
                        <option value="">-- Pilih --</option>
                        <option value="Laki-laki">Laki-laki</option>
                        <option value="Perempuan">Perempuan</option>
                    </select>
                </div>

                <div class="form-submit-wrapper">
                    <button class="btn btn-success btn-submit">
                        Daftar Event
                    </button>
                </div>

            </form>

            <script>
                document.addEventListener('DOMContentLoaded', () => {

                    const inputNama = document.getElementById('namaInput');
                    const dropdown = document.getElementById('namaDropdown');
                    const batchInput = document.getElementById('batchInput');
                    const genderInput = document.getElementById('genderSelect');

                    let controller = null;

                    inputNama.addEventListener('input', () => {
                        const keyword = inputNama.value.trim();

                        batchInput.value = '';
                        genderInput.value = '';

                        if (keyword.length < 2) {
                            dropdown.classList.add('hidden');
                            dropdown.innerHTML = '';
                            return;
                        }

                        if (controller) controller.abort();
                        controller = new AbortController();

                        fetch('../ajax/search_user_rh.php?q=' + encodeURIComponent(keyword), {
                                signal: controller.signal
                            })
                            .then(res => res.json())
                            .then(data => {

                                dropdown.innerHTML = '';

                                if (!data.length) {
                                    dropdown.classList.add('hidden');
                                    return;
                                }

                                data.forEach(user => {
                                    const item = document.createElement('div');
                                    item.className = 'consumer-search-item';

                                    item.innerHTML = `
                    <div class="consumer-search-name">${user.full_name}</div>
                    <div class="consumer-search-meta">
                        <span>${user.position ?? '-'}</span>
                        <span class="dot">•</span>
                        <span>Batch ${user.batch ?? '-'}</span>
                    </div>
                `;

                                    item.addEventListener('click', () => {
                                        inputNama.value = user.full_name;
                                        batchInput.value = user.batch ?? '';
                                        genderInput.value = user.jenis_kelamin ?? '';

                                        dropdown.classList.add('hidden');
                                        dropdown.innerHTML = '';
                                    });

                                    dropdown.appendChild(item);
                                });

                                dropdown.classList.remove('hidden');
                            })
                            .catch(() => {});
                    });

                    document.addEventListener('click', (e) => {
                        if (!inputNama.contains(e.target) && !dropdown.contains(e.target)) {
                            dropdown.classList.add('hidden');
                        }
                    });

                });
            </script>


        </div>

    </div>

</body>

</html>