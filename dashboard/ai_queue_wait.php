<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Jakarta');
session_start();
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_queue.php';
$jobId=max(0,(int)($_GET['job_id']??0));
try { $job=ems_ai_queue_status($pdo,$jobId,(int)($_SESSION['user_rh']['id']??0)); }
catch(PDOException $error) { error_log('AI queue wait database error: '.$error->getMessage()); http_response_code(500); exit('Status antrean belum dapat dimuat.'); }
catch(Throwable) { http_response_code(404); exit('Pekerjaan tidak ditemukan.'); }
$queueJobId=$jobId;
$queueFeature=(string)$job['feature_label'];
$queueNextUrl=ems_ai_queue_safe_return_path((string)($_SESSION['ai_queue_return_'.$jobId]??''));
unset($_SESSION['ai_queue_return_'.$jobId]);
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Antrean AI</title><style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(ellipse at 50% 0,#183c54,#07141e 65%);color:#eaf4f8;font:16px/1.55 system-ui,sans-serif;display:grid;place-items:center;padding:24px}.ai-queue-card{width:min(100%,650px);padding:38px;border:1px solid #315263;border-radius:22px;background:#102531eF;box-shadow:0 24px 80px #0006}.ai-queue-mark{font-size:38px;color:#62d8cb}.ai-queue-kicker{color:#72dacc;text-transform:uppercase;letter-spacing:.13em;font-size:12px;font-weight:700}.ai-queue-card h1{font-size:clamp(25px,5vw,36px);line-height:1.2;margin:.4em 0}.ai-queue-summary,.ai-queue-footnote{color:#bdd0d9}.ai-queue-progress{height:7px;background:#274552;border-radius:9px;overflow:hidden;margin:28px 0 14px}.ai-queue-progress span{display:block;width:35%;height:100%;background:linear-gradient(90deg,#45cbbd,#bcf7dc);border-radius:9px;animation:queue 1.4s ease-in-out infinite alternate}.ai-queue-status{font-size:18px;font-weight:650}.ai-queue-position{color:#9db7c2}.ai-queue-active{margin-top:28px;padding:18px;border:1px solid #315263;border-radius:14px;background:#0b1b25}.ai-queue-active h2{font-size:14px;margin:0 0 10px;color:#99b6c1}.ai-queue-active ul{margin:0;padding-left:21px}.ai-queue-result{white-space:pre-wrap;overflow-wrap:anywhere;background:#091820;padding:15px;border-radius:10px;max-height:260px;overflow:auto}.ai-queue-continue{display:inline-block;margin-top:12px;padding:12px 18px;border-radius:9px;background:#70dccd;color:#06201e;text-decoration:none;font-weight:700}@keyframes queue{from{transform:translateX(-35%)}to{transform:translateX(225%)}}@media(max-width:500px){.ai-queue-card{padding:25px}}
</style></head><body><?php require __DIR__.'/../partials/ai_queue_screen.php'; ?></body></html>
