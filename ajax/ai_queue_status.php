<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Jakarta');
session_start();
require_once __DIR__ . '/../auth/auth_guard.php';
$userId=(int)($_SESSION['user_rh']['id']??0);
if ($userId<=0) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'Sesi login telah berakhir.']); exit; }
session_write_close();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/ai_queue.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $jobId=max(0,(int)($_GET['job_id']??0));
    if ($jobId<=0) throw new InvalidArgumentException('ID pekerjaan tidak valid.');
    $status=ems_ai_queue_status($pdo,$jobId,$userId);
    echo json_encode(['ok'=>true]+$status,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (InvalidArgumentException $e) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
catch (PDOException $e) { error_log('AI queue status database error: '.$e->getMessage()); http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Status pekerjaan tidak dapat dibaca.']); }
catch (RuntimeException $e) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Pekerjaan tidak ditemukan.']); }
catch (Throwable $e) { error_log('AI queue status: '.$e->getMessage()); http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Status pekerjaan tidak dapat dibaca.']); }
