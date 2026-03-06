<?php

$configFile = __DIR__ . '/../dashboard/sheet_config.json';

$sheetConfig = [
    'spreadsheet_id' => '',
    'sheet_gid'      => '',
];

if (file_exists($configFile)) {
    $data = json_decode(file_get_contents($configFile), true);
    if (is_array($data)) {
        $sheetConfig = array_merge($sheetConfig, $data);
    }
}
