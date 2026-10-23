<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Jakarta');
session_start();
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/request_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/roxy_chatbot.php';
require_once __DIR__ . '/../config/ai_queue.php';
header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') emsJsonAbort(405,['success'=>false,'message'=>'Metode tidak diizinkan.']);
emsRequireJsonCsrf('Sesi kedaluwarsa, muat ulang halaman lalu coba lagi.');
$user=$_SESSION['user_rh']??[]; $userId=(int)($user['id']??0);
if($userId<=0) emsJsonAbort(401,['success'=>false,'message'=>'Sesi tidak valid, silakan login ulang.']);
emsRequireRateLimit('roxy_chat',emsCurrentRequestIdentifier($userId),20,60,'Terlalu banyak pesan ke Roxy dalam waktu singkat. Tunggu sebentar lalu coba lagi.');
$message=trim((string)($_POST['message']??'')); $conversationId=(int)($_POST['conversation_id']??0); $clientRequestId=strtolower(trim((string)($_POST['client_request_id']??'')));
if($message==='') emsJsonAbort(422,['success'=>false,'message'=>'Pesan tidak boleh kosong.']);
if(mb_strlen($message)>4000) emsJsonAbort(422,['success'=>false,'message'=>'Pesan terlalu panjang (maksimal 4000 karakter).']);
if(!preg_match('/^[a-f0-9]{32}$/',$clientRequestId)) emsJsonAbort(422,['success'=>false,'message'=>'ID permintaan pesan tidak valid.']);
try {
    ems_roxy_ensure_tables($pdo);
    $unitCode=ems_effective_unit($pdo,$user);
    $queued=ems_roxy_enqueue_message($pdo,$userId,$unitCode,$conversationId>0?$conversationId:null,$message,$clientRequestId);
    if(session_status()===PHP_SESSION_ACTIVE) session_write_close();
    echo json_encode(['success'=>true,'queued'=>true,'job_id'=>$queued['job_id'],'job_status'=>$queued['status'],'reused'=>$queued['reused'],'conversation_id'=>$queued['conversation_id'],'user_message_id'=>$queued['user_message_id']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch(OverflowException $e) {
    emsJsonAbort(429,['success'=>false,'message'=>$e->getMessage(),'error_code'=>'ai_queue_full']);
} catch(Throwable $e) {
    error_log(sprintf('[Roxy queue] %s in %s:%d',$e->getMessage(),$e->getFile(),$e->getLine()));
    emsJsonAbort(500,['success'=>false,'message'=>'Roxy sedang mengalami gangguan sementara. Pesan belum dimasukkan ke antrean.','error_code'=>'roxy_queue_failure']);
}
