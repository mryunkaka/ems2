<?php
$_SERVER['HTTP_HOST'] = 'localhost:8081';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['DOCUMENT_ROOT'] = 'D:/Project/Web/ems2';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = '8081';
$_SERVER['REQUEST_SCHEME'] = 'http';
$_SERVER['HTTPS'] = 'off';
chdir(__DIR__);
require 'index.php';
