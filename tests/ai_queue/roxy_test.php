<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/roxy_chatbot.php';
require_once __DIR__ . '/../../actions/ai_queue_handlers.php';

function roxyQueueDb(): PDO
{
    $pdo=aiQueueCoreDb();
    $pdo->exec("CREATE TABLE bot_conversations(id INTEGER PRIMARY KEY AUTOINCREMENT,unit_code TEXT,user_id INTEGER,title TEXT,status TEXT DEFAULT 'active',created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,last_message_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE bot_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,conversation_id INTEGER,user_id INTEGER,sender TEXT,content TEXT,reply_to_message_id INTEGER,answer_source TEXT,expression_tag TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE user_rh(id INTEGER PRIMARY KEY,full_name TEXT,unit_code TEXT,role TEXT)");
    $pdo->exec("INSERT INTO user_rh VALUES(7,'Pengguna Uji','unit-a','staff')");
    return $pdo;
}

aiQueueTest('roxy.persist_user_once_and_reuse_request', static function (): void {
    $pdo=roxyQueueDb();
    $first=ems_roxy_enqueue_message($pdo,7,'unit-a',null,'Apa kabar?','0123456789abcdef0123456789abcdef');
    $again=ems_roxy_enqueue_message($pdo,7,'unit-a',null,'Apa kabar?','0123456789abcdef0123456789abcdef');
    aiQueueAssertSame($first['job_id'],$again['job_id']);
    aiQueueAssertSame($first['user_message_id'],$again['user_message_id']);
    aiQueueAssertSame(1,(int)$pdo->query("SELECT COUNT(*) FROM bot_messages WHERE sender='user'")->fetchColumn());
});

aiQueueTest('roxy.conversation_owner_and_unit_are_enforced', static function (): void {
    $pdo=roxyQueueDb();
    $pdo->exec("INSERT INTO bot_conversations(id,unit_code,user_id,title) VALUES(20,'unit-b',8,'Private')");
    $job=ems_roxy_enqueue_message($pdo,7,'unit-a',20,'Pertanyaan baru','abcdef0123456789abcdef0123456789');
    $stmt=$pdo->prepare('SELECT unit_code,user_id FROM bot_conversations WHERE id=?'); $stmt->execute([$job['conversation_id']]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    aiQueueAssertSame('unit-a',$row['unit_code']); aiQueueAssertSame(7,(int)$row['user_id']);
});

aiQueueTest('roxy.existing_reply_is_returned_without_duplicate_bot_message', static function (): void {
    $pdo=roxyQueueDb();
    $pdo->exec("INSERT INTO bot_conversations(id,unit_code,user_id,title) VALUES(1,'unit-a',7,'Test')");
    $pdo->exec("INSERT INTO bot_messages(id,conversation_id,user_id,sender,content) VALUES(10,1,7,'user','Halo')");
    $pdo->exec("INSERT INTO bot_messages(id,conversation_id,user_id,sender,content,reply_to_message_id) VALUES(11,1,7,'bot','Jawaban',10)");
    $result=ems_ai_queue_handle($pdo,['job_type'=>'roxy_chat','requester_id'=>7],['conversation_id'=>1,'user_message_id'=>10,'unit_code'=>'unit-a']);
    aiQueueAssertSame(11,$result['message_id']);
    aiQueueAssertSame(1,(int)$pdo->query("SELECT COUNT(*) FROM bot_messages WHERE sender='bot'")->fetchColumn());
});

aiQueueTest('roxy.pending_jobs_are_recoverable_after_refresh_for_owner_only', static function (): void {
    $pdo=roxyQueueDb();
    $pdo->exec("INSERT INTO bot_conversations(id,unit_code,user_id,title) VALUES(1,'unit-a',7,'Test')");
    $pdo->exec("INSERT INTO bot_messages(id,conversation_id,user_id,sender,content) VALUES(10,1,7,'user','Halo')");
    $job=ems_ai_queue_enqueue($pdo,'roxy_chat',7,'Chat Roxy',['conversation_id'=>1,'user_message_id'=>10,'unit_code'=>'unit-a'],'refresh-test');
    $pending=ems_roxy_get_pending_queue_jobs($pdo,1,7);
    aiQueueAssertSame(1,count($pending));
    aiQueueAssertSame($job['job_id'],$pending[0]['job_id']);
    aiQueueAssertSame(10,$pending[0]['user_message_id']);
    aiQueueAssertSame('queued',$pending[0]['status']);
    aiQueueAssertSame([] ,ems_roxy_get_pending_queue_jobs($pdo,1,8));
    $pdo->prepare("UPDATE system_ai_request_queue SET status='failed',completed_at=CURRENT_TIMESTAMP,error_message='Provider gagal' WHERE id=?")->execute([$job['job_id']]);
    $failed=ems_roxy_get_pending_queue_jobs($pdo,1,7);
    aiQueueAssertSame('failed',$failed[0]['status']);
});

aiQueueTest('roxy.global_provider_answer_source_is_supported_by_schema_and_migration', static function (): void {
    $schema = (string)file_get_contents(__DIR__ . '/../../config/roxy_chatbot.php');
    $migration = (string)file_get_contents(__DIR__ . '/../../docs/sql/106_2026-11-03_roxy_global_provider_answer_source.sql');
    aiQueueAssertTrue(str_contains($schema, "'global_provider'"), 'Skema baru harus menerima sumber global_provider.');
    aiQueueAssertTrue(str_contains($migration, "'global_provider'"), 'Database yang sudah ada harus dimigrasikan agar menerima sumber global_provider.');
});

aiQueueTest('roxy.sources_prefer_latest_official_document_and_render_as_numbered_list', static function (): void {
    $oldReference = 'https://roxwoodhospitalime.my.id/dashboard/document_view.php?id=43&q=query';
    $latestReference = 'https://roxwoodhospitalime.my.id/dashboard/document_view.php?id=44&q=query';
    $context = [
        ['source'=>'Dokumen resmi pembanding 1','title'=>'3. Peraturan dan Standar Operasional Prosedur (SOP) 10 Mei 2026','reference'=>$oldReference],
        ['source'=>'Dokumen resmi pembanding 2','title'=>'4. Peraturan dan Standar Operasional Prosedur (SOP) 11 Juli 2026','reference'=>$latestReference],
        ['_type'=>'official_document_comparison','comparison'=>[
            'documents'=>[
                ['id'=>43,'title'=>'3. Peraturan dan Standar Operasional Prosedur (SOP) 10 Mei 2026'],
                ['id'=>44,'title'=>'4. Peraturan dan Standar Operasional Prosedur (SOP) 11 Juli 2026'],
            ],
            'latest'=>['id'=>44],
        ]],
        ['source'=>'Panduan terkait','title'=>'Tes','reference'=>'https://example.org/guide'],
    ];

    $answer = ems_roxy_append_official_sources('Jawaban ringkas terkait 4. Peraturan dan Standar Operasional Prosedur (SOP) 11 Juli 2026 dan Tes.', $context);
    aiQueueAssertTrue(!str_contains($answer, $oldReference), 'Sumber SOP lama harus dikeluarkan dari daftar.');
    aiQueueAssertTrue(str_contains($answer, "**Sumber:**\n1. [4. Peraturan dan Standar Operasional Prosedur (SOP) 11 Juli 2026]({$latestReference})\n2. [Tes](https://example.org/guide)"), 'Sumber resmi terbaru dan sumber lain harus dirender sebagai daftar bernomor.');
});

aiQueueTest('roxy.omits_context_sources_when_answer_does_not_use_documents', static function (): void {
    $context = [
        ['source'=>'Dokumen resmi','title'=>'1. Buku Panduan Penggunaan Obat','reference'=>'https://roxwoodhospitalime.my.id/dashboard/document_view.php?id=18&q=halo'],
    ];
    $answer = 'Halo, Michael. Ada yang ingin ditanyakan tentang EMS2 atau SOP/roleplay medis Roxwood Hospital?';
    aiQueueAssertSame($answer, ems_roxy_append_official_sources($answer, $context));
});
