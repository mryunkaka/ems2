<?php
session_start();
require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/request_guard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_settings.php';
require_once __DIR__ . '/../config/ai_queue.php';
require_once __DIR__ . '/ai_gemini_client.php';
header('Content-Type: application/json; charset=UTF-8');

$viewerId = (int)($_SESSION['user_rh']['id'] ?? 0);
if ($viewerId <= 0) { http_response_code(401); echo json_encode(['success'=>false]); exit; }
emsRequireRateLimit('work_anniversary_notices', emsCurrentRequestIdentifier($viewerId), 10, 60, 'Permintaan apresiasi terlalu sering.');
$mode = (string)($_GET['mode'] ?? 'list');
$requestedId = max(0, (int)($_GET['user_id'] ?? 0));
if ($mode === 'generate') {
    emsRequireRateLimit('work_anniversary_generation', emsCurrentRequestIdentifier($viewerId), 50, 60, 'Pembuatan apresiasi terlalu sering.');
    if (!defined('EMS_AI_QUEUE_WORKER')) {
        if($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Metode tidak diizinkan.']); exit; }
        emsRequireJsonCsrf('Sesi kedaluwarsa. Muat ulang halaman lalu coba lagi.');
        $stmt=$pdo->prepare('SELECT id,tanggal_masuk,is_active FROM user_rh WHERE id=? LIMIT 1'); $stmt->execute([$requestedId]); $person=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$person || (int)($person['is_active']??1)!==1 || empty($person['tanggal_masuk'])) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Data masa kerja tidak tersedia.']); exit; }
        $todayDate=new DateTimeImmutable('today',new DateTimeZone('Asia/Jakarta')); $join=new DateTimeImmutable((string)$person['tanggal_masuk']);
        $month=(int)$join->format('m'); $day=(int)$join->format('d'); $year=(int)$todayDate->format('Y'); if($month===2&&$day===29&&!checkdate(2,29,$year))$day=28;
        if($join->setDate($year,$month,$day)>$todayDate) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Tanggal apresiasi belum tiba.']); exit; }
        if(!ems_ai_global_provider_ready($pdo)) { http_response_code(503); echo json_encode(['success'=>false,'message'=>'Provider AI global belum siap.']); exit; }
        try {
            $job=ems_ai_queue_enqueue($pdo,'work_anniversary_generate',$viewerId,'Apresiasi masa kerja',['user_id'=>$requestedId,'anniversary_year'=>$year,'requester_id'=>$viewerId],'work-anniversary:'.$requestedId.':'.$year);
            echo json_encode(['success'=>true,'queued'=>true,'job_id'=>$job['job_id'],'status'=>$job['status']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
        } catch(OverflowException $e) { http_response_code(429); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit; }
    }
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Jakarta'));
$year = (int)$today->format('Y');
$todayKey = $today->format('m-d');
$hasUserUnit = ems_column_exists($pdo, 'user_rh', 'unit_code');
$hasFarmasiWebLogin = ems_column_exists($pdo, 'user_rh', 'last_farmasi_web_login_at');
$hasRecentFarmasiActivity = ems_table_exists($pdo, 'user_farmasi_sessions') && ems_column_exists($pdo, 'user_farmasi_sessions', 'session_start');
$recentFarmasiActivityWhere = $hasFarmasiWebLogin
    ? 'AND last_farmasi_web_login_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)'
    : ($hasRecentFarmasiActivity
        ? 'AND EXISTS (SELECT 1 FROM user_farmasi_sessions fs WHERE fs.user_id = user_rh.id AND fs.session_start >= DATE_SUB(NOW(), INTERVAL 1 MONTH))'
        : 'AND 1 = 0');
$recentFarmasiLoginSelect = $hasFarmasiWebLogin
    ? ', last_farmasi_web_login_at AS last_farmasi_login'
    : ($hasRecentFarmasiActivity
        ? ', (SELECT MAX(fs.session_start) FROM user_farmasi_sessions fs WHERE fs.user_id = user_rh.id) AS last_farmasi_login'
        : ', NULL AS last_farmasi_login');
$stmt = $pdo->prepare("SELECT id, full_name, position, division, tanggal_masuk, tanggal_lahir_ic" . ($hasUserUnit ? ', unit_code' : ", 'roxwood' AS unit_code") . " {$recentFarmasiLoginSelect} FROM user_rh WHERE tanggal_masuk IS NOT NULL AND DATE_FORMAT(tanggal_masuk, '%m-%d') = ? AND YEAR(tanggal_masuk) < ? AND COALESCE(is_active, 1) = 1 {$recentFarmasiActivityWhere} ORDER BY full_name");
$stmt->execute([$todayKey, $year]);
$people = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$items = [];
$pending = [];
$settings = ems_ai_get_settings($pdo);
$canGenerateImages = strtolower((string)($settings['provider'] ?? 'gemini')) === 'gemini' && ems_ai_global_provider_ready($pdo);
$storageDir = dirname(__DIR__) . '/storage/work_anniversary';
if (!is_dir($storageDir)) @mkdir($storageDir, 0750, true);

if (!function_exists('anniversaryDateLabel')) { function anniversaryDateLabel(?string $value): string {
    if (!$value || $value === '0000-00-00') return '-';
    try {
        $date = new DateTimeImmutable($value);
        $months = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
        return $date->format('d') . ' ' . $months[(int)$date->format('n')] . ' ' . $date->format('Y');
    } catch (Throwable $e) { return '-'; }
} }

foreach ($people as $person) {
    $uid = (int)$person['id'];
    $birthDateForSound = trim((string)($person['tanggal_lahir_ic'] ?? ''));
    $joinDateForSound = trim((string)($person['tanggal_masuk'] ?? ''));
    $musicSeed = sprintf('%u', crc32($birthDateForSound . '|' . $joinDateForSound));
    if ($mode === 'generate' && $requestedId !== $uid) continue;
    $joinDate = new DateTimeImmutable((string)$person['tanggal_masuk']);
    $anniversaryMonth = (int)$joinDate->format('m');
    $anniversaryDay = (int)$joinDate->format('d');
    if ($anniversaryMonth === 2 && $anniversaryDay === 29 && !checkdate(2, 29, $year)) $anniversaryDay = 28;
    $anniversaryDate = $joinDate->setDate($year, $anniversaryMonth, $anniversaryDay);
    if ($anniversaryDate > $today) continue;
    $years = $year - (int)$joinDate->format('Y');
    $existing = $pdo->prepare('SELECT greeting FROM work_anniversary_greetings WHERE user_id = ? AND anniversary_year = ? LIMIT 1');
    $existing->execute([$uid, $year]);
    $greeting = trim((string)($existing->fetchColumn() ?: ''));
    $existingGreetingLength = function_exists('mb_strlen') ? mb_strlen($greeting, 'UTF-8') : strlen($greeting);
    $needsGeneration = $greeting === '' || $existingGreetingLength < 2400;
    $matchingImages = glob($storageDir . '/' . $uid . '_' . $year . '.*') ?: [];
    $imageRelativePath = $matchingImages ? 'storage/work_anniversary/' . basename($matchingImages[0]) : 'storage/work_anniversary/' . $uid . '_' . $year . '.png';
    $imageAbsolutePath = dirname(__DIR__) . '/' . $imageRelativePath;

    if ($mode !== 'generate') {
        if ($needsGeneration) {
            $pending[] = ['id'=>$uid];
            continue;
        }
        $items[] = ['id'=>$uid,'name'=>(string)$person['full_name'],'position'=>ems_position_label((string)$person['position']),'division'=>ems_normalize_division((string)$person['division']) ?: '-','years'=>$years,'greeting'=>$greeting,'image_url'=>is_file($imageAbsolutePath) ? ems_secure_file_url($imageRelativePath) : '', 'music_seed'=>$musicSeed];
        continue;
    }

    if ($needsGeneration) {
        $periodStart = $anniversaryDate->modify('-1 year')->modify('+1 day');
        $periodEnd = $anniversaryDate;
        $start = $periodStart->format('Y-m-d');
        $end = $periodEnd->format('Y-m-d');

        $salesDateColumn = ems_column_exists($pdo, 'sales', 'created_at') ? 'created_at' : 'sale_date';
        $salesSql = 'SELECT COUNT(*) AS tx, COALESCE(SUM(price),0) AS revenue FROM sales WHERE medic_user_id = ? AND DATE(`' . $salesDateColumn . '`) BETWEEN ? AND ?';
        if (ems_column_exists($pdo, 'sales', 'unit_code')) $salesSql .= ' AND unit_code = ?';
        $sales = $pdo->prepare($salesSql);
        $salesParams = [$uid, $start, $end];
        if (ems_column_exists($pdo, 'sales', 'unit_code')) $salesParams[] = (string)($person['unit_code'] ?? 'roxwood');
        $sales->execute($salesParams);
        $salesStats = $sales->fetch(PDO::FETCH_ASSOC) ?: ['tx'=>0,'revenue'=>0];

        $sessionSql = 'SELECT COUNT(*) AS sessions, COALESCE(SUM(duration_seconds),0) AS seconds FROM user_farmasi_sessions WHERE user_id = ?';
        $sessionDateColumn = ems_column_exists($pdo, 'user_farmasi_sessions', 'session_start') ? 'session_start' : '';
        if ($sessionDateColumn !== '') $sessionSql .= ' AND DATE(session_start) BETWEEN ? AND ?';
        $sessions = $pdo->prepare($sessionSql);
        $sessions->execute($sessionDateColumn !== '' ? [$uid, $start, $end] : [$uid]);
        $sessionStats = $sessions->fetch(PDO::FETCH_ASSOC) ?: ['sessions'=>0,'seconds'=>0];

        $cutiHistory = [];
        if (ems_table_exists($pdo, 'cuti_requests')) {
            $hasManagerReply = ems_column_exists($pdo, 'cuti_requests', 'manager_approval_reply');
            $replySelect = $hasManagerReply ? ', manager_approval_reply' : ', NULL AS manager_approval_reply';
            $cuti = $pdo->prepare("SELECT start_date, end_date, days_total, actual_end_date, actual_days_used, status, reason_ic, reason_ooc {$replySelect} FROM cuti_requests WHERE user_id = ? AND status = 'approved' AND start_date <= ? AND COALESCE(actual_end_date, end_date) >= ? ORDER BY start_date ASC");
            $cuti->execute([$uid, $end, $start]);
            foreach (($cuti->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
                $days = (int)($row['actual_days_used'] ?? 0);
                if ($days <= 0) $days = (int)($row['days_total'] ?? 0);
                $cutiHistory[] = anniversaryDateLabel((string)$row['start_date']) . '–' . anniversaryDateLabel((string)($row['actual_end_date'] ?: $row['end_date'])) . " ({$days} hari)";
            }
        }

        if (ems_table_exists($pdo, 'cuti_requests')) {
            $hasManagerReply = ems_column_exists($pdo, 'cuti_requests', 'manager_approval_reply');
            $replySelect = $hasManagerReply ? ', manager_approval_reply' : ', NULL AS manager_approval_reply';
            $cutiDetails = $pdo->prepare("SELECT start_date, COALESCE(actual_end_date, end_date) AS leave_end, COALESCE(NULLIF(actual_days_used, 0), days_total) AS leave_days, reason_ic, reason_ooc {$replySelect} FROM cuti_requests WHERE user_id = ? AND status = 'approved' AND start_date <= ? AND COALESCE(actual_end_date, end_date) >= ? ORDER BY start_date ASC");
            $cutiDetails->execute([$uid, $end, $start]);
            $cutiHistory = [];
            foreach (($cutiDetails->fetchAll(PDO::FETCH_ASSOC) ?: []) as $leave) {
                $entry = anniversaryDateLabel((string)$leave['start_date']) . ' - ' . anniversaryDateLabel((string)$leave['leave_end']) . ' (' . (int)$leave['leave_days'] . ' hari)';
                foreach (['reason_ic'=>'alasan IC', 'reason_ooc'=>'alasan OOC', 'manager_approval_reply'=>'balasan manajemen'] as $field=>$label) {
                    $value = trim((string)($leave[$field] ?? ''));
                    if ($value !== '') $entry .= '; ' . $label . ': ' . $value;
                }
                $cutiHistory[] = $entry;
            }
        }

        $careerFields = ['tanggal_masuk'];
        foreach (['tanggal_naik_paramedic','tanggal_naik_co_asst','tanggal_naik_dokter','tanggal_naik_dokter_spesialis','tanggal_join_manager'] as $field) {
            if (ems_column_exists($pdo, 'user_rh', $field)) $careerFields[] = $field;
        }
        $careerFields[] = 'position';
        if (ems_column_exists($pdo, 'user_rh', 'specialist_degrees')) $careerFields[] = 'specialist_degrees';
        $careerSql = 'SELECT ' . implode(', ', array_map(static fn($f) => '`'.$f.'`', $careerFields)) . ' FROM user_rh WHERE id = ?';
        $careerStmt = $pdo->prepare($careerSql); $careerStmt->execute([$uid]);
        $career = $careerStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $careerLabels = ['tanggal_masuk'=>'Bergabung','tanggal_naik_paramedic'=>'Menjadi paramedis','tanggal_naik_co_asst'=>'Menjadi co-assistant','tanggal_naik_dokter'=>'Menjadi dokter','tanggal_naik_dokter_spesialis'=>'Menjadi dokter spesialis','tanggal_join_manager'=>'Bergabung ke manajemen'];
        $careerHistory = [];
        foreach ($careerLabels as $field => $label) if (!empty($career[$field])) $careerHistory[] = $label . ': ' . anniversaryDateLabel((string)$career[$field]);
        if (!empty($career['specialist_degrees'])) $careerHistory[] = 'Gelar spesialis: ' . (string)$career['specialist_degrees'];

        $documentHistory = [];
        if (ems_table_exists($pdo, 'document_files') && ems_column_exists($pdo, 'document_files', 'uploaded_by')) {
            $docSql = 'SELECT title, division, created_at FROM document_files WHERE uploaded_by = ? AND DATE(created_at) BETWEEN ? AND ?';
            $docParams = [$uid, $start, $end];
            if (ems_column_exists($pdo, 'document_files', 'unit_code')) { $docSql .= ' AND unit_code = ?'; $docParams[] = (string)($person['unit_code'] ?? 'roxwood'); }
            $docSql .= ' ORDER BY created_at ASC LIMIT 30';
            $docStmt = $pdo->prepare($docSql); $docStmt->execute($docParams);
            foreach (($docStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $doc) $documentHistory[] = anniversaryDateLabel((string)$doc['created_at']) . ': ' . trim((string)$doc['title']) . ' (' . trim((string)$doc['division']) . ')';
        }

        $birthDate = trim((string)($person['tanggal_lahir_ic'] ?? ''));
        $age = null;
        if ($birthDate !== '' && $birthDate !== '0000-00-00') {
            try { $age = (new DateTimeImmutable($birthDate))->diff($today)->y; } catch (Throwable $e) { $birthDate = ''; }
        }
        $facts = [
            'nama'=>$person['full_name'], 'jabatan'=>$person['position'], 'divisi'=>$person['division'],
            'tanggal bergabung'=>anniversaryDateLabel($joinDate->format('Y-m-d')), 'tahun masa kerja'=>$years,
            'periode rekap'=>$start . ' s.d. ' . $end,
            'kunjungan web Farmasi terakhir'=>anniversaryDateLabel(isset($person['last_farmasi_login']) ? substr((string)$person['last_farmasi_login'], 0, 10) : null),
            'tanggal lahir IC'=>$birthDate ? anniversaryDateLabel($birthDate) : 'tidak tercatat',
            'usia saat ini'=>$age === null ? 'tidak tercatat' : $age,
            'jumlah transaksi farmasi'=>$salesStats['tx'], 'total nilai transaksi farmasi'=>number_format((float)$salesStats['revenue'], 0, ',', '.'),
            'estimasi bagian staf 40 persen'=>number_format(floor((float)$salesStats['revenue'] * 0.4), 0, ',', '.'),
            'sesi farmasi'=>$sessionStats['sessions'], 'jam online farmasi'=>round((int)$sessionStats['seconds'] / 3600, 1),
            'riwayat cuti disetujui'=>$cutiHistory,
            'riwayat jabatan'=>$careerHistory,
            'unggahan dokumen pada menu Dokumen'=>$documentHistory,
        ];
        $factsJson = json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $prompt = "Tulis cerita apresiasi masa kerja berbahasa Indonesia dengan bahasa sehari-hari yang hangat, santai, enak dibaca, terasa personal, dan membuat pembaca ingin mengikuti kisah sampai akhir. Variasikan ritme kalimat; hindari ungkapan klise, pembukaan generik, bahasa berbunga-bunga berlebihan, serta pengulangan. Ceritakan perjalanan untuk {$person['full_name']}, staf roleplay Roxwood Hospital yang telah {$years} tahun bergabung. Buat sekitar 900–1200 kata dalam 10–14 paragraf. Awali dengan pembuka naratif yang memancing rasa ingin tahu tanpa clickbait; bangun perjalanan setahun terakhir dari data yang diberikan; selipkan rekap transaksi farmasi dan estimasi bagian staf sebagai konteks kontribusi di web farmasi, riwayat cuti yang disetujui beserta tanggal, durasi, alasan IC, alasan OOC, dan balasan manajemen bila tersedia, dengan hormat, ulang tahun/tanggal lahir IC dan usia bila datanya tercatat, perjalanan kenaikan jabatan yang bertanggal, serta unggahan nyata di menu Dokumen bila tercatat; sebut judul dokumen saja tanpa mengarang isi atau dampaknya. Jangan pernah menciptakan kegiatan, pencapaian, alasan cuti, pendapatan, usia, sifat, atau kejadian yang tidak ada di data. Jika data tidak tercatat, jangan menyebutnya. Jangan menyebut nilai finansial sebagai gaji yang pasti; namai estimasi bagian staf. Akhiri dengan apresiasi karena tetap bertahan di Roxwood Hospital, harapan untuk masa depan, dorongan yang ramah untuk naik ke jenjang profesi berikutnya sesuai jalur (misalnya co-assistant ke dokter), dan kesimpulan yang terasa kuat dan mengharukan. Buat ucapan pantas dibaca seluruh staf. Data faktual: {$factsJson}. Kembalikan JSON berisi greeting (cerpen) dan image_prompt (deskripsi visual simbolik yang mencerminkan perjalanan dari data, bukan tebakan sifat; pemandangan inspiratif, tanpa teks, tanpa logo, tanpa identitas medis).";
        if (ems_ai_global_provider_ready($pdo)) {
            try {
                $schema = ['type'=>'OBJECT','properties'=>['greeting'=>['type'=>'STRING'],'image_prompt'=>['type'=>'STRING']],'required'=>['greeting','image_prompt']];
                $result = ems_gemini_generate_content($pdo, $settings, [['role'=>'user','parts'=>[['text'=>$prompt]]]], (string)$settings['summary_model'], 'work_anniversary_greeting', $viewerId, $schema);
                $responseData = json_decode((string)($result['text'] ?? ''), true);
                if (is_array($responseData)) {
                    $greeting = trim((string)($responseData['greeting'] ?? ''));
                    $imagePrompt = trim((string)($responseData['image_prompt'] ?? ''));
                }
            } catch (Throwable $e) { $greeting = ''; }
        }
        $greetingLength = function_exists('mb_strlen') ? mb_strlen($greeting, 'UTF-8') : strlen($greeting);
        if ($greeting === '' || $greetingLength < 2400 || $imagePrompt === '') continue;

        if ($canGenerateImages && !is_file($imageAbsolutePath) && !empty($imagePrompt)) {
            try {
                $imageResult = ems_gemini_generate_image($pdo, $settings, [['role'=>'user','parts'=>[['text'=>$imagePrompt]]]], 'gemini-3.1-flash-image', 'work_anniversary_image', $viewerId);
                $image = $imageResult['image'] ?? null;
                if (is_array($image) && !empty($image['data'])) {
                    $binary = base64_decode((string)$image['data'], true);
                    if ($binary !== false && strlen($binary) <= 8 * 1024 * 1024) {
                        $extension = strtolower((string)($image['mime_type'] ?? 'image/png')) === 'image/jpeg' ? 'jpg' : 'png';
                        $imageRelativePath = 'storage/work_anniversary/' . $uid . '_' . $year . '.' . $extension;
                        $imageAbsolutePath = dirname(__DIR__) . '/' . $imageRelativePath;
                        file_put_contents($imageAbsolutePath, $binary, LOCK_EX);
                    }
                }
            } catch (Throwable $e) { /* Ucapan tetap tersedia jika model gambar tidak aktif atau gagal. */ }
        }
        $save = $pdo->prepare('INSERT INTO work_anniversary_greetings (user_id, anniversary_year, greeting) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE greeting = IF(CHAR_LENGTH(greeting) < 2400, VALUES(greeting), greeting)');
        $save->execute([$uid, $year, $greeting]);
        if ($save->rowCount() === 0) { $existing->execute([$uid, $year]); $greeting = (string)$existing->fetchColumn(); }
    }
    if (!is_file($imageAbsolutePath)) $imageRelativePath = '';
    $items[] = ['id'=>$uid,'name'=>(string)$person['full_name'],'position'=>ems_position_label((string)$person['position']),'division'=>ems_normalize_division((string)$person['division']) ?: '-','years'=>$years,'greeting'=>$greeting,'image_url'=>$imageRelativePath !== '' ? ems_secure_file_url($imageRelativePath) : '', 'needs_generation'=>false, 'music_seed'=>$musicSeed];
}
echo json_encode(['success'=>true,'year'=>$year,'date_key'=>$today->format('Y-m-d'),'items'=>$items,'pending'=>$mode === 'generate' ? [] : $pending], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
