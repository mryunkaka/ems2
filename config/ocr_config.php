<?php

require_once __DIR__ . '/env.php';

if (!defined('EMS_OCR_API_KEY')) {
    define('EMS_OCR_API_KEY', (string)ems_env('EMS_OCR_API_KEY', ''));
}

if (!defined('EMS_OCR_API_ENDPOINT')) {
    define('EMS_OCR_API_ENDPOINT', 'https://api.ocr.space/parse/image');
}

if (!defined('EMS_OCR_API_TEST_IMAGE')) {
    define('EMS_OCR_API_TEST_IMAGE', 'https://dl.a9t9.com/ocr/solarcell.jpg');
}
