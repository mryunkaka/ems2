<?php
declare(strict_types=1);

require_once __DIR__ . '/ai_settings.php';

function ems_ai_queue_safe_return_path(string $path): string
{
    $path = trim($path);
    if ($path === '' || str_contains($path, "\\") || preg_match('/[\x00-\x1F\x7F]/', $path)) return '';
    $parts = parse_url($path);
    if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || !str_starts_with((string)($parts['path'] ?? ''), '/dashboard/')) return '';
    if (preg_match('/(?:^|\/)\.\.(?:\/|$)/', rawurldecode((string)$parts['path']))) return '';
    if (!preg_match('~^/dashboard/[A-Za-z0-9_./-]+\.php$~', (string)$parts['path'])) return '';
    return (string)$parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
}

function ems_ai_queue_settings(PDO $pdo, bool $forUpdate = false): array
{
    $defaults = ['is_enabled' => 1, 'ai_max_concurrent_requests' => 1, 'ai_max_queue_size' => 50, 'timeout_seconds' => 30];
    try {
        $lock = $forUpdate && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' ? ' FOR UPDATE' : '';
        $row = $pdo->query('SELECT * FROM system_ai_settings ORDER BY id LIMIT 1'.$lock)->fetch(PDO::FETCH_ASSOC);
        if(!$row) return array_merge($defaults,['is_enabled'=>0]);
        return array_merge($defaults, ems_ai_queue_settings_normalize(array_merge($defaults, $row)));
    } catch (Throwable) { return array_merge($defaults,['is_enabled'=>0]); }
}

function ems_ai_queue_enqueue(PDO $pdo, string $jobType, int $requesterId, string $featureLabel, array $references, string $dedupeKey): array
{
    $jobType = trim($jobType); $featureLabel = trim($featureLabel);
    if ($jobType === '' || strlen($jobType) > 80 || $requesterId <= 0 || $featureLabel === '' || strlen($featureLabel) > 150) throw new InvalidArgumentException('Permintaan antrean tidak valid.');
    $payload = json_encode($references, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (strlen($payload) > 32768 || strlen($dedupeKey) > 256 || trim($dedupeKey) === '') throw new InvalidArgumentException('Referensi antrean tidak valid atau terlalu besar.');
    $hash = hash('sha256', $dedupeKey);
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id,status FROM system_ai_request_queue WHERE active_dedupe_key = ? LIMIT 1');
        $stmt->execute([$hash]);
        if ($existing = $stmt->fetch(PDO::FETCH_ASSOC)) { if ($ownsTransaction) $pdo->commit(); return ['job_id'=>(int)$existing['id'],'status'=>$existing['status'],'reused'=>true]; }
        $settings = ems_ai_queue_settings($pdo, true);
        if (empty($settings['is_enabled'])) throw new RuntimeException('AI sedang nonaktif.');
        $count = $driver === 'sqlite'
            ? (int)$pdo->query("SELECT COUNT(*) FROM system_ai_request_queue WHERE status='queued'")->fetchColumn()
            : count($pdo->query("SELECT id FROM system_ai_request_queue WHERE status='queued' FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN));
        if ($count >= (int)$settings['ai_max_queue_size']) throw new OverflowException('Antrean AI penuh. Silakan coba lagi beberapa saat.');
        $stmt = $pdo->prepare("INSERT INTO system_ai_request_queue (job_type,requester_id,feature_label,references_json,status,dedupe_key,active_dedupe_key) VALUES (?,?,?,?, 'queued',?,?)");
        $stmt->execute([$jobType,$requesterId,$featureLabel,$payload,$hash,$hash]);
        $id = (int)$pdo->lastInsertId(); if ($ownsTransaction) $pdo->commit();
        return ['job_id'=>$id,'status'=>'queued','reused'=>false];
    } catch (PDOException $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        if (str_contains(strtolower($e->getMessage()), 'unique')) {
            $stmt = $pdo->prepare('SELECT id,status FROM system_ai_request_queue WHERE active_dedupe_key = ?'); $stmt->execute([$hash]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) return ['job_id'=>(int)$row['id'],'status'=>$row['status'],'reused'=>true];
        }
        throw $e;
    } catch (Throwable $e) { if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function ems_ai_queue_status(PDO $pdo, int $jobId, int $requesterId): array
{
    $stmt = $pdo->prepare('SELECT * FROM system_ai_request_queue WHERE id=? AND requester_id=?'); $stmt->execute([$jobId,$requesterId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Pekerjaan tidak ditemukan.');
    $position = null; $ahead = [];
    if ($row['status'] === 'queued') {
        $q = $pdo->prepare("SELECT COUNT(*) FROM system_ai_request_queue WHERE status='queued' AND (queued_at < ? OR (queued_at = ? AND id <= ?))");
        $q->execute([$row['queued_at'],$row['queued_at'],$jobId]); $position = (int)$q->fetchColumn();
        $aheadSql = "SELECT q.feature_label,q.queued_at,COALESCE(NULLIF(u.full_name,''),'Pengguna') AS requester_name FROM system_ai_request_queue q LEFT JOIN user_rh u ON u.id=q.requester_id WHERE q.status='queued' AND (q.queued_at < ? OR (q.queued_at = ? AND q.id < ?)) ORDER BY q.queued_at,q.id";
        try {
            $q = $pdo->prepare($aheadSql);
            $q->execute([$row['queued_at'],$row['queued_at'],$jobId]);
            $ahead = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            $q = $pdo->prepare("SELECT feature_label,queued_at,'Pengguna' AS requester_name FROM system_ai_request_queue WHERE status='queued' AND (queued_at < ? OR (queued_at = ? AND id < ?)) ORDER BY queued_at,id");
            $q->execute([$row['queued_at'],$row['queued_at'],$jobId]);
            $ahead = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }
    try {
        $active = $pdo->query("SELECT COALESCE(NULLIF(u.full_name,''),'Pengguna') AS requester_name,q.feature_label,q.started_at FROM system_ai_request_queue q LEFT JOIN user_rh u ON u.id=q.requester_id WHERE q.status='running' ORDER BY q.started_at,q.id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        $active = $pdo->query("SELECT 'Pengguna' AS requester_name,feature_label,started_at FROM system_ai_request_queue WHERE status='running' ORDER BY started_at,id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $now = time();
    $waitingSeconds = $row['status'] === 'queued' ? max(0, $now - (strtotime((string)$row['queued_at']) ?: $now)) : 0;
    foreach ($ahead as &$item) $item['waited_seconds'] = max(0, $now - (strtotime((string)$item['queued_at']) ?: $now));
    unset($item);
    foreach ($active as &$item) {
        $item['running_seconds'] = max(0, $now - (strtotime((string)$item['started_at']) ?: $now));
        unset($item['started_at']);
    }
    unset($item);

    $durations = [];
    try {
        $recent = $pdo->query("SELECT started_at,completed_at FROM system_ai_request_queue WHERE status='completed' AND started_at IS NOT NULL AND completed_at IS NOT NULL ORDER BY completed_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($recent as $completed) {
            $duration = (strtotime((string)$completed['completed_at']) ?: 0) - (strtotime((string)$completed['started_at']) ?: 0);
            if ($duration > 0) $durations[] = $duration;
        }
    } catch (Throwable) {
        $durations = [];
    }
    $averageJobSeconds = $durations === [] ? 60 : (int)round(array_sum($durations) / count($durations));
    $averageJobSeconds = max(15, min(600, $averageJobSeconds));
    $settings = ems_ai_queue_settings($pdo);
    $concurrency = max(1, (int)($settings['ai_max_concurrent_requests'] ?? 1));
    $estimatedWaitSeconds = $row['status'] === 'queued'
        ? (int)(ceil((count($ahead) + count($active)) / $concurrency) * $averageJobSeconds)
        : 0;
    $jobRunningSeconds = $row['status'] === 'running' ? max(0, $now - (strtotime((string)($row['started_at'] ?? '')) ?: $now)) : 0;
    $out = [
        'job_id'=>(int)$row['id'],
        'status'=>$row['status'],
        'feature_label'=>$row['feature_label'],
        'position'=>$position,
        'waited_seconds'=>$waitingSeconds,
        'estimated_wait_seconds'=>$estimatedWaitSeconds,
        'job_running_seconds'=>$jobRunningSeconds,
        'worker_delayed'=>$row['status'] === 'queued' && $waitingSeconds >= 75 && $active === [],
        'queue_ahead'=>$ahead,
        'active'=>$active,
    ];
    if ($row['status'] === 'completed') $out['result'] = json_decode((string)$row['result_json'], true);
    if ($row['status'] === 'failed') $out['error'] = $row['error_message'] ?: 'Pekerjaan AI gagal.';
    return $out;
}

function ems_ai_queue_monitor(PDO $pdo): array
{
    $counts = ['queued'=>0,'running'=>0,'completed'=>0,'failed'=>0];
    foreach ($pdo->query('SELECT status,COUNT(*) AS n FROM system_ai_request_queue GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $row) $counts[$row['status']] = (int)$row['n'];
    try { $active=$pdo->query("SELECT u.full_name AS requester_name,q.feature_label,q.started_at FROM system_ai_request_queue q LEFT JOIN user_rh u ON u.id=q.requester_id WHERE q.status='running' ORDER BY q.started_at,q.id")->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch(Throwable) { $active=[]; }
    return ['counts'=>$counts,'active'=>$active];
}

function ems_ai_queue_provider_test(PDO $pdo, int $requesterId, string $scope, string $provider): array
{
    if($requesterId<=0||!in_array($scope,['global','personal'],true)||!in_array($provider,['gemini','openai_compatible','groq'],true)) throw new InvalidArgumentException('Pilihan uji koneksi tidak valid.');
    return ems_ai_queue_enqueue($pdo,'provider_connection_test',$requesterId,'Uji koneksi provider AI',['scope'=>$scope,'provider'=>$provider],'provider-test:'.$scope.':'.$requesterId.':'.$provider);
}

function ems_ai_queue_recover_expired(PDO $pdo): int
{
    $stmt = $pdo->prepare("UPDATE system_ai_request_queue SET status='failed',active_dedupe_key=NULL,error_message='Worker melewati batas waktu. Kirim ulang permintaan jika masih diperlukan.',completed_at=CURRENT_TIMESTAMP,worker_slot=NULL WHERE status='running' AND lease_expires_at < CURRENT_TIMESTAMP");
    $stmt->execute(); return $stmt->rowCount();
}

function ems_ai_queue_claim(PDO $pdo): ?array
{
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    try {
        $pdo->beginTransaction();
        $settings = ems_ai_queue_settings($pdo, true);
        if (empty($settings['is_enabled'])) {
            $pdo->exec("UPDATE system_ai_request_queue SET status='failed',active_dedupe_key=NULL,error_message='AI dinonaktifkan administrator.',completed_at=CURRENT_TIMESTAMP WHERE status='queued'");
            $pdo->commit(); return null;
        }
        $running = $driver === 'sqlite'
            ? (int)$pdo->query("SELECT COUNT(*) FROM system_ai_request_queue WHERE status='running'")->fetchColumn()
            : count($pdo->query("SELECT id FROM system_ai_request_queue WHERE status='running' FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN));
        if ($running >= (int)$settings['ai_max_concurrent_requests']) { $pdo->commit(); return null; }
        $row = $pdo->query("SELECT * FROM system_ai_request_queue WHERE status='queued' ORDER BY queued_at,id LIMIT 1" . ($driver === 'sqlite' ? '' : ' FOR UPDATE'))->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $pdo->commit(); return null; }
        $lease = max(90, (int)$settings['timeout_seconds'] + 30);
        $sql = $driver === 'sqlite'
            ? "UPDATE system_ai_request_queue SET status='running',started_at=CURRENT_TIMESTAMP,lease_expires_at=datetime('now', '+' || ? || ' seconds') WHERE id=? AND status='queued'"
            : "UPDATE system_ai_request_queue SET status='running',started_at=CURRENT_TIMESTAMP,lease_expires_at=DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? SECOND) WHERE id=? AND status='queued'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$lease,$row['id']]);
        if ($stmt->rowCount() !== 1) { $pdo->rollBack(); return null; }
        $pdo->commit(); $row['status']='running'; return $row;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function ems_ai_queue_heartbeat(PDO &$pdo): void
{
    $jobId=(int)($GLOBALS['ems_ai_queue_current_job_id']??0);
    if($jobId<=0) return;
    if(function_exists('ems_reconnect_database_if_needed')) ems_reconnect_database_if_needed($pdo);
    $settings=ems_ai_queue_settings($pdo); $lease=max(90,(int)$settings['timeout_seconds']+30);
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') {
        $stmt=$pdo->prepare("UPDATE system_ai_request_queue SET lease_expires_at=datetime('now', '+' || ? || ' seconds') WHERE id=? AND status='running'");
    } else {
        $stmt=$pdo->prepare('UPDATE system_ai_request_queue SET lease_expires_at=DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ? SECOND) WHERE id=? AND status=\'running\'');
    }
    $stmt->execute([$lease,$jobId]);
}

function ems_ai_queue_finish(PDO $pdo, int $jobId, array $result): void
{
    $stmt=$pdo->prepare("UPDATE system_ai_request_queue SET status='completed',result_json=?,active_dedupe_key=NULL,completed_at=CURRENT_TIMESTAMP,lease_expires_at=NULL WHERE id=? AND status='running'");
    $stmt->execute([json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$jobId]);
}

function ems_ai_queue_fail(PDO $pdo, int $jobId, Throwable $error): void
{
    error_log('AI queue job '.$jobId.' failed: '.$error->getMessage());
    $stmt=$pdo->prepare("UPDATE system_ai_request_queue SET status='failed',error_message=?,active_dedupe_key=NULL,completed_at=CURRENT_TIMESTAMP,lease_expires_at=NULL WHERE id=? AND status='running'");
    $stmt->execute(['Terjadi kendala saat memproses permintaan AI. Silakan coba kembali.',$jobId]);
}

function ems_ai_queue_browser_request_allowed(string $method, int $requesterId, bool $csrfValid): bool
{
    return strtoupper($method) === 'POST' && $requesterId > 0 && $csrfValid;
}

function ems_ai_queue_browser_response(array $summary): array
{
    return ['ok' => true, 'status' => (int)($summary['processed'] ?? 0) > 0 ? 'processed' : 'idle'];
}

function ems_ai_queue_is_worker_context(): bool
{
    return (PHP_SAPI === 'cli' && defined('EMS_AI_QUEUE_WORKER'))
        || (PHP_SAPI !== 'cli' && defined('EMS_AI_QUEUE_BROWSER_WORKER'));
}

function ems_ai_queue_actor_id(): int
{
    $workerActorId=(int)($GLOBALS['ems_ai_queue_actor_id']??0);
    if ($workerActorId>0 && ems_ai_queue_is_worker_context()) return $workerActorId;
    return (int)($_SESSION['user_rh']['id']??0);
}

function ems_ai_queue_can_skip_request_auth(): bool
{
    return ems_ai_queue_is_worker_context() && ems_ai_queue_actor_id() > 0;
}

function ems_ai_queue_process_one(PDO $pdo): bool
{
    require_once __DIR__.'/../actions/ai_queue_handlers.php';
    $job=ems_ai_queue_claim($pdo);
    if (!$job) return false;
    $GLOBALS['ems_ai_queue_current_job_id']=(int)$job['id'];
    $previousActorId=$GLOBALS['ems_ai_queue_actor_id']??null;
    $GLOBALS['ems_ai_queue_actor_id']=(int)$job['requester_id'];
    try {
        $refs=json_decode((string)$job['references_json'],true,32,JSON_THROW_ON_ERROR);
        $result=ems_ai_queue_handle($pdo,$job,$refs);
        ems_ai_queue_finish($pdo,(int)$job['id'],$result);
    } catch(Throwable $error) {
        ems_ai_queue_fail($pdo,(int)$job['id'],$error);
    } finally {
        unset($GLOBALS['ems_ai_queue_current_job_id']);
        if ($previousActorId===null) unset($GLOBALS['ems_ai_queue_actor_id']);
        else $GLOBALS['ems_ai_queue_actor_id']=$previousActorId;
    }
    return true;
}

function ems_ai_queue_cleanup_retention(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec("DELETE FROM system_ai_request_queue WHERE status IN ('completed','failed') AND completed_at < datetime('now','-7 days')");
    } else {
        $pdo->exec("DELETE FROM system_ai_request_queue WHERE status IN ('completed','failed') AND completed_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY)");
    }
}

function ems_ai_queue_run_one(PDO $pdo): array
{
    if (!ems_ai_queue_is_worker_context()) throw new RuntimeException('Worker antrean hanya dapat dijalankan dari endpoint worker yang tervalidasi atau PHP CLI.');
    ems_ai_queue_recover_expired($pdo);
    $processed=ems_ai_queue_process_one($pdo) ? 1 : 0;
    ems_ai_queue_cleanup_retention($pdo);
    return ['processed'=>$processed];
}

function ems_ai_queue_run(PDO $pdo, int $maxRunSeconds = 50): array
{
    if (PHP_SAPI !== 'cli' || !defined('EMS_AI_QUEUE_WORKER')) throw new RuntimeException('Worker antrean hanya dapat dijalankan melalui PHP CLI.');
    $started=microtime(true); $done=0; ems_ai_queue_recover_expired($pdo);
    do {
        if (!ems_ai_queue_process_one($pdo)) break;
        $done++;
    } while (microtime(true)-$started < max(1,$maxRunSeconds));
    ems_ai_queue_cleanup_retention($pdo);
    return ['processed'=>$done];
}
