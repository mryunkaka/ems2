<?php

header('Content-Type: text/plain; charset=UTF-8');

$projectRoot = dirname(__DIR__);
$targets = [
    $projectRoot . '/auth/auth_guard.php',
    $projectRoot . '/config/helpers.php',
    $projectRoot . '/dashboard/setting_akun.php',
    $projectRoot . '/dashboard/setting_akun_action.php',
    $projectRoot . '/dashboard/setting_akun_quick_save.php',
    $projectRoot . '/partials/footer.php',
    $projectRoot . '/assets/js/app.js',
];

echo 'opcache_invalidate=' . (function_exists('opcache_invalidate') ? 'yes' : 'no') . PHP_EOL;
echo 'opcache_reset=' . (function_exists('opcache_reset') ? 'yes' : 'no') . PHP_EOL;
echo 'validate_timestamps=' . (string)ini_get('opcache.validate_timestamps') . PHP_EOL;
echo 'revalidate_freq=' . (string)ini_get('opcache.revalidate_freq') . PHP_EOL;

foreach ($targets as $file) {
    $exists = is_file($file);
    $result = false;

    if ($exists && function_exists('opcache_invalidate')) {
        $result = opcache_invalidate($file, true);
    }

    echo basename($file) . ':exists=' . ($exists ? 'yes' : 'no') . ':invalidate=' . ($result ? 'yes' : 'no') . PHP_EOL;
}

if (function_exists('opcache_reset')) {
    echo 'reset=' . (opcache_reset() ? 'yes' : 'no') . PHP_EOL;
}
