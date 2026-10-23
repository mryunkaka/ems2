<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/ai_queue.php';
require_once __DIR__ . '/../../actions/ai_queue_handlers.php';

aiQueueTest('domain.provider_test_job_never_contains_credentials', static function (): void {
    $pdo=aiQueueCoreDb();
    $job=ems_ai_queue_provider_test($pdo,7,'personal','gemini');
    $row=$pdo->query('SELECT references_json FROM system_ai_request_queue WHERE id='.(int)$job['job_id'])->fetch(PDO::FETCH_ASSOC);
    $refs=json_decode($row['references_json'],true);
    aiQueueAssertSame(['scope'=>'personal','provider'=>'gemini'],$refs);
    aiQueueAssertTrue(!str_contains($row['references_json'],'api_key'),'Job tidak boleh memuat API key.');
});

aiQueueTest('domain.birthdays_and_training_dedupe_active_work', static function (): void {
    $pdo=aiQueueCoreDb();
    $birthdayA=ems_ai_queue_enqueue($pdo,'birthday_message',7,'Ucapan ulang tahun',['user_id'=>7,'date_key'=>'2026-10-23'],'birthday:7:2026-10-23');
    $birthdayB=ems_ai_queue_enqueue($pdo,'birthday_message',7,'Ucapan ulang tahun',['user_id'=>7,'date_key'=>'2026-10-23'],'birthday:7:2026-10-23');
    aiQueueAssertSame($birthdayA['job_id'],$birthdayB['job_id']); aiQueueAssertSame(true,$birthdayB['reused']);
    $trainingA=ems_ai_queue_enqueue($pdo,'training_group_generate',7,'Pembuatan kelompok',['unit_code'=>'roxwood','batch'=>4],'training-groups:roxwood:4');
    $trainingB=ems_ai_queue_enqueue($pdo,'training_group_generate',7,'Pembuatan kelompok',['unit_code'=>'roxwood','batch'=>4],'training-groups:roxwood:4');
    aiQueueAssertSame($trainingA['job_id'],$trainingB['job_id']);
});

aiQueueTest('domain.web_pages_do_not_call_provider_helpers_directly', static function (): void {
    $training=(string)file_get_contents(__DIR__.'/../../dashboard/training_group_generator.php');
    $candidate=(string)file_get_contents(__DIR__.'/../../dashboard/candidate_detail.php');
    $birthday=(string)file_get_contents(__DIR__.'/../../config/birthday_helper.php');
    $anniversary=(string)file_get_contents(__DIR__.'/../../actions/work_anniversary_notices.php');
    $globalSettings=(string)file_get_contents(__DIR__.'/../../dashboard/ai_settings_action.php');
    aiQueueAssertTrue(!str_contains($training,'ems_training_generate_groups('),'Halaman training harus enqueue, bukan menjalankan generator langsung.');
    aiQueueAssertTrue(!str_contains($candidate,'ems_ai_generate_candidate_summary(')&&!str_contains($candidate,'ems_ai_review_assessment_plausibility('),'Halaman detail kandidat tidak boleh memanggil model langsung.');
    aiQueueAssertTrue(str_contains($birthday,'ems_ai_queue_enqueue($pdo'),'Pengingat ulang tahun harus memasukkan job ke antrean.');
    aiQueueAssertTrue(str_contains($anniversary,"if (!defined('EMS_AI_QUEUE_WORKER'))"),'Endpoint masa kerja hanya boleh membuat panggilan model di worker.');
    aiQueueAssertTrue(!str_contains($globalSettings,'ems_gemini_test_connection($pdo, $incomingSettings'),'Uji koneksi global harus masuk antrean, bukan memanggil provider dari request web.');
});
