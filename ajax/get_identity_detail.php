<?php
require_once __DIR__ . '/../config/database.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    exit('<p style="color:#ef4444;text-align:center;padding:20px;">Identity ID tidak valid.</p>');
}

/* ===============================
   AMBIL DATA MASTER (AKTIF)
   =============================== */
$stmtMaster = $pdo->prepare("
    SELECT *
    FROM identity_master
    WHERE id = :id
    LIMIT 1
");
$stmtMaster->execute([':id' => $id]);
$master = $stmtMaster->fetch(PDO::FETCH_ASSOC);

if (!$master) {
    exit('<p style="color:#ef4444;text-align:center;padding:20px;">Data konsumen tidak ditemukan.</p>');
}

/* ===============================
   AMBIL RIWAYAT IDENTITAS
   =============================== */
$stmtVersions = $pdo->prepare("
    SELECT *
    FROM identity_versions
    WHERE identity_id = :id
    ORDER BY created_at DESC
");
$stmtVersions->execute([':id' => $id]);
$versions = $stmtVersions->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    .identity-wrapper {
        display: flex;
        flex-direction: column;
        gap: 24px;
    }

    .identity-section-title {
        font-size: 16px;
        font-weight: 700;
        color: #1e293b;
        margin: 0 0 12px 0;
        padding: 0 0 10px 0;
        border-bottom: 2px solid #3b82f6;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .identity-section-title::before {
        content: "📋";
        font-size: 20px;
    }

    .history-count-badge {
        display: inline-block;
        background: #3b82f6;
        color: white;
        font-size: 12px;
        font-weight: 600;
        padding: 4px 10px;
        border-radius: 999px;
        margin-left: 8px;
    }

    .identity-card-container {
        display: grid;
        gap: 16px;
    }

    .identity-card {
        border: 2px solid #e2e8f0;
        border-radius: 12px;
        padding: 20px;
        background: #ffffff;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        transition: all 0.2s ease;
    }

    .identity-card:hover {
        border-color: #3b82f6;
        box-shadow: 0 4px 12px rgba(59, 130, 246, 0.12);
    }

    .identity-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
        padding-bottom: 12px;
        border-bottom: 2px solid #f1f5f9;
    }

    .identity-status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        font-size: 12px;
        font-weight: 600;
        border-radius: 999px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .badge-active-status {
        background: #dcfce7;
        color: #166534;
        border: 1px solid #86efac;
    }

    .badge-active-status::before {
        content: "●";
        font-size: 14px;
        animation: pulse-dot 2s infinite;
    }

    .badge-history-status {
        background: #fef3c7;
        color: #92400e;
        border: 1px solid #fde047;
    }

    @keyframes pulse-dot {

        0%,
        100% {
            opacity: 1;
        }

        50% {
            opacity: 0.5;
        }
    }

    .identity-photo {
        width: 100%;
        max-width: 280px;
        height: 200px;
        object-fit: cover;
        border-radius: 10px;
        margin: 0 auto 16px;
        display: block;
        border: 2px solid #e2e8f0;
    }

    .identity-no-photo {
        width: 100%;
        max-width: 280px;
        height: 200px;
        background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #94a3b8;
        font-size: 14px;
        margin: 0 auto 16px;
        border: 2px dashed #cbd5e1;
    }

    .identity-details {
        display: grid;
        gap: 12px;
    }

    .identity-detail-row {
        display: grid;
        grid-template-columns: 110px 1fr;
        gap: 12px;
        padding: 10px 0;
        border-bottom: 1px solid #f1f5f9;
    }

    .identity-detail-row:last-child {
        border-bottom: none;
    }

    .identity-label {
        font-size: 13px;
        font-weight: 600;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .identity-value {
        font-size: 15px;
        color: #1e293b;
        font-weight: 500;
        word-break: break-word;
    }

    .history-grid {
        display: grid;
        gap: 16px;
    }

    @media (min-width: 768px) {
        .history-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }
</style>

<?php
function identityImageUrl(?string $path): ?string
{
    if (!$path) return null;

    // Bersihkan slash awal
    $path = ltrim($path, '/');

    // Jika DB sudah menyimpan "storage/identity/..."
    if (strpos($path, 'storage/identity/') === 0) {
        return '/' . $path;
    }

    // Jika DB hanya menyimpan "Y8TNU04H/v1.jpg"
    return '/storage/identity/' . $path;
}

?>

<div class="identity-wrapper">

    <!-- ================= DATA AKTIF ================= -->
    <div>
        <div class="identity-section-title">
            Data Aktif
        </div>

        <div class="identity-card-container">
            <div class="identity-card">
                <div class="identity-card-header">
                    <span class="identity-status-badge badge-active-status">Aktif</span>
                </div>

                <?php $img = identityImageUrl($master['image_path']); ?>
                <?php if ($img): ?>
                    <img
                        src="<?= htmlspecialchars($img) ?>"
                        class="identity-photo"
                        alt="KTP <?= htmlspecialchars($master['first_name'] . ' ' . $master['last_name']) ?>"
                        title="Klik untuk memperbesar">
                <?php else: ?>
                    <div class="identity-no-photo">📷 Tidak ada foto</div>
                <?php endif; ?>

                <div class="identity-details">
                    <div class="identity-detail-row">
                        <span class="identity-label">Nama</span>
                        <span class="identity-value"><?= htmlspecialchars($master['first_name'] . ' ' . $master['last_name']) ?></span>
                    </div>
                    <div class="identity-detail-row">
                        <span class="identity-label">NIK</span>
                        <span class="identity-value"><?= htmlspecialchars($master['citizen_id']) ?></span>
                    </div>
                    <div class="identity-detail-row">
                        <span class="identity-label">Lahir</span>
                        <span class="identity-value"><?= htmlspecialchars($master['dob'] ?? '-') ?></span>
                    </div>
                    <div class="identity-detail-row">
                        <span class="identity-label">Kelamin</span>
                        <span class="identity-value"><?= htmlspecialchars($master['sex'] ?? '-') ?></span>
                    </div>
                    <div class="identity-detail-row">
                        <span class="identity-label">Negara</span>
                        <span class="identity-value"><?= htmlspecialchars($master['nationality'] ?? '-') ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================= RIWAYAT ================= -->
    <?php if (!empty($versions)): ?>
        <div>
            <div class="identity-section-title">
                Riwayat Perubahan
                <span class="history-count-badge"><?= count($versions) ?></span>
            </div>

            <div class="history-grid">
                <?php foreach ($versions as $v): ?>
                    <div class="identity-card">
                        <div class="identity-card-header">
                            <span class="identity-status-badge badge-history-status">Riwayat</span>
                        </div>

                        <?php $img = identityImageUrl($v['image_path']); ?>
                        <?php if ($img): ?>
                            <img
                                src="<?= htmlspecialchars($img) ?>"
                                class="identity-photo"
                                alt="KTP <?= htmlspecialchars($v['first_name'] . ' ' . $v['last_name']) ?> (Riwayat)"
                                title="Klik untuk memperbesar">
                        <?php else: ?>
                            <div class="identity-no-photo">📷 Tidak ada foto</div>
                        <?php endif; ?>

                        <div class="identity-details">
                            <div class="identity-detail-row">
                                <span class="identity-label">Nama</span>
                                <span class="identity-value"><?= htmlspecialchars($v['first_name'] . ' ' . $v['last_name']) ?></span>
                            </div>
                            <div class="identity-detail-row">
                                <span class="identity-label">NIK</span>
                                <span class="identity-value"><?= htmlspecialchars($v['citizen_id']) ?></span>
                            </div>
                            <div class="identity-detail-row">
                                <span class="identity-label">Alasan</span>
                                <span class="identity-value"><?= htmlspecialchars($v['change_reason'] ?? '-') ?></span>
                            </div>
                            <div class="identity-detail-row">
                                <span class="identity-label">Waktu</span>
                                <span class="identity-value"><?= date('d M Y H:i', strtotime($v['created_at'])) ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

</div>