<?php
declare(strict_types=1);
require_once __DIR__ . '/../../actions/ai_recruitment_service.php';

aiQueueTest('interview.missing_material_is_queued_only_with_active_provider_and_template', static function (): void {
    aiQueueAssertSame(true,ems_ai_interview_should_enqueue(true,true,true,false,false));
    aiQueueAssertSame(true,ems_ai_interview_should_enqueue(true,false,false,true,true));
    aiQueueAssertSame(false,ems_ai_interview_should_enqueue(false,true,true,true,true));
    aiQueueAssertSame(false,ems_ai_interview_should_enqueue(true,true,false,false,false));
});

aiQueueTest('interview.get_path_exits_before_model_generation', static function (): void {
    $source=(string)file_get_contents(__DIR__.'/../../dashboard/candidate_interview_multi.php');
    $queue=strpos($source,'ems_ai_interview_should_enqueue(');
    $exit=strpos($source,'    exit;',$queue);
    $summary=strpos($source,'ems_ai_generate_candidate_summary(');
    $pack=strpos($source,'ems_ai_get_or_create_interview_question_pack(');
    aiQueueAssertTrue($queue!==false&&$exit!==false&&$summary!==false&&$pack!==false&&$exit<$summary&&$exit<$pack,'GET harus berhenti ke layar antrean sebelum jalur pembuat model.');
});

aiQueueTest('interview.authorizer_uses_database_candidate_and_session_owner', static function (): void {
    $pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE medical_applicants(id INTEGER PRIMARY KEY,status TEXT,recruitment_type TEXT)");
    $pdo->exec("INSERT INTO medical_applicants VALUES(340,'ai_completed','assistant_manager')");
    $prepared=ems_ai_queue_prepare_submission('candidate_interview_prepare',7,['applicant_id'=>340,'hr_id'=>7,'recruitment_type'=>'tampered'],$pdo);
    aiQueueAssertSame(['applicant_id'=>340,'hr_id'=>7,'recruitment_type'=>'assistant_manager'],$prepared['references']);
    try { ems_ai_queue_prepare_submission('candidate_interview_prepare',8,['applicant_id'=>340,'hr_id'=>7],$pdo); throw new RuntimeException('Pemilik yang berbeda diterima.'); }
    catch (InvalidArgumentException) {}
});
