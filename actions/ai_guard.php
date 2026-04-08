<?php

require_once __DIR__ . '/../config/helpers.php';

function ems_require_programmer_roxwood_access(string $redirectTo = '/dashboard/index.php'): void
{
    if (ems_current_user_is_programmer_roxwood()) {
        return;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['flash_errors'][] = 'Akses halaman AI hanya untuk Programmer Roxwood.';
    }

    header('Location: ' . $redirectTo);
    exit;
}
