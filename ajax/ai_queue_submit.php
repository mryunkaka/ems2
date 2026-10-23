<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Jakarta');
session_start();
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/ai_queue.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../actions/ai_queue_handlers.php';
header('Content-Type: application/json; charset=utf-8');
function ems_ai_queue_json_error(int $status, string $message): never { http_response_code($status); echo json_encode(['ok'=>false,'error'=>$message],JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') ems_ai_queue_json_error(405,'Metode tidak diizinkan.');
if (!validateCsrfToken(csrfRequestToken())) ems_ai_queue_json_error(403,'Token keamanan kedaluwarsa. Muat ulang halaman.');
$userId=(int)($_SESSION['user_rh']['id']??0);
if ($userId<=0) ems_ai_queue_json_error(401,'Sesi login telah berakhir.');
$body=json_decode((string)file_get_contents('php://input'),true);
if (!is_array($body)) $body=$_POST;
$jobType=trim((string)($body['job_type']??''));
$refs=$body['references']??[];
if (!is_array($refs)) ems_ai_queue_json_error(400,'Referensi pekerjaan tidak valid.');
try {
    $prepared=ems_ai_queue_prepare_submission($jobType,$userId,$refs,$pdo);
    $job=ems_ai_queue_enqueue($pdo,$prepared['job_type'],$userId,$prepared['feature_label'],$prepared['references'],$prepared['dedupe_key']);
    $next=ems_ai_queue_safe_return_path((string)($body['return_to']??''));
    $_SESSION['ai_queue_return_'.$job['job_id']]=$next;
    echo json_encode(['ok'=>true]+$job+['wait_url'=>ems_url('/dashboard/ai_queue_wait.php?job_id='.$job['job_id'])],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (OverflowException $e) { ems_ai_queue_json_error(429,$e->getMessage()); }
catch (InvalidArgumentException $e) { ems_ai_queue_json_error(400,$e->getMessage()); }
catch (Throwable $e) { error_log('AI queue submit: '.$e->getMessage()); ems_ai_queue_json_error(500,'Permintaan AI tidak dapat dimasukkan ke antrean.'); }
