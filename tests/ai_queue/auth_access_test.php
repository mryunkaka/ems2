<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/auth_access.php';

aiQueueTest('auth.active_flag_is_authoritative_over_resignation_history', static function (): void {
    aiQueueAssertSame(true, ems_user_access_is_active([
        'is_active' => 1,
        'resign_reason' => 'Masa kerja sebelumnya berakhir',
        'resigned_at' => '2026-01-01 12:00:00',
    ]));
    aiQueueAssertSame(false, ems_user_access_is_active([
        'is_active' => 0,
        'resign_reason' => null,
        'resigned_at' => null,
    ]));
    aiQueueAssertSame(false, ems_user_access_is_active([]));
});
