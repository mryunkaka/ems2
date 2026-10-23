<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/ai_settings.php';

aiQueueTest('settings.defaults_limit_queue_pressure', static function (): void {
    $defaults = ems_ai_settings_defaults();
    aiQueueAssertSame(1, (int)($defaults['ai_max_concurrent_requests'] ?? 0), 'Batas konkurensi bawaan');
    aiQueueAssertSame(50, (int)($defaults['ai_max_queue_size'] ?? 0), 'Batas antrean bawaan');
});

aiQueueTest('settings.clamps_out_of_range_values', static function (): void {
    $normalized = ems_ai_queue_settings_normalize([
        'ai_max_concurrent_requests' => 0,
        'ai_max_queue_size' => 201,
    ]);
    aiQueueAssertSame(1, $normalized['ai_max_concurrent_requests']);
    aiQueueAssertSame(200, $normalized['ai_max_queue_size']);
});

aiQueueTest('settings.preserves_valid_values', static function (): void {
    $normalized = ems_ai_queue_settings_normalize([
        'ai_max_concurrent_requests' => '3',
        'ai_max_queue_size' => '100',
    ]);
    aiQueueAssertSame(3, $normalized['ai_max_concurrent_requests']);
    aiQueueAssertSame(100, $normalized['ai_max_queue_size']);
});
