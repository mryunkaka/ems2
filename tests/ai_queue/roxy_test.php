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
