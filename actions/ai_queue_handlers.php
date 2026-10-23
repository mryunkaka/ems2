<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/ai_queue.php';
require_once __DIR__ . '/ai_recruitment_service.php';
require_once __DIR__ . '/../config/recruitment_profiles.php';
require_once __DIR__ . '/ai_scoring_engine.php';
require_once __DIR__ . '/../config/roxy_chatbot.php';
require_once __DIR__ . '/../config/training_groups.php';
require_once __DIR__ . '/../config/birthday_helper.php';

ems_ai_queue_register_job_type('candidate_interview_prepare','Persiapan interview kandidat',static function(?PDO $pdo,int $requesterId,array $input):array {
    if (!$pdo) throw new InvalidArgumentException('Koneksi data tidak tersedia.');
    $applicantId=(int)($input['applicant_id']??0); $hrId=(int)($input['hr_id']??0);
    if ($applicantId<=0 || $hrId!==$requesterId) throw new InvalidArgumentException('Rujukan kandidat tidak valid.');
    $stmt=$pdo->prepare('SELECT id,status,recruitment_type FROM medical_applicants WHERE id=? LIMIT 1'); $stmt->execute([$applicantId]); $candidate=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$candidate || !in_array((string)$candidate['status'],['ai_completed','interview'],true)) throw new InvalidArgumentException('Kandidat tidak tersedia untuk interview.');
    $type=ems_normalize_recruitment_type($candidate['recruitment_type']??'medical_candidate');
    return ['references'=>['applicant_id'=>$applicantId,'hr_id'=>$hrId,'recruitment_type'=>$type],'dedupe_key'=>"candidate_interview:{$applicantId}:{$hrId}:{$type}"];
});

ems_ai_queue_register_handler('candidate_interview_prepare',static function(PDO $pdo,array $job,array $refs):array {
    if ((int)($refs['hr_id']??0)!==(int)$job['requester_id']) throw new RuntimeException('Pemilik pekerjaan tidak sesuai.');
    return ems_ai_prepare_candidate_interview($pdo,(int)$refs['applicant_id'],(int)$refs['hr_id']);
});

ems_ai_queue_register_handler('candidate_detail_prepare',static function(PDO $pdo,array $job,array $refs):array {
    $actorId=(int)$job['requester_id'];$applicantId=(int)($refs['applicant_id']??0);
    if($applicantId<=0||$actorId!==(int)($refs['requester_id']??0))throw new RuntimeException('Referensi assessment tidak valid.');
    $stmt=$pdo->prepare('SELECT role FROM user_rh WHERE id=? LIMIT 1');$stmt->execute([$actorId]);$role=(string)($stmt->fetchColumn()?:'');
    if($role===''||strtolower($role)==='staff')throw new RuntimeException('Akses detail kandidat tidak lagi berlaku.');
    return ems_ai_prepare_candidate_detail($pdo,$applicantId,$actorId,!empty($refs['summary']),!empty($refs['audit']));
});

ems_ai_queue_register_handler('roxy_chat',static function(PDO $pdo,array $job,array $refs):array {
    $conversationId=(int)($refs['conversation_id']??0); $userMessageId=(int)($refs['user_message_id']??0); $ownerId=(int)$job['requester_id'];
    if($conversationId<=0||$userMessageId<=0) throw new RuntimeException('Referensi percakapan Roxy tidak valid.');
    $stmt=$pdo->prepare('SELECT id,user_id,unit_code FROM bot_conversations WHERE id=? LIMIT 1'); $stmt->execute([$conversationId]); $conversation=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$conversation||(int)$conversation['user_id']!==$ownerId||(string)$conversation['unit_code']!==(string)($refs['unit_code']??'')) throw new RuntimeException('Percakapan Roxy tidak lagi dapat diakses.');
    $stmt=$pdo->prepare("SELECT id,user_id,content FROM bot_messages WHERE id=? AND conversation_id=? AND sender='user' LIMIT 1"); $stmt->execute([$userMessageId,$conversationId]); $userMessage=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$userMessage||(int)$userMessage['user_id']!==$ownerId) throw new RuntimeException('Pesan Roxy tidak tersedia.');
    $stmt=$pdo->prepare('SELECT id,content,answer_source,expression_tag FROM bot_messages WHERE conversation_id=? AND sender=\'bot\' AND reply_to_message_id=? LIMIT 1'); $stmt->execute([$conversationId,$userMessageId]);
    if($existing=$stmt->fetch(PDO::FETCH_ASSOC)) return ['conversation_id'=>$conversationId,'message_id'=>(int)$existing['id'],'answer'=>(string)$existing['content'],'answer_source'=>$existing['answer_source'],'expression'=>$existing['expression_tag'],'used_deep_research'=>false,'gemini_key_missing'=>false];
    $stmt=$pdo->prepare('SELECT * FROM user_rh WHERE id=? LIMIT 1'); $stmt->execute([$ownerId]); $user=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$user) throw new RuntimeException('Pengguna Roxy tidak lagi tersedia.');
    $stmt=$pdo->prepare('SELECT * FROM bot_messages WHERE conversation_id=? AND id<? ORDER BY id ASC'); $stmt->execute([$conversationId,$userMessageId]); $history=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $result=ems_roxy_ask($pdo,$user,(string)$conversation['unit_code'],$history,(string)$userMessage['content']);
    if(empty($result['ok'])) throw new RuntimeException((string)($result['message']??'Roxy tidak dapat menjawab.'));
    if(function_exists('ems_reconnect_database_if_needed')) ems_reconnect_database_if_needed($pdo);
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare('SELECT id FROM bot_messages WHERE conversation_id=? AND sender=\'bot\' AND reply_to_message_id=? LIMIT 1'); $stmt->execute([$conversationId,$userMessageId]);
        $botId=(int)($stmt->fetchColumn()?:0);
        if($botId===0) $botId=ems_roxy_save_message($pdo,$conversationId,$ownerId,'bot',(string)$result['answer'],(string)$result['answer_source'],(string)$result['expression'],$userMessageId);
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    return ['conversation_id'=>$conversationId,'message_id'=>$botId,'answer'=>(string)$result['answer'],'expression'=>(string)$result['expression'],'answer_source'=>(string)$result['answer_source'],'used_deep_research'=>!empty($result['used_deep_research']),'gemini_key_missing'=>!empty($result['gemini_key_missing']),'provider_name'=>$result['provider_name']??null];
});

ems_ai_queue_register_handler('training_group_generate',static function(PDO $pdo,array $job,array $refs):array {
    $stmt=$pdo->prepare('SELECT * FROM user_rh WHERE id=? LIMIT 1'); $stmt->execute([(int)$job['requester_id']]); $actor=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$actor||!ems_training_is_manager_role((string)($actor['role']??''))) throw new RuntimeException('Akses pembuatan grup tidak lagi berlaku.');
    $unit=ems_effective_unit($pdo,$actor); $batch=(int)($refs['batch']??0); $size=(int)($refs['group_size']??0);
    if($unit!== (string)($refs['unit_code']??'') || $batch<1 || $batch>26 || $size<1 || $size>20) throw new RuntimeException('Parameter pembuatan grup tidak valid.');
    $onlineIds=array_map(static fn(array $row):int=>(int)$row['id'],ems_training_fetch_online_managers($pdo,$unit));
    $managerIds=array_values(array_intersect(array_unique(array_map('intval',(array)($refs['preferred_manager_ids']??[]))),$onlineIds));
    $result=ems_training_generate_groups($pdo,$unit,$batch,$size,$managerIds,(int)$job['requester_id']);
    return ['batch'=>$batch,'group_count'=>(int)$result['group_count'],'trainee_count'=>(int)$result['trainee_count'],'manager_count'=>(int)$result['manager_count']];
});

ems_ai_queue_register_handler('birthday_message',static function(PDO $pdo,array $job,array $refs):array {
    $userId=(int)($refs['user_id']??0); $dateKey=(string)($refs['date_key']??'');
    if($userId!==(int)$job['requester_id'] || $dateKey!==(new DateTimeImmutable('now'))->format('Y-m-d')) throw new RuntimeException('Permintaan ucapan ulang tahun sudah kedaluwarsa.');
    if(ems_birthday_evening_inbox_exists($pdo,$userId)) return ['sent'=>false,'already_exists'=>true];
    $stmt=$pdo->prepare('SELECT id,full_name,tanggal_lahir_ic FROM user_rh WHERE id=? AND is_active=1 LIMIT 1'); $stmt->execute([$userId]); $person=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$person || !ems_birthday_viewer_is_celebrating_today($pdo,$userId)) throw new RuntimeException('Data ulang tahun tidak tersedia.');
    $message=ems_birthday_generate_message($pdo,$person);
    if(!ems_birthday_evening_inbox_exists($pdo,$userId)) sendInbox($pdo,$userId,ems_birthday_inbox_title($person),$message,'birthday_evening');
    return ['sent'=>true,'date_key'=>$dateKey];
});

ems_ai_queue_register_handler('provider_connection_test',static function(PDO $pdo,array $job,array $refs):array {
    $scope=(string)($refs['scope']??''); $provider=(string)($refs['provider']??''); $userId=(int)$job['requester_id'];
    if(!in_array($scope,['global','personal'],true)||!in_array($provider,['gemini','openai_compatible','groq'],true)) throw new RuntimeException('Jenis uji koneksi tidak valid.');
    if($scope==='global') {
        $settings=ems_ai_get_settings($pdo);
        if(!ems_ai_global_provider_ready($pdo)||strtolower((string)$settings['provider'])!==$provider) throw new RuntimeException('Simpan dan aktifkan provider global yang akan diuji terlebih dahulu.');
        if($provider==='gemini') $result=ems_gemini_test_connection($pdo,$settings,$userId);
        else $result=ems_custom_test_connection($pdo,$settings,$userId);
        return ['ok'=>true,'scope'=>$scope,'provider'=>$provider,'model'=>(string)($result['model']??''),'message'=>trim((string)($result['content']??$result['text']??''))];
    }
    $personal=ems_ai_provider_get_user_settings($pdo,$userId);
    if(!$personal) throw new RuntimeException('Simpan pengaturan provider pribadi terlebih dahulu.');
    if($provider==='gemini') {
        if(trim((string)($personal['gemini_api_key']??''))==='') throw new RuntimeException('API key Gemini pribadi belum tersimpan.');
        $settings=array_merge(ems_ai_settings_defaults(),$personal,['provider'=>'gemini','is_enabled'=>1,'daily_request_limit'=>0,'default_model'=>$personal['default_model']?:'gemini-3.5-flash-lite']);
        $result=ems_gemini_test_connection($pdo,$settings,$userId);
        return ['ok'=>true,'scope'=>$scope,'provider'=>$provider,'model'=>(string)($result['model']??''),'message'=>trim((string)($result['text']??''))];
    }
    if($provider==='openai_compatible') {
        if(trim((string)($personal['custom_api_key']??''))===''||trim((string)($personal['custom_base_url']??''))==='') throw new RuntimeException('Kredensial OpenAI-Compatible pribadi belum tersimpan.');
        $result=ems_custom_test_connection($pdo,$personal,$userId);
    } else {
        $groq=ems_groq_get_user_settings($pdo,$userId)??[];
        if(trim((string)($groq['groq_api_key']??''))==='') throw new RuntimeException('API key Groq pribadi belum tersimpan.');
        $result=ems_groq_test_connection($pdo,$groq,$userId);
    }
    return ['ok'=>true,'scope'=>$scope,'provider'=>$provider,'model'=>(string)($result['model']??''),'message'=>trim((string)($result['content']??''))];
});

ems_ai_queue_register_handler('work_anniversary_generate',static function(PDO $pdo,array $job,array $refs):array {
    $requesterId=(int)$job['requester_id']; $userId=(int)($refs['user_id']??0); $year=(int)($refs['anniversary_year']??0);
    if($requesterId!==(int)($refs['requester_id']??0)||$userId<=0||$year!==(int)(new DateTimeImmutable('today',new DateTimeZone('Asia/Jakarta')))->format('Y')) throw new RuntimeException('Permintaan apresiasi masa kerja kedaluwarsa.');
    $stmt=$pdo->prepare('SELECT * FROM user_rh WHERE id=? LIMIT 1'); $stmt->execute([$requesterId]); $actor=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$actor) throw new RuntimeException('Peminta apresiasi tidak tersedia.');
    $oldGet=$_GET??[]; $oldUri=$_SERVER['REQUEST_URI']??null; $oldSelf=$_SERVER['PHP_SELF']??null;
    if(session_status()===PHP_SESSION_NONE) session_start();
    $_SESSION['user_rh']=['id'=>$requesterId,'full_name'=>(string)($actor['full_name']??''),'name'=>(string)($actor['full_name']??''),'role'=>(string)($actor['role']??''),'position'=>(string)($actor['position']??''),'unit_code'=>(string)($actor['unit_code']??'')]; session_write_close();
    $_GET=['mode'=>'generate','user_id'=>$userId]; $_SERVER['REQUEST_URI']='/actions/work_anniversary_notices.php?mode=generate&user_id='.$userId; $_SERVER['PHP_SELF']=__DIR__.'/work_anniversary_notices.php';
    ob_start();
    try { include __DIR__.'/work_anniversary_notices.php'; $output=(string)ob_get_clean(); }
    catch(Throwable $e) { if(ob_get_level()>0)ob_end_clean(); throw $e; }
    $_GET=$oldGet; if($oldUri===null)unset($_SERVER['REQUEST_URI']);else $_SERVER['REQUEST_URI']=$oldUri; if($oldSelf===null)unset($_SERVER['PHP_SELF']);else $_SERVER['PHP_SELF']=$oldSelf;
    $result=json_decode($output,true);
    if(!is_array($result)||empty($result['success'])||empty($result['items'][0])) throw new RuntimeException('Pembuatan apresiasi masa kerja belum berhasil.');
    return ['year'=>$year,'date_key'=>$result['date_key']??null,'items'=>$result['items']];
});

function ems_ai_queue_register_job_type(string $jobType, string $featureLabel, callable $prepare): void
{
    $GLOBALS['ems_ai_queue_job_types'][$jobType] = ['label' => $featureLabel, 'prepare' => $prepare];
}

function ems_ai_queue_prepare_submission(string $jobType, int $requesterId, array $references, ?PDO $pdo = null): array
{
    $definition = $GLOBALS['ems_ai_queue_job_types'][$jobType] ?? null;
    if (!is_array($definition) || !is_callable($definition['prepare'] ?? null)) throw new InvalidArgumentException('Jenis pekerjaan AI tidak diizinkan.');
    $prepared = ($definition['prepare'])($pdo, $requesterId, $references);
    if (!is_array($prepared) || !isset($prepared['references'], $prepared['dedupe_key']) || !is_array($prepared['references'])) throw new InvalidArgumentException('Referensi pekerjaan tidak valid.');
    return ['job_type' => $jobType, 'feature_label' => $definition['label'], 'references' => $prepared['references'], 'dedupe_key' => (string)$prepared['dedupe_key']];
}

function ems_ai_queue_register_handler(string $jobType, callable $handler): void
{
    $GLOBALS['ems_ai_queue_handlers'][$jobType] = $handler;
}

function ems_ai_queue_handle(PDO $pdo, array $job, ?array $references = null): array
{
    $references ??= json_decode((string)($job['references_json'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($references)) throw new RuntimeException('Data referensi pekerjaan tidak valid.');
    $handler = $GLOBALS['ems_ai_queue_handlers'][$job['job_type'] ?? ''] ?? null;
    if (!is_callable($handler)) throw new RuntimeException('Jenis pekerjaan AI belum didukung.');
    $result = $handler($pdo, $job, $references);
    if (!is_array($result)) throw new RuntimeException('Handler pekerjaan mengembalikan hasil tidak valid.');
    return $result;
}
