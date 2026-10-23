<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/ai_queue.php';
require_once __DIR__ . '/../../actions/ai_queue_handlers.php';
require_once __DIR__ . '/../../auth/csrf.php';

aiQueueTest('http.job_type_requires_registered_authorizer', static function (): void {
    try { ems_ai_queue_prepare_submission('not_allowed', 10, []); throw new RuntimeException('Job tak dikenal diterima.'); }
    catch (InvalidArgumentException) {}
});

aiQueueTest('http.authorizer_sets_server_owned_label_and_dedupe', static function (): void {
    ems_ai_queue_register_job_type('safe_test', 'Label aman', static fn(?PDO $db, int $user, array $refs): array => ['references'=>['record_id'=>(int)($refs['record_id']??0)],'dedupe_key'=>'record:'.(int)($refs['record_id']??0)]);
    $prepared=ems_ai_queue_prepare_submission('safe_test',10,['record_id'=>7,'feature_label'=>'admin supplied'],null);
    aiQueueAssertSame('Label aman',$prepared['feature_label']);
    aiQueueAssertSame(['record_id'=>7],$prepared['references']);
    aiQueueAssertSame('record:7',$prepared['dedupe_key']);
});

aiQueueTest('http.continuation_rejects_external_paths', static function (): void {
    aiQueueAssertSame('/dashboard/ai_assistant.php', ems_ai_queue_safe_return_path('/dashboard/ai_assistant.php'));
    aiQueueAssertSame('', ems_ai_queue_safe_return_path('https://example.com'));
    aiQueueAssertSame('', ems_ai_queue_safe_return_path('//example.com'));
    aiQueueAssertSame('', ems_ai_queue_safe_return_path('/../../.env'));
});

aiQueueTest('http.csrf_requires_session_token', static function (): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['csrf_token']='expected-token';
    aiQueueAssertSame(true,validateCsrfToken('expected-token'));
    aiQueueAssertSame(false,validateCsrfToken('wrong-token'));
});

aiQueueTest('http.active_metadata_has_no_payload_fields', static function (): void {
    $pdo=aiQueueCoreDb();
    ems_ai_queue_enqueue($pdo,'test',4,'Fitur uji',['prompt'=>'rahasia'],'safe');
    $job=ems_ai_queue_claim($pdo);
    $status=ems_ai_queue_status($pdo,(int)$job['id'],4);
    aiQueueAssertSame(['requester_name','feature_label'],array_keys($status['active'][0]));
});
