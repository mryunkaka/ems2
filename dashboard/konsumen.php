<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/date_range.php';

$pageTitle = 'Data Konsumen';

include __DIR__ . '/../partials/header.php';
include __DIR__ . '/../partials/sidebar.php';

// ===============================
// ROLE USER
// ===============================
$userRole = strtolower(trim($_SESSION['user_rh']['role'] ?? ''));

// ===============================
// FILTER INPUT
// ===============================
$q         = trim($_GET['q'] ?? '');
$startDate = $_GET['start_date'] ?? '';
$endDate   = $_GET['end_date'] ?? '';

$sales = [];

// ===============================
// QUERY DATA KONSUMEN (DINAMIS)
// ===============================
if ($q !== '' || ($startDate && $endDate)) {

    $sql = "
        SELECT
            s.created_at,
            s.consumer_name,
            s.medic_name,
            s.medic_jabatan,
            s.qty_bandage,
            s.qty_ifaks,
            s.qty_painkiller,
            (s.qty_bandage + s.qty_ifaks + s.qty_painkiller) AS total_item,
            s.price,
            s.identity_id,
            im.citizen_id
        FROM sales s
        LEFT JOIN identity_master im ON im.id = s.identity_id
        WHERE 1=1
    ";

    $params = [];

    // 🔍 FILTER KEYWORD
    if ($q !== '') {
        $sql .= "
            AND (
                s.consumer_name LIKE :q
                OR im.citizen_id LIKE :q
                OR s.medic_name LIKE :q
            )
        ";
        $params[':q'] = "%$q%";
    }

    // 📅 FILTER TANGGAL
    if ($startDate && $endDate) {
        $sql .= " AND DATE(s.created_at) BETWEEN :start_date AND :end_date";
        $params[':start_date'] = $startDate;
        $params[':end_date']   = $endDate;
    }

    // ⬇️ SORT & LIMIT
    $sql .= " ORDER BY s.created_at DESC LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<section class="content">
    <div class="page" style="max-width:1200px;margin:auto;">

        <h1>Data Konsumen</h1>

        <p class="text-muted">Menampilkan seluruh data transaksi konsumen</p>

        <div class="card">
            <div class="card-header-actions" style="margin-bottom: 20px;">
                <div class="card-header-actions-title">
                    Daftar Transaksi Konsumen
                </div>
                <?php if ($userRole !== 'staff'): ?>
                    <div class="card-header-actions-right">
                        <button type="button" class="btn btn-success" onclick="openImportModal()">
                            📥 Import Excel
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <div class="search-panel">
                <form method="get" class="search-form search-form-inline">

                    <input type="hidden" name="start_date" id="startDate">
                    <input type="hidden" name="end_date" id="endDate">

                    <!-- CUSTOM DATE (HIDDEN DEFAULT) -->
                    <div class="search-field search-field-date" id="customDateWrapper" style="display:none;">
                        <input type="date" id="customStart">
                    </div>

                    <div class="search-field search-field-date" id="customDateWrapperEnd" style="display:none;">
                        <input type="date" id="customEnd">
                    </div>

                    <!-- RENTANG -->
                    <div class="search-field search-field-range">
                        <select name="range" id="rangeSelect">
                            <option value="this_week">Minggu Ini</option>
                            <option value="last_week">Minggu Lalu</option>
                            <option value="2_weeks">2 Minggu Lalu</option>
                            <option value="3_weeks">3 Minggu Lalu</option>
                            <option value="custom">Custom</option>
                        </select>
                    </div>

                    <!-- KEYWORD -->
                    <div class="search-field search-field-keyword">
                        <input type="text"
                            name="q"
                            placeholder="Cari Nama Konsumen / Citizen ID / Nama Medis"
                            value="<?= htmlspecialchars($_GET['q'] ?? '') ?>"
                            autocomplete="off">
                    </div>

                    <!-- ACTION -->
                    <div class="search-actions">
                        <button type="submit" class="btn btn-primary">
                            Cari
                        </button>

                        <?php if (!empty($_GET['q']) || !empty($_GET['range'])): ?>
                            <a href="konsumen.php" class="btn btn-secondary">
                                Clear
                            </a>
                        <?php endif; ?>
                    </div>

                </form>
            </div>

            <div class="table-wrapper">
                <table id="konsumenTable" class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Tanggal</th>
                            <th>Citizen ID</th>
                            <th>Nama Konsumen</th>
                            <th>Nama Medis</th>
                            <th>Jabatan</th>
                            <th>Bandage</th>
                            <th>IFAK</th>
                            <th>Obat</th>
                            <th>Total Item</th>
                            <th>Total Harga</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (!empty($sales)): ?>
                            <?php foreach ($sales as $i => $row): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td><?= date('d M Y H:i', strtotime($row['created_at'])) ?></td>
                                    <td>
                                        <?php if (!empty($row['citizen_id'])): ?>
                                            <a href="#"
                                                class="identity-link"
                                                data-identity-id="<?= (int)$row['identity_id'] ?>">
                                                <?= htmlspecialchars($row['citizen_id']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span style="color:#9ca3af;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($row['consumer_name']) ?></td>
                                    <td><?= htmlspecialchars($row['medic_name']) ?></td>
                                    <td><?= htmlspecialchars($row['medic_jabatan']) ?></td>
                                    <td><?= (int)$row['qty_bandage'] ?></td>
                                    <td><?= (int)$row['qty_ifaks'] ?></td>
                                    <td><?= (int)$row['qty_painkiller'] ?></td>
                                    <td><?= (int)$row['total_item'] ?></td>
                                    <td><?= dollar((int)$row['price']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th style="text-align:right;">TOTAL</th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</section>

<!-- ================================================
     MODAL IDENTITY
     ================================================ -->
<div id="identityModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <div class="modal-header">
            <strong>Data Konsumen</strong>
            <button onclick="closeIdentityModal()" type="button">✕</button>
        </div>

        <div id="identityContent" class="modal-body">
            <p style="color:#9ca3af;">Memuat data...</p>
        </div>
    </div>
</div>

<!-- =========================
MODAL IMPORT KONSUMEN (EMS)
========================= -->
<div id="importModal" class="ems-modal-overlay" style="display:none;">
    <div class="ems-modal-card" style="max-width:520px;">

        <h4>📥 Import Data Transaksi</h4>

        <form id="importForm" enctype="multipart/form-data">

            <div class="ems-form-group">
                <label>Nama Medis Yang Input</label>
                <input type="text"
                    id="medicNameInput"
                    name="medic_name"
                    placeholder="Ketik nama medis..."
                    autocomplete="off"
                    required>

                <div id="medicSuggestions" class="ems-suggestion-box"></div>
            </div>

            <div class="ems-form-group">
                <label>Tanggal Transaksi</label>
                <input type="date"
                    name="transaction_date"
                    id="transactionDate"
                    required>
            </div>

            <div class="ems-form-group">
                <label>File Excel (.xlsx / .xls)</label>
                <input type="file"
                    name="excel_file"
                    id="excelFile"
                    accept=".xlsx,.xls"
                    required>
            </div>

            <!-- PROGRESS -->
            <div id="importProgress" class="ems-import-progress" style="display:none;">
                <div class="ems-spinner"></div>
                <p>Mengupload dan memproses data...</p>
            </div>

            <div class="modal-actions">
                <button type="button" class="ems-btn-cancel" onclick="closeImportModal()">
                    Batal
                </button>
                <button type="submit" class="ems-btn-confirm" id="importBtn">
                    Import Data
                </button>
            </div>

        </form>
    </div>
</div>

<script>
    document.getElementById('rangeSelect')?.addEventListener('change', function() {
        const range = this.value;

        const startHidden = document.getElementById('startDate');
        const endHidden = document.getElementById('endDate');

        const customStart = document.getElementById('customStart');
        const customEnd = document.getElementById('customEnd');

        const wrapStart = document.getElementById('customDateWrapper');
        const wrapEnd = document.getElementById('customDateWrapperEnd');

        const today = new Date();
        let start, end;

        function format(d) {
            return d.toISOString().slice(0, 10);
        }

        // RESET
        wrapStart.style.display = 'none';
        wrapEnd.style.display = 'none';

        if (range === 'custom') {
            startHidden.value = '';
            endHidden.value = '';

            wrapStart.style.display = 'block';
            wrapEnd.style.display = 'block';

            customStart.focus();
            return;
        }

        if (range === 'this_week') {
            const day = today.getDay() || 7;
            start = new Date(today);
            start.setDate(today.getDate() - day + 1);
            end = new Date(start);
            end.setDate(start.getDate() + 6);
        }

        if (range === 'last_week') {
            const day = today.getDay() || 7;
            end = new Date(today);
            end.setDate(today.getDate() - day);
            start = new Date(end);
            start.setDate(end.getDate() - 6);
        }

        if (range === '2_weeks') {
            start = new Date(today);
            start.setDate(today.getDate() - 14);
            end = today;
        }

        if (range === '3_weeks') {
            start = new Date(today);
            start.setDate(today.getDate() - 21);
            end = today;
        }

        if (start && end) {
            startHidden.value = format(start);
            endHidden.value = format(end);
        }
    });

    // COPY VALUE CUSTOM → HIDDEN
    document.getElementById('customStart')?.addEventListener('change', function() {
        document.getElementById('startDate').value = this.value;
    });

    document.getElementById('customEnd')?.addEventListener('change', function() {
        document.getElementById('endDate').value = this.value;
    });
</script>

<script>
    // ================================================
    // IMPORT MODAL HANDLERS
    // ================================================
    function openImportModal() {
        document.getElementById('importModal').style.display = 'flex';
        document.getElementById('transactionDate').value = new Date().toISOString().split('T')[0];
    }

    function closeImportModal() {
        document.getElementById('importModal').style.display = 'none';
        document.getElementById('importForm').reset();
        document.getElementById('importProgress').style.display = 'none';
        document.getElementById('medicSuggestions').style.display = 'none';
    }

    // ================================================
    // MEDIC NAME AUTOCOMPLETE
    // ================================================
    let medicSearchTimeout;
    const medicInput = document.getElementById('medicNameInput');
    const medicSuggestions = document.getElementById('medicSuggestions');

    medicInput.addEventListener('input', function() {
        clearTimeout(medicSearchTimeout);
        const query = this.value.trim();

        if (query.length < 2) {
            medicSuggestions.style.display = 'none';
            return;
        }

        medicSearchTimeout = setTimeout(async () => {
            try {
                const res = await fetch(`/actions/search_medic.php?q=${encodeURIComponent(query)}`);
                const data = await res.json();

                if (data.success && data.medics.length > 0) {
                    medicSuggestions.innerHTML = data.medics.map(m => `
                        <div class="medic-suggestion-item" onclick="selectMedic('${m.full_name}', '${m.position}')">
                            <strong>${m.full_name}</strong>
                            <div style="font-size:12px;color:#64748b;">${m.position}</div>
                        </div>
                    `).join('');
                    medicSuggestions.style.display = 'block';
                } else {
                    medicSuggestions.style.display = 'none';
                }
            } catch (e) {
                console.error('Error searching medic:', e);
            }
        }, 300);
    });

    function selectMedic(name, position) {
        medicInput.value = name;
        medicInput.dataset.position = position;
        medicSuggestions.style.display = 'none';
    }

    // Close suggestions when clicking outside
    document.addEventListener('click', function(e) {
        if (e.target !== medicInput && !medicSuggestions.contains(e.target)) {
            medicSuggestions.style.display = 'none';
        }
    });

    // ================================================
    // IMPORT FORM SUBMISSION
    // ================================================
    document.getElementById('importForm').addEventListener('submit', async function(e) {
        e.preventDefault();

        const formData = new FormData(this);
        const medicName = medicInput.value.trim();
        const medicPosition = medicInput.dataset.position || '';

        if (!medicName) {
            alert('Nama medis harus diisi!');
            return;
        }

        formData.append('medic_position', medicPosition);

        // Show loading
        document.getElementById('importProgress').style.display = 'block';
        document.getElementById('importBtn').disabled = true;

        try {
            const res = await fetch('/actions/import_sales_excel.php', {
                method: 'POST',
                body: formData
            });

            const result = await res.json();

            if (result.success) {
                alert(`✅ Berhasil import ${result.imported} transaksi!`);
                closeImportModal();
                location.reload();
            } else {
                alert('❌ Error: ' + (result.message || 'Import gagal'));
            }
        } catch (error) {
            console.error('Import error:', error);
            alert('❌ Terjadi kesalahan saat import data');
        } finally {
            document.getElementById('importProgress').style.display = 'none';
            document.getElementById('importBtn').disabled = false;
        }
    });
</script>

<script>
    // ================================================
    // IMAGE LIGHTBOX (ZOOM KTP)
    // ================================================
    function createLightbox() {
        if (document.getElementById('imageLightbox')) return;

        const lightbox = document.createElement('div');
        lightbox.id = 'imageLightbox';
        lightbox.className = 'image-lightbox';
        lightbox.innerHTML = `
        <div class="lightbox-content">
            <button class="lightbox-close" onclick="closeLightbox()">×</button>
            <img class="lightbox-image" src="" alt="KTP Preview">
            <div class="lightbox-caption"></div>
        </div>
    `;
        document.body.appendChild(lightbox);
    }

    function openLightbox(imageSrc, caption = '') {
        createLightbox();
        const lightbox = document.getElementById('imageLightbox');
        const lightboxImage = lightbox.querySelector('.lightbox-image');
        const lightboxCaption = lightbox.querySelector('.lightbox-caption');
        lightboxImage.src = imageSrc;
        lightboxCaption.textContent = caption;
        lightbox.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        const lightbox = document.getElementById('imageLightbox');
        if (lightbox) {
            lightbox.classList.remove('active');
            document.body.style.overflow = '';
        }
    }

    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('identity-photo')) {
            e.preventDefault();
            openLightbox(e.target.src, e.target.alt || 'Foto Identitas');
        }
        if (e.target.id === 'imageLightbox') {
            closeLightbox();
        }
        if (e.target.classList.contains('lightbox-image')) {
            e.stopPropagation();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeLightbox();
        }
    });

    // ================================================
    // MODAL IDENTITY HANDLER
    // ================================================
    document.addEventListener('click', function(e) {
        const link = e.target.closest('.identity-link');
        if (!link) return;
        e.preventDefault();
        openIdentityModal(link.dataset.identityId);
    });

    function openIdentityModal(identityId) {
        const modal = document.getElementById('identityModal');
        const content = document.getElementById('identityContent');
        modal.style.display = 'flex';
        content.innerHTML = '<p style="color:#9ca3af;">Memuat data...</p>';

        fetch('/ajax/get_identity_detail.php?id=' + encodeURIComponent(identityId))
            .then(res => res.text())
            .then(html => {
                content.innerHTML = html;
            })
            .catch(err => {
                console.error('Error loading identity:', err);
                content.innerHTML = '<p style="color:#ef4444;">Gagal memuat data.</p>';
            });
    }

    function closeIdentityModal() {
        document.getElementById('identityModal').style.display = 'none';
    }

    document.addEventListener('click', function(e) {
        const modal = document.getElementById('identityModal');
        if (e.target === modal) closeIdentityModal();

        const importModal = document.getElementById('importModal');
        if (e.target === importModal) closeImportModal();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeIdentityModal();
            closeImportModal();
        }
    });
</script>

<script>
    // ================================================
    // DATATABLE INITIALIZATION
    // ================================================
    document.addEventListener('DOMContentLoaded', function() {
        if (!window.jQuery || !jQuery.fn.DataTable) {
            console.error('jQuery atau DataTables tidak tersedia');
            return;
        }

        const table = document.getElementById('konsumenTable');
        if (!table) return;

        try {
            jQuery('#konsumenTable').DataTable({
                pageLength: 10,
                order: [
                    [1, 'desc']
                ],
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/id.json'
                },
                searching: false,
                footerCallback: function(row, data, start, end, display) {
                    const api = this.api();

                    function intVal(i) {
                        return typeof i === 'string' ?
                            i.replace(/[^\d]/g, '') * 1 :
                            typeof i === 'number' ? i : 0;
                    }

                    const totalBandage = api.column(6, {
                            search: 'applied'
                        })
                        .data().reduce((a, b) => intVal(a) + intVal(b), 0);
                    const totalIFAK = api.column(7, {
                            search: 'applied'
                        })
                        .data().reduce((a, b) => intVal(a) + intVal(b), 0);
                    const totalObat = api.column(8, {
                            search: 'applied'
                        })
                        .data().reduce((a, b) => intVal(a) + intVal(b), 0);
                    const totalItem = api.column(9, {
                            search: 'applied'
                        })
                        .data().reduce((a, b) => intVal(a) + intVal(b), 0);
                    const totalPrice = api.column(10, {
                            search: 'applied'
                        })
                        .data().reduce((a, b) => intVal(a) + intVal(b), 0);

                    function formatDollar(num) {
                        return '$' + num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                    }

                    jQuery(api.column(6).footer()).html(totalBandage);
                    jQuery(api.column(7).footer()).html(totalIFAK);
                    jQuery(api.column(8).footer()).html(totalObat);
                    jQuery(api.column(9).footer()).html(totalItem);
                    jQuery(api.column(10).footer()).html(formatDollar(totalPrice));
                }
            });
        } catch (error) {
            console.error('Error initializing DataTable:', error);
        }
    });
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>