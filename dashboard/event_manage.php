<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../assets/design/ui/icon.php';

// ===============================
// ROLE GUARD
// ===============================
$role = strtolower($_SESSION['user_rh']['role'] ?? '');
if ($role === 'staff') {
    header('Location: events.php');
    exit;
}

// ===============================
// FLASH
// ===============================
$messages = $_SESSION['flash_messages'] ?? [];
$errors   = $_SESSION['flash_errors'] ?? [];
unset($_SESSION['flash_messages'], $_SESSION['flash_errors']);

$pageTitle = 'Manajemen Event';

// ===============================
// AMBIL EVENT
// ===============================
$events = $pdo->query("
    SELECT 
        e.*,
        (SELECT COUNT(*) FROM event_participants ep WHERE ep.event_id = e.id) total_peserta
    FROM events e
    ORDER BY e.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

function formatTanggalHari(string $tanggal): string
{
    $hari = [
        'Minggu',
        'Senin',
        'Selasa',
        'Rabu',
        'Kamis',
        'Jumat',
        'Sabtu'
    ];

    $dt = new DateTime($tanggal);
    $namaHari = $hari[(int)$dt->format('w')];

    return $namaHari . ', ' . $dt->format('d M Y');
}

?>

<?php include __DIR__ . '/../partials/header.php'; ?>
<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<section class="content">
    <div class="page page-shell-md">

	        <h1 class="page-title">Manajemen Event</h1>
	        <p class="page-subtitle">Tambah, edit, dan kelola event</p>

        <?php foreach ($messages as $m): ?>
            <div class="alert alert-info"><?= htmlspecialchars($m) ?></div>
        <?php endforeach; ?>

        <?php foreach ($errors as $e): ?>
            <div class="alert alert-error"><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>

        <div class="card">
            <div class="card-header card-header-between">
                <span>Daftar Event</span>
                <button class="btn-success" id="btnAddEvent"><?= ems_icon('plus', 'h-4 w-4') ?> <span>Tambah Event</span></button>
            </div>

            <div class="table-wrapper">
                <table class="table-custom datatable-event">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nama Event</th>
                            <th>Tanggal</th>
                            <th>Lokasi</th>
                            <th>Status</th>
                            <th>Peserta</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $i => $e): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <a href="event_participants.php?event_id=<?= (int)$e['id'] ?>"
                                        class="link-primary">
                                        <?= htmlspecialchars($e['nama_event']) ?>
                                    </a>
                                </td>
                                <td><?= formatTanggalHari($e['tanggal_event']) ?></td>
                                <td><?= htmlspecialchars($e['lokasi'] ?? '-') ?></td>
                                <td>
                                    <?= $e['is_active'] ? '<span class="badge-success">Aktif</span>' : '<span class="badge-muted">Nonaktif</span>' ?>
                                </td>
                                <td><?= (int)$e['total_peserta'] ?></td>
                                <td>
                                    <div class="action-row-nowrap">
                                        <button class="btn-secondary btn-edit"
                                            data-id="<?= $e['id'] ?>"
                                            data-nama="<?= htmlspecialchars($e['nama_event'], ENT_QUOTES) ?>"
                                            data-tanggal="<?= $e['tanggal_event'] ?>"
                                            data-lokasi="<?= htmlspecialchars($e['lokasi'], ENT_QUOTES) ?>"
                                            data-ket="<?= htmlspecialchars($e['keterangan'], ENT_QUOTES) ?>"
                                            data-active="<?= $e['is_active'] ?>">
                                            Edit
                                        </button>

                                        <button type="button" class="btn-danger btn-delete"
                                            data-id="<?= $e['id'] ?>"
                                            data-nama="<?= htmlspecialchars($e['nama_event'], ENT_QUOTES) ?>">
                                            Hapus
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<div id="eventModal" class="modal-overlay hidden">
    <div class="modal-box modal-shell modal-frame-md">
        <div class="modal-head">
            <div id="modalTitle" class="modal-title">Tambah Event</div>
            <button type="button" class="modal-close-btn btn-cancel" aria-label="Tutup modal">
                <?= ems_icon('x-mark', 'h-5 w-5') ?>
            </button>
        </div>

        <form method="POST" action="event_action.php" class="form modal-form">
            <div class="modal-content">
            <input type="hidden" name="event_id" id="eventId">

            <label>Nama Event</label>
            <input type="text" name="nama_event" id="eventNama" required>

            <label>Tanggal Event</label>
            <input type="date" name="tanggal_event" id="eventTanggal" required>

            <label>Lokasi</label>
            <input type="text" name="lokasi" id="eventLokasi">

            <label>Keterangan</label>
            <textarea name="keterangan" id="eventKet"></textarea>

            <label class="checkbox-label checkbox-pill">
                <input type="checkbox" name="is_active" id="eventActive" checked>
                Event Aktif
            </label>

            </div>

            <div class="modal-foot">
                <div class="modal-actions">
                    <button type="button" class="btn-secondary btn-cancel">Batal</button>
                    <button type="submit" class="btn-success">Simpan</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {

        const modal = document.getElementById('eventModal');

        document.getElementById('btnAddEvent').onclick = () => {
            modal.querySelector('form').reset();
            document.getElementById('eventId').value = '';
            document.getElementById('modalTitle').innerText = 'Tambah Event';
            modal.classList.remove('hidden');
            modal.style.display = 'flex';
            document.body.classList.add('modal-open');
        };

        document.body.addEventListener('click', function(e) {
            if (e.target.closest('.btn-edit')) {
                const b = e.target.closest('.btn-edit');
                document.getElementById('modalTitle').innerText = 'Edit Event';
                eventId.value = b.dataset.id;
                eventNama.value = b.dataset.nama;
                eventTanggal.value = b.dataset.tanggal;
                eventLokasi.value = b.dataset.lokasi;
                eventKet.value = b.dataset.ket;
                eventActive.checked = b.dataset.active == 1;
                modal.classList.remove('hidden');
                modal.style.display = 'flex';
                document.body.classList.add('modal-open');
            }

            // Hapus permanen (endpoint baru): event_delete.php (hapus relasi + event)
            if (e.target.closest('.btn-delete')) {
                const b = e.target.closest('.btn-delete');
                const nama = b.dataset.nama || 'event ini';
                const ok = confirm(
                    `Yakin ingin menghapus permanen ${nama}?\n\n` +
                    `Catatan: data peserta/kelompok terkait juga akan ikut terhapus.`
                );
                if (!ok) return;

                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'event_delete.php';

                function add(name, value) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = value ?? '';
                    form.appendChild(input);
                }

                add('event_id', b.dataset.id || '');

                document.body.appendChild(form);
                form.submit();
                return;
            }

            if (e.target.closest('.btn-cancel') || e.target === modal) {
                modal.classList.add('hidden');
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                modal.classList.add('hidden');
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            }
        });
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {

        if (window.jQuery && jQuery.fn.DataTable) {
            jQuery('.datatable-event').DataTable({
                pageLength: 10,
                order: [
                    [2, 'desc']
                ],
                scrollX: true,
                language: {
                    url: '/assets/design/js/datatables-id.json'
                }
            });
        } else {
            console.warn('DataTables belum ter-load');
        }

    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
