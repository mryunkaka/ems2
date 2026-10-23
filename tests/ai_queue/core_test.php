<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/ai_queue.php';
require_once __DIR__ . '/../../actions/ai_queue_handlers.php';

function aiQueueCoreDb(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE system_ai_request_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT, job_type TEXT NOT NULL, requester_id INTEGER NOT NULL,
        feature_label TEXT NOT NULL, references_json TEXT NOT NULL, result_json TEXT, status TEXT NOT NULL DEFAULT 'queued',
        dedupe_key TEXT NOT NULL, active_dedupe_key TEXT UNIQUE, error_message TEXT, worker_slot INTEGER,
        queued_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, started_at TEXT, completed_at TEXT, lease_expires_at TEXT
    )");
    $pdo->exec("CREATE TABLE system_ai_settings (id INTEGER PRIMARY KEY, is_enabled INTEGER DEFAULT 1, ai_max_concurrent_requests INTEGER DEFAULT 1, ai_max_queue_size INTEGER DEFAULT 50)");
    $pdo->exec("INSERT INTO system_ai_settings (id) VALUES (1)");
    return $pdo;
}

aiQueueTest('core.enqueue_fifo_dedupes_and_caps', static function (): void {
    $pdo = aiQueueCoreDb();
    $one = ems_ai_queue_enqueue($pdo, 'test', 4, 'Fitur uji', ['id' => 1], 'same');
    $again = ems_ai_queue_enqueue($pdo, 'test', 4, 'Fitur uji', ['id' => 1], 'same');
    aiQueueAssertSame($one['job_id'], $again['job_id']);
    aiQueueAssertSame(true, $again['reused']);
    $two = ems_ai_queue_enqueue($pdo, 'test', 5, 'Fitur uji', ['id' => 2], 'other');
    aiQueueAssertSame(2, ems_ai_queue_status($pdo, $two['job_id'], 5)['position']);
    $pdo->exec('UPDATE system_ai_settings SET ai_max_queue_size = 10');
    for ($i = 0; $i < 8; $i++) ems_ai_queue_enqueue($pdo, 'test', 20 + $i, 'Fitur uji', ['id' => $i], 'key-' . $i);
    try { ems_ai_queue_enqueue($pdo, 'test', 99, 'Fitur uji', [], 'overflow'); throw new RuntimeException('Antrean penuh tidak ditolak.'); }
    catch (OverflowException) {}
});

aiQueueTest('core.ownership_and_terminal_transition', static function (): void {
    $pdo = aiQueueCoreDb();
    $job = ems_ai_queue_enqueue($pdo, 'test', 4, 'Fitur uji', [], 'key');
    try { ems_ai_queue_status($pdo, $job['job_id'], 8); throw new RuntimeException('Status lintas pemilik diterima.'); }
    catch (RuntimeException $e) { aiQueueAssertTrue(str_contains($e->getMessage(), 'tidak ditemukan'), 'Akses lintas pemilik harus terlihat seperti job tidak ada.'); }
    $pdo->prepare("UPDATE system_ai_request_queue SET status='completed', result_json=? WHERE id=?")->execute([json_encode(['ok'=>true]), $job['job_id']]);
    aiQueueAssertSame(['ok' => true], ems_ai_queue_status($pdo, $job['job_id'], 4)['result']);
});

aiQueueTest('core.expired_jobs_fail_without_retry', static function (): void {
    $pdo = aiQueueCoreDb();
    $pdo->exec("INSERT INTO system_ai_request_queue (job_type,requester_id,feature_label,references_json,status,dedupe_key,active_dedupe_key,started_at,lease_expires_at) VALUES ('x',1,'X','{}','running','k','k',datetime('now','-2 hour'),datetime('now','-1 hour'))");
    ems_ai_queue_recover_expired($pdo);
    aiQueueAssertSame('failed', $pdo->query('SELECT status FROM system_ai_request_queue')->fetchColumn());
});

aiQueueTest('core.provider_call_is_blocked_outside_worker_context', static function (): void {
    try { ems_gemini_generate_content_direct(aiQueueCoreDb(),[],[],null,'test'); throw new RuntimeException('Provider dapat dipanggil dari request non-worker.'); }
    catch (RuntimeException $e) { aiQueueAssertTrue(str_contains($e->getMessage(),'melalui antrean AI'),'Client harus menolak pemanggilan di luar worker.'); }
});

aiQueueTest('core.worker_completes_registered_handler', static function (): void {
    $pdo = aiQueueCoreDb();
    ems_ai_queue_register_handler('test', static fn(PDO $db, array $job, array $refs): array => ['value' => $refs['value']]);
    $job = ems_ai_queue_enqueue($pdo, 'test', 4, 'Fitur uji', ['value' => 42], 'worker-key');
    if (!defined('EMS_AI_QUEUE_WORKER')) define('EMS_AI_QUEUE_WORKER', true);
    aiQueueAssertSame(1, ems_ai_queue_run($pdo, 1)['processed']);
    aiQueueAssertSame(['value' => 42], ems_ai_queue_status($pdo, $job['job_id'], 4)['result']);
});

aiQueueTest('core.browser_worker_processes_at_most_one_job', static function (): void {
    $pdo = aiQueueCoreDb();
    if (!defined('EMS_AI_QUEUE_WORKER')) define('EMS_AI_QUEUE_WORKER', true);
    ems_ai_queue_register_handler('browser_test', static fn(PDO $db, array $job, array $refs): array => ['value'=>$refs['value']]);
    $first=ems_ai_queue_enqueue($pdo,'browser_test',4,'Fitur uji',['value'=>1],'browser-one');
    $second=ems_ai_queue_enqueue($pdo,'browser_test',5,'Fitur uji',['value'=>2],'browser-two');
    aiQueueAssertSame(['processed'=>1],ems_ai_queue_run_one($pdo));
    aiQueueAssertSame('completed',$pdo->query('SELECT status FROM system_ai_request_queue WHERE id='.(int)$first['job_id'])->fetchColumn());
    aiQueueAssertSame('queued',$pdo->query('SELECT status FROM system_ai_request_queue WHERE id='.(int)$second['job_id'])->fetchColumn());
});

aiQueueTest('core.browser_worker_returns_idle_for_empty_queue_or_full_slots', static function (): void {
    $pdo=aiQueueCoreDb();
    if (!defined('EMS_AI_QUEUE_WORKER')) define('EMS_AI_QUEUE_WORKER',true);
    aiQueueAssertSame(['processed'=>0],ems_ai_queue_run_one($pdo));
    $pdo->exec("INSERT INTO system_ai_request_queue(job_type,requester_id,feature_label,references_json,status,dedupe_key,active_dedupe_key,started_at,lease_expires_at) VALUES('test',1,'Aktif','{}','running','running','running',CURRENT_TIMESTAMP,datetime('now','+1 hour'))");
    ems_ai_queue_enqueue($pdo,'test',2,'Menunggu',[],'waiting');
    aiQueueAssertSame(['processed'=>0],ems_ai_queue_run_one($pdo));
    aiQueueAssertSame('queued',$pdo->query("SELECT status FROM system_ai_request_queue WHERE requester_id=2")->fetchColumn());
});

aiQueueTest('core.worker_actor_context_does_not_overwrite_browser_session', static function (): void {
    $pdo=aiQueueCoreDb();
    if (!defined('EMS_AI_QUEUE_WORKER')) define('EMS_AI_QUEUE_WORKER',true);
    $_SESSION['user_rh']=['id'=>8,'role'=>'User'];
    ems_ai_queue_register_handler('actor_test',static fn(PDO $db,array $job,array $refs):array=>[
        'actor_id'=>ems_ai_queue_actor_id(),
        'session_user_id'=>(int)($_SESSION['user_rh']['id']??0),
    ]);
    $job=ems_ai_queue_enqueue($pdo,'actor_test',44,'Fitur uji',[],'actor-context');
    ems_ai_queue_run_one($pdo);
    $result=ems_ai_queue_status($pdo,$job['job_id'],44)['result'];
    aiQueueAssertSame(['actor_id'=>44,'session_user_id'=>8],$result);
    aiQueueAssertSame(8,(int)$_SESSION['user_rh']['id']);
});

aiQueueTest('core.worker_actor_skips_browser_auth_only_with_a_valid_actor', static function (): void {
    if (!defined('EMS_AI_QUEUE_WORKER')) define('EMS_AI_QUEUE_WORKER',true);
    unset($GLOBALS['ems_ai_queue_actor_id']);
    $previousUser=$_SESSION['user_rh']??null;
    unset($_SESSION['user_rh']);
    aiQueueAssertSame(false,ems_ai_queue_can_skip_request_auth());
    $GLOBALS['ems_ai_queue_actor_id']=44;
    aiQueueAssertSame(true,ems_ai_queue_can_skip_request_auth());
    unset($GLOBALS['ems_ai_queue_actor_id']);
    if ($previousUser===null) unset($_SESSION['user_rh']); else $_SESSION['user_rh']=$previousUser;
});
