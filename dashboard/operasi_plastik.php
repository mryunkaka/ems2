<?php
session_start();
date_default_timezone_set('Asia/Jakarta');

function tanggalIndo($date)
{
    if (!$date) return '-';

    $bulan = [
        1 => 'Januari',
        'Februari',
        'Maret',
        'April',
        'Mei',
        'Juni',
        'Juli',
        'Agustus',
        'September',
        'Oktober',
        'November',
        'Desember'
    ];

    $exp = explode('-', $date);
    return (int) $exp[2] .
        ' ' . $bulan[(int) $exp[1]] .
        ' ' . $exp[0];
}

/*
|--------------------------------------------------------------------------
| HARD GUARD
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';

/*
|--------------------------------------------------------------------------
| USER SESSION
|--------------------------------------------------------------------------
*/
$userSession = $_SESSION['user_rh'] ?? [];
$userId = (int)($userSession['id'] ?? 0);

if ($userId <= 0) {
    header('Location: ../auth/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| DATA MEDIS LOGIN
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT full_name, position, file_ktp, file_kta
    FROM user_rh
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$medicName = $user['full_name'] ?? '';
$medicPos  = $user['position'] ?? '';

/*
|--------------------------------------------------------------------------
| RIWAYAT OPERASI PLASTIK (HANYA DATA DIA)
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare(" 
    SELECT 
        op.id,
        op.tanggal,
        op.jenis_operasi,
        op.alasan,
        op.status,
        op.approved_at,
        u.full_name AS nama_medis,
        pj.full_name AS penanggung_jawab,
        ap.full_name AS approved_by_name
    FROM medic_operasi_plastik op
    JOIN user_rh u ON u.id = op.id_user
    JOIN user_rh pj ON pj.id = op.id_penanggung_jawab
    LEFT JOIN user_rh ap ON ap.id = op.approved_by
    ORDER BY op.created_at DESC
");
$stmt->execute();
$riwayat = $stmt->fetchAll(PDO::FETCH_ASSOC);
/*
|--------------------------------------------------------------------------
| BATAS OPERASI PLASTIK (25 HARI SEKALI)
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT tanggal
    FROM medic_operasi_plastik
    WHERE id_user = ?
    ORDER BY tanggal DESC
    LIMIT 1
");
$stmt->execute([$userId]);
$lastTanggal = $stmt->fetchColumn();

$sisaHari = 0;
$bolehInput = true;

if ($lastTanggal) {
    $lastDate = new DateTime($lastTanggal);
    $today    = new DateTime(date('Y-m-d'));

    $diffHari = $today->diff($lastDate)->days;

    if ($diffHari < 25) {
        $bolehInput = false;
        $sisaHari   = 25 - $diffHari;
    }
}

/*
|--------------------------------------------------------------------------
| DATA PENANGGUNG JAWAB (MIN CO.AST)
|--------------------------------------------------------------------------
*/
$pjStmt = $pdo->query("
    SELECT id, full_name, position
    FROM user_rh
    WHERE position IN ('(Co.Ast)', 'Dokter Umum', 'Dokter Spesialis')
    ORDER BY full_name ASC
");
$penanggungJawab = $pjStmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| FLASH MESSAGE
|--------------------------------------------------------------------------
*/
$messages = $_SESSION['flash_messages'] ?? [];
$errors   = $_SESSION['flash_errors'] ?? [];

unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);
?>

<?php include __DIR__ . '/../partials/header.php'; ?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<section class="content">
    <div class="page" style="max-width:900px;margin:auto;">

        <h1>Operasi Plastik</h1>

        <!-- NOTIF -->
        <?php foreach ($messages as $m): ?>
            <div class="alert alert-info"><?= htmlspecialchars($m) ?></div>
        <?php endforeach; ?>

        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>

        <!-- ===============================
        FORM INPUT OPERASI PLASTIK
        =============================== -->
        <div class="card">
            <div class="card-header card-header-flex">
                <span>Input Operasi Plastik</span>

                <div class="operasi-status">
                    <?php if (!$bolehInput): ?>
                        <span class="badge-danger badge-operasi">
                            ⏳ <?= $sisaHari ?> hari lagi
                        </span>
                    <?php else: ?>
                        <span class="badge-success-mini badge-operasi">
                            ✅ Sudah bisa
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <form method="POST" action="operasi_plastik_action.php" class="form">

                <label>Nama Medis</label>
                <input type="text"
                    value="<?= htmlspecialchars($medicName) ?>"
                    readonly
                    style="background:#f5f5f5;cursor:not-allowed;">

                <label>Tanggal</label>
                <input type="date"
                    name="tanggal"
                    value="<?= date('Y-m-d') ?>"
                    required>

                <label>Jenis Operasi Plastik</label>
                <select name="jenis_operasi" required>
                    <option value="">-- Pilih Jenis Operasi --</option>
                    <option value="Rekonstruksi Wajah">Rekonstruksi Wajah</option>
                    <option value="Suntik Putih">Suntik Putih</option>
                </select>

                <div class="ems-form-group">
                    <label>Yang Menangani</label>
                    <input type="text"
                        id="pjSearch"
                        placeholder="Cari nama Yang Menangani..."
                        autocomplete="off"
                        required>

                    <input type="hidden"
                        name="id_penanggung_jawab"
                        id="pjId">

                    <div id="pjSuggestion" class="ems-suggestion-box">
                        <?php foreach ($penanggungJawab as $pj): ?>
                            <div class="medic-suggestion-item"
                                data-id="<?= $pj['id'] ?>">
                                <?= htmlspecialchars($pj['full_name']) ?>
                                <small style="color:#64748b;">
                                    (<?= htmlspecialchars($pj['position']) ?>)
                                </small>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <small>Minimal jabatan (Co.Ast)</small>
                </div>

                <label>Alasan</label>
                <textarea name="alasan" rows="4" required></textarea>

                <button type="submit"
                    class="btn-primary"
                    <?= !$bolehInput ? 'disabled' : '' ?>>
                    Simpan Data Operasi
                </button>
                <?php if (!$bolehInput): ?>
                    <small style="color:#b91c1c;font-weight:600;">
                        Anda harus menunggu <?= $sisaHari ?> hari lagi untuk operasi plastik berikutnya.
                    </small>
                <?php endif; ?>

            </form>
        </div>

        <!-- ===============================
        RIWAYAT OPERASI PLASTIK
        =============================== -->
        <div class="card">
            <div class="card-header-actions" style="margin-bottom:20px;">
                <div class="card-header-actions-title">
                    Riwayat Operasi Plastik
                </div>
            </div>

            <div class="table-wrapper">
                <table id="operasiPlastikTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Tanggal</th>
                            <th>Nama Medis</th>
                            <th>Jenis Operasi</th>
                            <th>Yang Menangani</th>
                            <th>Status</th>
                            <th>Approved By</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($riwayat as $i => $row): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>

                                <td><?= tanggalIndo($row['tanggal']) ?></td>

                                <td>
                                    <strong><?= htmlspecialchars($row['nama_medis']) ?></strong>
                                </td>

                                <td><?= htmlspecialchars($row['jenis_operasi']) ?></td>

                                <td><?= htmlspecialchars($row['penanggung_jawab']) ?></td>

                                <td>
                                    <?php if ($row['status'] === 'approved'): ?>
                                        <span class="status-pill status-approved">
                                            ✅ Approved
                                        </span>
                                    <?php elseif ($row['status'] === 'rejected'): ?>
                                        <span class="status-pill status-rejected">
                                            ❌ Rejected
                                        </span>
                                    <?php else: ?>
                                        <span class="status-pill status-pending">
                                            ⏳ Pending
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($row['approved_by_name']): ?>
                                        <strong><?= htmlspecialchars($row['approved_by_name']) ?></strong>
                                        <div style="font-size:11px;color:#64748b;">
                                            <?= tanggalIndo(date('Y-m-d', strtotime($row['approved_at']))) ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;">-</span>
                                    <?php endif; ?>
                                </td>

                                <td style="white-space:nowrap;text-align:center;">
                                    <?php
                                    $role     = strtolower($_SESSION['user_rh']['role'] ?? '');
                                    $position = strtolower($medicPos ?? '');

                                    $bolehAlasan =
                                        $row['status'] === 'pending' &&
                                        (
                                            $role === 'manager' ||
                                            !in_array($position, ['trainee', 'paramedic'], true)
                                        );
                                    ?>

                                    <?php if ($bolehAlasan): ?>
                                        <button
                                            type="button"
                                            class="btn btn-warning btn-sm btn-alasan"
                                            data-id="<?= $row['id'] ?>"
                                            data-nama="<?= htmlspecialchars($row['nama_medis']) ?>"
                                            data-alasan="<?= htmlspecialchars($row['alasan']) ?>">
                                            Alasan
                                        </button>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (!window.jQuery || !jQuery.fn.DataTable) return;

            jQuery('#operasiPlastikTable').DataTable({
                pageLength: 10,
                order: [
                    [1, 'desc']
                ], // sort tanggal
                searching: true,
                lengthChange: false,
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/id.json'
                }
            });
        });
    </script>
    <script>
        let currentOperasiId = null;

        document.addEventListener('DOMContentLoaded', () => {

            document.querySelectorAll('.btn-alasan').forEach(btn => {
                btn.addEventListener('click', () => {

                    currentOperasiId = btn.dataset.id;

                    const nama = btn.dataset.nama || '-';
                    const alasan = btn.dataset.alasan || '-';

                    document.getElementById('modalTitle').textContent =
                        'Alasan Operasi - ' + nama;

                    document.getElementById('modalContent').textContent = alasan;

                    document.getElementById('modalAlasan').classList.remove('hidden');
                });
            });

            document.getElementById('btnApprove').onclick = () => submitOperasiAction('approve');
            document.getElementById('btnReject').onclick = () => submitOperasiAction('reject');
        });

        function closeAlasanModal() {
            document.getElementById('modalAlasan').classList.add('hidden');
            currentOperasiId = null;
        }

        function submitOperasiAction(action) {
            if (!currentOperasiId) return;

            if (!confirm('Yakin ingin ' + action.toUpperCase() + '?')) return;

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'operasi_plastik_action.php';

            form.innerHTML = `
        <input type="hidden" name="action" value="${action}">
        <input type="hidden" name="id" value="${currentOperasiId}">
    `;

            document.body.appendChild(form);
            form.submit();
        }
    </script>

    <script>
        const pjSearch = document.getElementById('pjSearch');
        const pjBox = document.getElementById('pjSuggestion');
        const pjId = document.getElementById('pjId');

        pjSearch.addEventListener('focus', () => pjBox.style.display = 'block');

        pjSearch.addEventListener('input', () => {
            const q = pjSearch.value.toLowerCase();
            pjBox.querySelectorAll('.medic-suggestion-item').forEach(item => {
                item.style.display =
                    item.textContent.toLowerCase().includes(q) ?
                    'block' :
                    'none';
            });
        });

        pjBox.addEventListener('click', e => {
            const item = e.target.closest('.medic-suggestion-item');
            if (!item) return;

            pjSearch.value = item.textContent.trim();
            pjId.value = item.dataset.id;
            pjBox.style.display = 'none';
        });

        document.addEventListener('click', e => {
            if (!pjBox.contains(e.target) && e.target !== pjSearch) {
                pjBox.style.display = 'none';
            }
        });
    </script>

    <!-- MODAL ALASAN OPERASI -->
    <div id="modalAlasan" class="modal-overlay hidden">
        <div class="modal-box">
            <h3 id="modalTitle">Alasan Operasi</h3>

            <div id="modalContent" style="margin-top:10px;white-space:pre-line;"></div>

            <div style="margin-top:15px;text-align:right;">
                <button id="btnApprove" class="btn btn-success">Approve</button>
                <button id="btnReject" class="btn btn-danger">Reject</button>
                <button class="btn btn-secondary" onclick="closeAlasanModal()">Batal</button>
            </div>
        </div>
    </div>
</section>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const alerts = document.querySelectorAll('.alert');

        if (!alerts.length) return;

        setTimeout(() => {
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';

                setTimeout(() => {
                    alert.remove();
                }, 500);
            });
        }, 5000); // ⏱️ 5 detik
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>