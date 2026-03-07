<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function ems_user_position(): string
{
    return strtolower(trim($_SESSION['user_rh']['position'] ?? ''));
}

function ems_require_not_trainee_html(string $featureLabel, string $redirectTo = '/dashboard/index.php'): void
{
    if (ems_user_position() !== 'trainee') {
        return;
    }

    http_response_code(403);
    $GLOBALS['pageTitle'] = 'Akses Ditolak';
    include __DIR__ . '/../partials/header.php';
    ?>
    <div class="card access-card">
        <h3 class="access-title">Akses Ditolak</h3>
        <p class="access-copy">
            Akun dengan posisi <strong>Trainee</strong>
            tidak diperbolehkan mengakses
            <strong><?= htmlspecialchars($featureLabel, ENT_QUOTES, 'UTF-8') ?></strong>.
        </p>
        <a href="<?= htmlspecialchars($redirectTo, ENT_QUOTES, 'UTF-8') ?>" class="btn-secondary top-spaced-button">
            Kembali ke Dashboard
        </a>
    </div>
    <?php
    include __DIR__ . '/../partials/footer.php';
    exit;
}

function ems_require_not_trainee_json(string $featureLabel): void
{
    if (ems_user_position() !== 'trainee') {
        return;
    }

    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => "Akses ditolak (Trainee) untuk {$featureLabel}.",
    ]);
    exit;
}

