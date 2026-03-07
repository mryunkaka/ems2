<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

/* ===============================
   ROLE GUARD (MANAGER ONLY)
   =============================== */
$role = strtolower($_SESSION['user_rh']['role'] ?? '');
if ($role === 'staff') {
    header('Location: events.php');
    exit;
}

/* ===============================
   VALIDASI EVENT
   =============================== */
$eventId = (int)($_GET['event_id'] ?? 0);
if (!$eventId) {
    header('Location: event_manage.php');
    exit;
}

/* ===============================
   DATA EVENT
   =============================== */
$stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
$stmt->execute([$eventId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    header('Location: event_manage.php');
    exit;
}

/* ===============================
   DATA PESERTA
   =============================== */
$stmt = $pdo->prepare("
    SELECT 
        u.id AS user_id,
        u.full_name,
        u.position,
        u.batch,
        u.jenis_kelamin,
        u.citizen_id,
        u.no_hp_ic,
        ep.registered_at
    FROM event_participants ep
    JOIN user_rh u ON u.id = ep.user_id
    WHERE ep.event_id = ?
    ORDER BY ep.registered_at ASC
");
$stmt->execute([$eventId]);
$participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   GENERATE & SIMPAN KELOMPOK (FINAL)
   TANPA SISTEM KUNCI
   =============================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_group'])) {

    $groupSize  = max(2, (int)$_POST['group_size']);
    $maleNeed   = max(0, (int)$_POST['male_count']);
    $femaleNeed = max(0, (int)$_POST['female_count']);

    // validasi kapasitas
    if (($maleNeed + $femaleNeed) > $groupSize) {
        $_SESSION['flash_errors'][] = 'Jumlah laki-laki + perempuan melebihi kapasitas kelompok.';
        header("Location: event_participants.php?event_id={$eventId}");
        exit;
    }

    /* ===============================
       RESET KELOMPOK LAMA (JIKA ADA)
       =============================== */
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("SELECT id FROM event_groups WHERE event_id = ?");
        $stmt->execute([$eventId]);
        $groupIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($groupIds)) {
            $in = implode(',', array_fill(0, count($groupIds), '?'));

            // hapus member
            $pdo->prepare("
                DELETE FROM event_group_members 
                WHERE event_group_id IN ($in)
            ")->execute($groupIds);

            // hapus group
            $pdo->prepare("
                DELETE FROM event_groups 
                WHERE id IN ($in)
            ")->execute($groupIds);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $_SESSION['flash_errors'][] = 'Gagal menghapus kelompok lama.';
        header("Location: event_participants.php?event_id={$eventId}");
        exit;
    }

    /* ===============================
       GENERATE KELOMPOK BARU
       =============================== */
    $pool = $participants;
    shuffle($pool);

    $groups = [];
    $groupIndex = 1;

    while (!empty($pool)) {

        $group = [];
        $usedBatch = [];

        // 1️⃣ perempuan
        for ($i = 0; $i < $femaleNeed; $i++) {
            foreach ($pool as $k => $p) {
                if ($p['jenis_kelamin'] === 'Perempuan') {
                    $group[] = $p;
                    $usedBatch[] = $p['batch'];
                    unset($pool[$k]);
                    break;
                }
            }
        }

        // 2️⃣ laki-laki
        for ($i = 0; $i < $maleNeed; $i++) {
            foreach ($pool as $k => $p) {
                if ($p['jenis_kelamin'] === 'Laki-laki') {
                    $group[] = $p;
                    $usedBatch[] = $p['batch'];
                    unset($pool[$k]);
                    break;
                }
            }
        }

        // 3️⃣ beda batch
        foreach ($pool as $k => $p) {
            if (count($group) >= $groupSize) break;
            if (!in_array($p['batch'], $usedBatch, true)) {
                $group[] = $p;
                $usedBatch[] = $p['batch'];
                unset($pool[$k]);
            }
        }

        // 4️⃣ fallback
        foreach ($pool as $k => $p) {
            if (count($group) >= $groupSize) break;
            $group[] = $p;
            unset($pool[$k]);
        }

        if (!empty($group)) {
            $groups['Kelompok ' . $groupIndex] = $group;
            $groupIndex++;
        }
    }

    /* ===============================
       SIMPAN KE DATABASE
       =============================== */
    $pdo->beginTransaction();

    try {
        foreach ($groups as $groupName => $members) {

            $stmt = $pdo->prepare("
                INSERT INTO event_groups (event_id, group_name)
                VALUES (?, ?)
            ");
            $stmt->execute([$eventId, $groupName]);

            $groupId = $pdo->lastInsertId();

            $stmtMember = $pdo->prepare("
                INSERT INTO event_group_members (event_group_id, user_id)
                VALUES (?, ?)
            ");

            foreach ($members as $m) {
                $stmtMember->execute([$groupId, $m['user_id']]);
            }
        }

        $pdo->commit();
        $_SESSION['flash_messages'][] = 'Kelompok berhasil digenerate ulang.';
    } catch (Throwable $e) {
        $pdo->rollBack();
        $_SESSION['flash_errors'][] = 'Gagal menyimpan kelompok.';
    }

    header("Location: event_participants.php?event_id={$eventId}");
    exit;
}


/* ===============================
   AMBIL KELOMPOK DARI DATABASE
   =============================== */
$stmt = $pdo->prepare("
    SELECT 
        g.group_name,
        u.full_name,
        u.position,
        u.jenis_kelamin,
        u.batch
    FROM event_groups g
    JOIN event_group_members gm ON gm.event_group_id = g.id
    JOIN user_rh u ON u.id = gm.user_id
    WHERE g.event_id = ?
    ORDER BY g.id, u.full_name
");
$stmt->execute([$eventId]);

$dbGroups = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $dbGroups[$row['group_name']][] = $row;
}

$pageTitle = 'Peserta Event';
?>

<?php include __DIR__ . '/../partials/header.php'; ?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<section class="content">
    <div class="page page-shell">

        <h1 class="page-title">Peserta Event</h1>
        <p class="page-subtitle"><?= htmlspecialchars((string)($event['nama_event'] ?? '')) ?></p>

        <div class="card">
            <div class="card-header">
                Daftar Peserta Pendaftaran
                <span class="header-meta">
                    Total: <strong><?= count($participants) ?></strong> orang
                </span>
            </div>

            <div class="table-wrapper">
                <table class="table-custom datatable-peserta">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nama</th>
                            <th>Jabatan</th>
                            <th>Batch</th>
                            <th>Jenis Kelamin</th>
                            <th>Citizen ID</th>
                            <th>No HP IC</th>
                            <th>Waktu Daftar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($participants as $i => $p): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= htmlspecialchars($p['full_name']) ?></strong></td>
                                <td><?= htmlspecialchars(ems_position_label($p['position'])) ?></td>
                                <td><?= htmlspecialchars((string)($p['batch'] ?? '')) ?></td>
                                <td><?= htmlspecialchars($p['jenis_kelamin']) ?></td>
                                <td><?= htmlspecialchars((string)($p['citizen_id'] ?? '')) ?></td>
                                <td><?= htmlspecialchars((string)($p['no_hp_ic'] ?? '')) ?></td>
                                <td><?= (new DateTime($p['registered_at']))->format('d M Y H:i') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="card-header">Generate Kelompok</div>
            <div class="card-body">
                <div class="alert alert-info">
                    Pastikan daftar peserta sudah final sebelum generate kelompok.
                </div>
                <form method="POST">

                    <label>Jumlah Orang / Kelompok</label>
                    <input type="number" name="group_size" min="2" required>

                    <label>Laki-laki / Kelompok</label>
                    <div class="radio-group">
                        <?php for ($i = 0; $i <= 10; $i++): ?>
                            <label><input type="radio" name="male_count" value="<?= $i ?>" <?= $i === 0 ? 'checked' : '' ?>> <?= $i ?></label>
                        <?php endfor; ?>
                    </div>

                    <label>Perempuan / Kelompok</label>
                    <div class="radio-group">
                        <?php for ($i = 0; $i <= 10; $i++): ?>
                            <label><input type="radio" name="female_count" value="<?= $i ?>" <?= $i === 0 ? 'checked' : '' ?>> <?= $i ?></label>
                        <?php endfor; ?>
                    </div>

                    <button type="submit" name="generate_group" class="btn-success mt-3">
                        <?= ems_icon('users', 'h-4 w-4') ?> <span>Generate Kelompok</span>
                    </button>

                </form>
            </div>
        </div>

        <?php if (!empty($dbGroups)): ?>
            <div class="export-panel">
                <div class="export-panel-box">
                    <button id="btnExportGroupText"
                        class="btn-secondary">
                        <?= ems_icon('document-text', 'h-4 w-4') ?> <span>Export Kelompok</span>
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($dbGroups as $groupName => $members): ?>
            <div class="card card-section">
                <div class="card-header"><?= $groupName ?></div>
                <div class="table-wrapper">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Nama</th>
                                <th>Jabatan</th>
                                <th>Gender</th>
                                <th>Batch</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $i => $m): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td><strong><?= htmlspecialchars($m['full_name']) ?></strong></td>
                                    <td><?= htmlspecialchars((string)($m['position'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars($m['jenis_kelamin']) ?></td>
                                    <td><?= htmlspecialchars((string)($m['batch'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>

        <a href="event_manage.php" class="btn-secondary mt-4"><?= ems_icon('arrow-left', 'h-4 w-4') ?> <span>Kembali</span></a>

    </div>
</section>

<script>
    document.addEventListener('DOMContentLoaded', function() {

        if (window.jQuery && $.fn.DataTable) {

            $('.datatable-peserta').DataTable({
                pageLength: 10,
                lengthMenu: [10, 25, 50, 100],
                ordering: true,
                searching: true,
                info: true,
                autoWidth: false,
                scrollX: true,
                order: [
                    [7, 'desc']
                ], // Waktu daftar DESC

                language: {
                    url: '/assets/design/js/datatables-id.json'
                },

                columnDefs: [{
                        targets: 0,
                        width: '40px',
                        className: 'text-center'
                    },
                    {
                        targets: [4],
                        className: 'text-center'
                    }, // gender
                    {
                        targets: [6],
                        className: 'text-nowrap'
                    } // no hp
                ]
            });

        } else {
            console.warn('DataTables atau jQuery belum ter-load');
        }

    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {

        const btn = document.getElementById('btnExportGroupText');
        if (!btn) return;

        btn.addEventListener('click', function() {

            let output = '';
            const eventNameRaw = <?= json_encode($event['nama_event']) ?>;

            // ===============================
            // FORMAT NAMA EVENT (AMAN UNTUK FILE)
            // ===============================
            const safeEventName = eventNameRaw
                .toLowerCase()
                .replace(/[^a-z0-9]+/gi, '_')
                .replace(/^_|_$/g, '');

            // ===============================
            // TIMESTAMP SAMPAI DETIK
            // ===============================
            const now = new Date();
            const pad = n => String(n).padStart(2, '0');

            const timestamp =
                now.getFullYear() + '-' +
                pad(now.getMonth() + 1) + '-' +
                pad(now.getDate()) + '_' +
                pad(now.getHours()) + '-' +
                pad(now.getMinutes()) + '-' +
                pad(now.getSeconds());

            const filename = `kelompok_${safeEventName}_${timestamp}.txt`;

            // ===============================
            // HEADER FILE
            // ===============================
            output += `EVENT: ${eventNameRaw}\n`;
            output += '==============================\n\n';

            // LOOP KELOMPOK
            document.querySelectorAll('.card').forEach(card => {

                const header = card.querySelector('.card-header');
                const table = card.querySelector('table');
                if (!header || !table) return;

                const groupName = header.innerText.trim();
                let rows = Array.from(table.querySelectorAll('tbody tr'));
                if (!rows.length) return;

                // perempuan di atas
                const perempuan = [];
                const lainnya = [];

                rows.forEach(row => {
                    const gender = row.querySelector('td:nth-child(4)')?.innerText || '';
                    if (gender.toLowerCase() === 'perempuan') {
                        perempuan.push(row);
                    } else {
                        lainnya.push(row);
                    }
                });

                rows = [...perempuan, ...lainnya];

                output += groupName.toUpperCase() + '\n';

                let no = 1;
                rows.forEach(row => {
                    const nama = row.querySelector('td:nth-child(2) strong')?.innerText || '';
                    if (nama) {
                        output += `${no}. ${nama}\n`;
                        no++;
                    }
                });

                output += '\n';
            });

            if (!output.trim()) {
                alert('Tidak ada data kelompok untuk diexport.');
                return;
            }

            // ===============================
            // DOWNLOAD FILE
            // ===============================
            const blob = new Blob([output], {
                type: 'text/plain;charset=utf-8;'
            });
            const url = URL.createObjectURL(blob);

            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();

            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });

    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
