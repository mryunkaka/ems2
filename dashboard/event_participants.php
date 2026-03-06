<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

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
       🔥 RESET KELOMPOK LAMA (JIKA ADA)
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

<style>
    .radio-group {
        display: flex;
        flex-wrap: wrap;
        gap: 10px
    }

    .radio-group label {
        background: #f1f5f9;
        padding: 6px 10px;
        border-radius: 6px;
        cursor: pointer
    }
</style>

<section class="content">
    <div class="page" style="max-width:1200px;margin:auto;">

        <h1 class="gradient-text">Peserta Event</h1>
        <p class="text-muted"><?= htmlspecialchars((string)($event['nama_event'] ?? '')) ?></p>

        <div class="card">
            <div class="card-header">
                Daftar Peserta Pendaftaran
                <span style="float:right;font-weight:normal;">
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
                                <td><?= htmlspecialchars($p['position']) ?></td>
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

                    <button type="submit" name="generate_group" class="btn-success" style="margin-top:12px;">
                        🎲 Generate Kelompok
                    </button>

                </form>
            </div>
        </div>

        <?php if (!empty($dbGroups)): ?>
            <div style="
        margin:20px 0;
        display:flex;
        justify-content:flex-end;
    ">
                <div style="
            background:#f8fafc;
            padding:8px 12px;
            border-radius:8px;
            box-shadow:0 1px 2px rgba(0,0,0,0.05);
        ">
                    <button id="btnExportGroupText"
                        class="btn-secondary"
                        style="display:flex;align-items:center;gap:6px;">
                        📄 Export Kelompok
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($dbGroups as $groupName => $members): ?>
            <div class="card" style="margin-top:20px;">
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

        <a href="event_manage.php" class="btn-secondary" style="margin-top:16px;">⬅ Kembali</a>

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
                    url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/id.json'
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
            console.warn('❌ DataTables atau jQuery belum ter-load');
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