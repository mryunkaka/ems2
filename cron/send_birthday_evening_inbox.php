<?php
date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/birthday_helper.php';

ems_birthday_ensure_evening_inbox($pdo);

echo '[birthday_evening_inbox] completed at ' . date('Y-m-d H:i:s') . PHP_EOL;
