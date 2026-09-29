<?php
// Generate in persisted, resumable sections so no single request carries the whole plan.
@set_time_limit(55);
date_default_timezone_set('Asia/Jakarta');
session_start();
header('Content-Type: application/json; charset=UTF-8');

register_shutdown_function(static function (): void {
    $lastError = error_get_last();
    if (!is_array($lastError) || !in_array((int) ($lastError['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    $requestTag = substr(hash('sha256', microtime(true) . ':' . mt_rand()), 0, 10);
    error_log('[AI Surgery Planner][' . $requestTag . '] PHP fatal: ' . (string) ($lastError['message'] ?? 'unknown') . ' at ' . (string) ($lastError['file'] ?? 'unknown') . ':' . (int) ($lastError['line'] ?? 0));
    if (!headers_sent()) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => 'PHP menghentikan proses pembuatan rencana. Kode diagnostik: ' . $requestTag . '. Periksa error log hosting dengan kode tersebut.',
        ], JSON_UNESCAPED_UNICODE);
    }
});

function ems_ai_ds_surgery_job_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ems_ai_ds_surgery_job_record_stage(PDO $pdo, int $jobId, int $stageNo, string $stageType, bool $ok, ?array $result, array $errors): void
{
    $attemptStmt = $pdo->prepare('SELECT COALESCE(MAX(attempt_no), 0) + 1 FROM ai_surgery_generation_stages WHERE job_id = ? AND stage_no = ?');
    $attemptStmt->execute([$jobId, $stageNo]);
    $attemptNo = (int) $attemptStmt->fetchColumn();
    $insert = $pdo->prepare('INSERT INTO ai_surgery_generation_stages (job_id, stage_no, attempt_no, stage_type, status, result_json, validation_errors_json) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insert->execute([
        $jobId, $stageNo, $attemptNo, $stageType, $ok ? 'done' : 'error',
        $result === null ? null : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $errors === [] ? null : json_encode(array_values($errors), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function ems_ai_ds_surgery_job_validate_header(array $header): array
{
    $errors = [];
    $serialized = json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    if (preg_match('/\b(?:data\s+belum\s+tersedia|petugas\s+belum\s+tersedia|wajib\s+diverifikasi|belum\s+tersedia|belum\s+diketahui|belum\s+dilakukan|belum\s+tercatat|sedang\s+diproses|menunggu\s+hasil)\b/iu', $serialized)) $errors[] = 'ringkasan masih mengandung placeholder atau status menunggu';
    if (trim((string) ($header['durasi'] ?? '')) === '') $errors[] = 'durasi belum diisi model';
    if (trim((string) ($header['laporan_pasca_operasi'] ?? '')) === '') $errors[] = 'laporan pasca-operasi belum diisi model';
    if (!is_array($header['sop_references'] ?? null) || $header['sop_references'] === []) $errors[] = 'rujukan SOP belum diisi model';
    if (!is_array($header['risiko_komplikasi'] ?? null)) $errors[] = 'daftar risiko tidak berbentuk array';
    $drugSections = ['pra_operatif', 'intra_operatif', 'post_operatif', 'pemulangan'];
    if (!is_array($header['farmakologi'] ?? null)) {
        $errors[] = 'farmakologi belum diisi model';
    } else {
        foreach ($drugSections as $section) {
            if (!is_array($header['farmakologi'][$section] ?? null)) $errors[] = 'bagian farmakologi ' . $section . ' belum berbentuk array';
        }
    }
    $outline = $header['outline_tahapan'] ?? null;
    if (!is_array($outline) || count($outline) < 2 || count($outline) > 24) $errors[] = 'model harus menentukan 2 sampai 24 tahap inti yang diperlukan';
    else foreach ($outline as $index => $item) {
        if (!is_array($item) || trim((string) ($item['judul'] ?? '')) === '' || trim((string) ($item['tujuan'] ?? '')) === '') {
            $errors[] = 'judul/tujuan tahap ' . ($index + 1) . ' pada kerangka belum lengkap';
        }
    }
    return $errors;
}

function ems_ai_ds_surgery_job_validate_step(array $step, string $caseText): array
{
    $errors = [];
    $role = trim((string) ($step['pelaku'] ?? ''));
    $roles = array_map('trim', explode('+', $role));
    if ($role === '' || count(array_filter($roles, static fn ($r) => in_array($r, ['DPJP', 'Asisten 1', 'Asisten 2'], true))) !== count($roles)) $errors[] = 'pelaku tahap tidak dikenali';
    foreach (['aksi', 'hasil'] as $field) if (trim((string) ($step[$field] ?? '')) === '') $errors[] = $field . ' tahap kosong';
    $serialized = json_encode($step, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    if (preg_match('/\b(?:data\s+belum\s+tersedia|petugas\s+belum\s+tersedia|wajib\s+diverifikasi|belum\s+tersedia|belum\s+diketahui|belum\s+dilakukan|belum\s+tercatat|sedang\s+diproses|menunggu\s+hasil)\b/iu', $serialized)) $errors[] = 'tahap masih berisi placeholder';
    if (preg_match('/\b(?:usia|berusia|tahun)\b[^\n]{0,50}\b\d{1,3}\s*[-–]\s*\d{1,3}\s*tahun\b|\b\d{1,3}\s*[-–]\s*\d{1,3}\s*tahun\b/iu', $serialized)) $errors[] = 'usia ditulis sebagai rentang ambigu';
    $branchText = (string) ($step['aksi'] ?? '');
    $branchText = (string) preg_replace('/\b(?:tanpa|tidak|bukan|hindari|mencegah)\b[^,;.!?]{0,120}\b(?:atau|or)\b/iu', '', $branchText);
    if (preg_match('/\b(?:atau|or)\b/iu', $branchText)) $errors[] = 'aksi masih memberi pilihan bercabang';
    $errors = array_merge($errors, ems_ai_ds_instrument_action_issues([$step], 'Tahapan operasi', $caseText));
    return array_values(array_unique($errors));
}

function ems_ai_ds_surgery_job_process(PDO $pdo, string $token, int $userId, string $unitCode): array
{
    $claim = $pdo->prepare("UPDATE ai_surgery_generation_jobs SET lock_expires_at = DATE_ADD(NOW(), INTERVAL 60 SECOND) WHERE job_token = ? AND user_id = ? AND unit_code = ? AND status IN ('running','repairing') AND (lock_expires_at IS NULL OR lock_expires_at < NOW())");
    $claim->execute([$token, $userId, $unitCode]);
    if ($claim->rowCount() !== 1) {
        $lookup = $pdo->prepare('SELECT status, final_plan_id, last_error FROM ai_surgery_generation_jobs WHERE job_token = ? AND user_id = ? AND unit_code = ? LIMIT 1');
        $lookup->execute([$token, $userId, $unitCode]);
        $existing = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$existing) return ['ok' => false, 'message' => 'Sesi proses tidak ditemukan atau bukan milik akun Anda.'];
        if ($existing['status'] === 'done' && (int) ($existing['final_plan_id'] ?? 0) > 0) return ['ok' => true, 'done' => true, 'plan_id' => (int) $existing['final_plan_id']];
        if ($existing['status'] === 'error') return ['ok' => false, 'message' => (string) ($existing['last_error'] ?: 'Proses ini telah berakhir dengan kegagalan.'), 'retryable' => false];
        return ['ok' => false, 'message' => 'Tahap sebelumnya masih diproses. Tunggu sebentar lalu lanjutkan kembali.', 'retryable' => true, 'busy' => true];
    }

    try {
        $find = $pdo->prepare('SELECT * FROM ai_surgery_generation_jobs WHERE job_token = ? AND user_id = ? AND unit_code = ? LIMIT 1');
        $find->execute([$token, $userId, $unitCode]);
        $job = $find->fetch(PDO::FETCH_ASSOC);
        if (!$job) throw new RuntimeException('Data proses tidak ditemukan.');
        $stageNo = (int) $job['next_stage_no'];
        $chunkSize = 3;
        $plannedCount = (int) ($job['planned_step_count'] ?? 0);
        $chunkCount = max(1, (int) ceil($plannedCount / $chunkSize));
        $header = null;
        $steps = [];
        $repairStart = $chunkCount + 2;
        $repairIndexes = json_decode((string) ($job['repair_indexes_json'] ?? '[]'), true);
        $repairIndexes = is_array($repairIndexes) ? array_values(array_unique(array_map('intval', $repairIndexes))) : [];
        $latestAuditStmt = $pdo->prepare("SELECT id, result_json FROM ai_surgery_generation_stages WHERE job_id = ? AND stage_type = 'audit' ORDER BY id DESC LIMIT 1");
        $latestAuditStmt->execute([(int) $job['id']]);
        $latestAudit = $latestAuditStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $stageStmt = $pdo->prepare("SELECT s.* FROM ai_surgery_generation_stages s JOIN (SELECT stage_no, MAX(attempt_no) attempt_no FROM ai_surgery_generation_stages WHERE job_id = ? AND status = 'done' GROUP BY stage_no) latest ON latest.stage_no=s.stage_no AND latest.attempt_no=s.attempt_no WHERE s.job_id = ? ORDER BY s.stage_no");
        $stageStmt->execute([(int) $job['id'], (int) $job['id']]);
        $completedStages = $stageStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($completedStages as $completed) {
            $piece = json_decode((string) ($completed['result_json'] ?? ''), true);
            if (!is_array($piece)) continue;
            if ((int) $completed['stage_no'] === 1) $header = $piece;
            elseif ((int) $completed['stage_no'] >= 2 && (int) $completed['stage_no'] <= $chunkCount + 1) {
                $one = ems_ai_ds_extract_surgery_steps($piece);
                $chunkStart = ((int) $completed['stage_no'] - 2) * $chunkSize;
                foreach ($one as $offset => $item) $steps[$chunkStart + $offset] = $item;
            }
        }
        // A final-audit snapshot is the canonical baseline for repeated repair
        // rounds; it preserves already-correct steps even if the failing step
        // list changes between rounds.
        if ($latestAudit) {
            $auditData = json_decode((string) ($latestAudit['result_json'] ?? ''), true);
            if (is_array($auditData) && is_array($auditData['tahapan_prosedur'] ?? null)) {
                $steps = array_values($auditData['tahapan_prosedur']);
            }
        }
        if ($job['status'] === 'repairing' && $repairIndexes !== []) {
            foreach ($completedStages as $completed) {
                $stageNoDone = (int) $completed['stage_no'];
                if ($stageNoDone < $repairStart || $stageNoDone >= $repairStart + count($repairIndexes)) continue;
                if ($latestAudit && (int) $completed['id'] <= (int) $latestAudit['id']) continue;
                $repairPosition = $stageNoDone - $repairStart;
                $one = ems_ai_ds_extract_surgery_steps(json_decode((string) ($completed['result_json'] ?? ''), true) ?: []);
                if (isset($repairIndexes[$repairPosition], $one[0])) $steps[$repairIndexes[$repairPosition]] = $one[0];
            }
        }
        if ($header === null && $stageNo !== 1) throw new RuntimeException('Kerangka hasil model tidak ditemukan; proses tidak dapat dilanjutkan.');
        $aiUserId = (int) $job['user_id'];
        $systemPrompt = (string) $job['system_prompt'];
        $basePrompt = (string) $job['user_prompt'];
        $caseText = trim((string) $job['kasus_tindakan'] . "\n" . (string) $job['diagnosis_context']);
        $stageType = 'step';
        $schema = ems_ai_ds_surgery_response_schema(true);
        $prompt = '';
        $targetIndex = null;

        if ($stageNo === 1) {
            $stageType = 'outline';
            $schema = ems_ai_ds_surgery_response_schema(false, true, true);
            $prompt = $basePrompt . "\n\nTAHAP 1 DARI GENERASI BERTAHAP — KERANGKA DAN RINGKASAN. Keluarkan semua field ringkasan selain tahapan_prosedur, lalu tentukan sendiri outline_tahapan berupa 2 sampai 24 item berurutan. Setiap item hanya berisi judul dan tujuan yang spesifik pada kasus. Jangan menulis detail aksi tahap dahulu. Semua nilai harus final sebagai skenario roleplay dan sesuai schema."
                . ((string) ($job['last_error'] ?? '') !== '' ? "\nPerbaiki kekurangan validasi sebelumnya: " . implode('; ', json_decode((string) $job['last_error'], true) ?: []) : '');
        } elseif ($job['status'] === 'repairing') {
            $repairPosition = $stageNo - $repairStart;
            if (!isset($repairIndexes[$repairPosition])) throw new RuntimeException('Daftar tahap perbaikan tidak konsisten.');
            $targetIndex = $repairIndexes[$repairPosition];
            $stageType = 'repair';
            $oldStep = $steps[$targetIndex] ?? [];
            $prompt = "KASUS KANONIK:\n" . $basePrompt . "\n\nPERBAIKI HANYA TAHAP " . ($targetIndex + 1) . " BERDASARKAN KERANGKA MODEL:\n" . json_encode($jobOutline = ($header['outline_tahapan'][$targetIndex] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . "\nTAHAP SAAT INI:\n" . json_encode($oldStep, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . "\nMASALAH VALIDASI:\n" . implode('; ', json_decode((string) ($job['last_error'] ?? '[]'), true) ?: [])
                . "\nKembalikan tepat satu item tahapan_prosedur. Untuk setiap masalah alat, tulis nama alat/instrumen/bahan yang dikenali secara eksplisit di aksi /me, lalu jelaskan penggunaannya dalam tindakan itu; jangan hanya menambahkan daftar alat atau mengandalkan instruksi asisten. Jika pelakunya asisten, instruksi DPJP juga wajib menyebut alat yang sama. Pertahankan urutan, hasil /do dan fakta kasus; jangan membuat tahap baru.";
        } else {
            $chunkIndex = $stageNo - 2;
            $chunkStart = $chunkIndex * $chunkSize;
            $outline = $header['outline_tahapan'] ?? [];
            $outlineChunk = array_slice($outline, $chunkStart, $chunkSize);
            if ($outlineChunk === []) throw new RuntimeException('Tahap yang diminta tidak ada pada kerangka model.');
            $targetIndex = $chunkStart;
            $prompt = "KASUS KANONIK DAN SOP:\n" . $basePrompt
                . "\n\nKERANGKA YANG DIBUAT MODEL:\n" . json_encode($outline, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . "\n\nBATCH TAHAP YANG HARUS DITULIS SEKARANG: " . ($chunkStart + 1) . " sampai " . ($chunkStart + count($outlineChunk)) . " dari " . count($outline) . "\n"
                . json_encode($outlineChunk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . "\nTAHAP SEBELUMNYA YANG SUDAH TERSIMPAN (untuk kesinambungan; jangan salin atau ulangi):\n"
                . json_encode(array_slice(array_values($steps), max(0, count($steps) - 3)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . "\nKembalikan tepat " . count($outlineChunk) . " item tahapan_prosedur sesuai urutan kerangka. Setiap item menjabarkan aksi /me dan hasil langsung /do. Alat dan bahan harus disebut di dalam aksi, instruksi kepada asisten menyebut alat spesifik. Jangan membuat tindakan/hasil yang bertentangan dengan kasus atau langkah sebelumnya."
                . ((string) ($job['last_error'] ?? '') !== '' ? "\nPerbaiki juga masalah validasi percobaan sebelumnya: " . implode('; ', json_decode((string) $job['last_error'], true) ?: []) : '');
        }

        // Keep shared hosting/provider capacity bounded. A short lease is
        // reclaimed automatically if PHP is terminated before finally runs.
        $slotTokenRaw = bin2hex(random_bytes(16));
        $slotToken = substr($slotTokenRaw, 0, 8) . '-' . substr($slotTokenRaw, 8, 4) . '-4' . substr($slotTokenRaw, 13, 3) . '-a' . substr($slotTokenRaw, 17, 3) . '-' . substr($slotTokenRaw, 20, 12);
        $slotClaim = $pdo->prepare('UPDATE ai_surgery_generation_provider_slots SET job_id = ?, lock_token = ?, expires_at = DATE_ADD(NOW(), INTERVAL 55 SECOND) WHERE expires_at IS NULL OR expires_at < NOW() ORDER BY slot_id LIMIT 1');
        $slotClaim->execute([(int) $job['id'], $slotToken]);
        if ($slotClaim->rowCount() !== 1) {
            $unlock = $pdo->prepare('UPDATE ai_surgery_generation_jobs SET lock_expires_at = NULL WHERE id = ? AND job_token = ?');
            $unlock->execute([(int) $job['id'], $token]);
            return ['ok' => false, 'busy' => true, 'retryable' => true, 'retry_after_seconds' => 5, 'job_token' => $token, 'message' => 'Provider AI sedang menangani permintaan lain. Proses ini mengantre dan akan dilanjutkan otomatis.'];
        }
        try {
            $result = ems_ai_ds_call_gemini($pdo, $systemPrompt, $prompt, 'ai_surgery_planner', $aiUserId, $schema);
        } finally {
            $slotRelease = $pdo->prepare('UPDATE ai_surgery_generation_provider_slots SET job_id = NULL, lock_token = NULL, expires_at = NULL WHERE lock_token = ?');
            $slotRelease->execute([$slotToken]);
        }
        if (empty($result['ok']) || !is_array($result['data'] ?? null)) {
            $error = (string) ($result['error'] ?? 'Model tidak menghasilkan objek JSON.');
            ems_ai_ds_surgery_job_record_stage($pdo, (int) $job['id'], $stageNo, $stageType, false, $result['data'] ?? null, [$error]);
            $saveError = $pdo->prepare('UPDATE ai_surgery_generation_jobs SET last_error = ?, lock_expires_at = NULL WHERE id = ?');
            $saveError->execute([json_encode([$error], JSON_UNESCAPED_UNICODE), (int) $job['id']]);
            return ['ok' => false, 'message' => 'Generasi tahap ' . $stageNo . ' gagal: ' . $error, 'retryable' => true, 'job_token' => $token, 'stage_no' => $stageNo];
        }

        $piece = $result['data'];
        if ($stageNo === 1) {
            $errors = ems_ai_ds_surgery_job_validate_header($piece);
            ems_ai_ds_surgery_job_record_stage($pdo, (int) $job['id'], 1, $stageType, $errors === [], $piece, $errors);
            if ($errors !== []) {
                $saveError = $pdo->prepare('UPDATE ai_surgery_generation_jobs SET last_error = ?, lock_expires_at = NULL WHERE id = ?');
                $saveError->execute([json_encode($errors, JSON_UNESCAPED_UNICODE), (int) $job['id']]);
                return ['ok' => false, 'message' => 'Kerangka AI perlu diperbaiki: ' . implode('; ', $errors), 'retryable' => true, 'job_token' => $token, 'stage_no' => 1];
            }
            $outlineJson = json_encode($piece, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $advance = $pdo->prepare('UPDATE ai_surgery_generation_jobs SET outline_json = ?, planned_step_count = ?, next_stage_no = 2, last_error = NULL, lock_expires_at = NULL WHERE id = ?');
            $advance->execute([$outlineJson, count($piece['outline_tahapan']), (int) $job['id']]);
            return ['ok' => true, 'done' => false, 'job_token' => $token, 'stage_no' => 1, 'planned_steps' => count($piece['outline_tahapan']), 'message' => 'Kerangka tersimpan. Model menentukan ' . count($piece['outline_tahapan']) . ' tahap; menyusun tahap 1.'];
        }

        $newSteps = ems_ai_ds_extract_surgery_steps($piece);
        if ($stageType === 'repair') {
            if (count($newSteps) !== 1) $errors = ['model harus mengembalikan tepat satu tahap perbaikan'];
            else $errors = ems_ai_ds_surgery_job_validate_step($newSteps[0], $caseText);
        } else {
            $outlineChunk = array_slice($header['outline_tahapan'], ($stageNo - 2) * $chunkSize, $chunkSize);
            if (count($newSteps) !== count($outlineChunk)) $errors = ['batch harus mengembalikan tepat ' . count($outlineChunk) . ' tahap'];
            else {
                $errors = [];
                foreach ($newSteps as $offset => $itemErrors) {
                    foreach (ems_ai_ds_surgery_job_validate_step($itemErrors, $caseText) as $itemError) {
                        $errors[] = 'Tahapan operasi tahap ' . (((($stageNo - 2) * $chunkSize) + $offset) + 1) . ' ' . $itemError;
                    }
                }
            }
        }
        ems_ai_ds_surgery_job_record_stage($pdo, (int) $job['id'], $stageNo, $stageType, $errors === [], $piece, $errors);
        if ($errors !== []) {
            $saveError = $pdo->prepare('UPDATE ai_surgery_generation_jobs SET last_error = ?, lock_expires_at = NULL WHERE id = ?');
            $saveError->execute([json_encode($errors, JSON_UNESCAPED_UNICODE), (int) $job['id']]);
            return ['ok' => false, 'message' => 'Tahap ' . (($targetIndex ?? ($stageNo - 2)) + 1) . ' tersimpan sebagai draf tetapi belum valid: ' . implode('; ', $errors), 'retryable' => true, 'job_token' => $token, 'stage_no' => $stageNo];
        }

        $isRepair = $job['status'] === 'repairing';
        if ($isRepair) {
            $repairIndexes = json_decode((string) ($job['repair_indexes_json'] ?? '[]'), true) ?: [];
            $repairPosition = $stageNo - $repairStart;
            $steps[(int) $repairIndexes[$repairPosition]] = $newSteps[0];
        } else {
            foreach ($newSteps as $offset => $item) $steps[$targetIndex + $offset] = $item;
        }
        $expectedNext = $isRepair
            ? $stageNo + 1
            : $stageNo + 1;
        $advance = $pdo->prepare('UPDATE ai_surgery_generation_jobs SET next_stage_no = ?, last_error = ?, lock_expires_at = NULL WHERE id = ?');
        $advance->execute([$expectedNext, $isRepair ? (string) $job['last_error'] : null, (int) $job['id']]);

        $allStepsReady = $isRepair
            ? $expectedNext >= $repairStart + count($repairIndexes)
            : $expectedNext > $chunkCount + 1;
        if (!$allStepsReady) {
            $completedCount = count($steps);
            $plannedCount = (int) $job['planned_step_count'];
            $pct = min(92, 12 + (int) floor(($completedCount / max(1, $plannedCount)) * 76));
            $batchStart = (($stageNo - 2) * $chunkSize) + 1;
            $batchEnd = min($plannedCount, $batchStart + count($newSteps) - 1);
            $savedLabel = $isRepair ? 'Tahap ' . (($targetIndex ?? 0) + 1) . ' diperbaiki' : 'Batch tahap ' . $batchStart . '–' . $batchEnd . ' tersimpan';
            return ['ok' => true, 'done' => false, 'job_token' => $token, 'stage_no' => $stageNo, 'planned_steps' => $plannedCount, 'completed_steps' => $completedCount, 'progress' => $pct, 'message' => $savedLabel . ' dan lolos validasi.'];
        }

        $header = json_decode((string) ($job['outline_json'] ?? ''), true) ?: $header;
        unset($header['outline_tahapan']);
        ksort($steps);
        $finalData = $header;
        $finalData['tahapan_prosedur'] = array_values($steps);
        $finalErrors = ems_ai_ds_surgery_quality_errors($finalData);
        $serialized = json_encode($finalData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        if (preg_match('/\b(?:usia|berusia|tahun)\b[^\n]{0,50}\b\d{1,3}\s*[-–]\s*\d{1,3}\s*tahun\b|\b\d{1,3}\s*[-–]\s*\d{1,3}\s*tahun\b/iu', $serialized) === 1) $finalErrors[] = 'usia pasien ditulis sebagai rentang yang ambigu';
        $finalErrors = array_values(array_unique($finalErrors));
        if ($finalErrors !== []) {
            $failedIndexes = [];
            foreach ($finalErrors as $error) if (preg_match('/Tahapan operasi tahap (\d+)/u', $error, $match)) $failedIndexes[] = (int) $match[1] - 1;
            $failedIndexes = array_values(array_unique(array_filter($failedIndexes, static fn ($i) => $i >= 0 && $i < count($finalData['tahapan_prosedur']))));
            $auditCountStmt = $pdo->prepare("SELECT COUNT(*) FROM ai_surgery_generation_stages WHERE job_id = ? AND stage_type = 'audit'");
            $auditCountStmt->execute([(int) $job['id']]);
            $auditCount = (int) $auditCountStmt->fetchColumn();
            if ($failedIndexes !== [] && $auditCount < 5) {
                $auditStage = $repairStart;
                // Save the latest assembled plan as the baseline for this
                // repair round, including every step that already passed.
                ems_ai_ds_surgery_job_record_stage($pdo, (int) $job['id'], $auditStage, 'audit', false, $finalData, $finalErrors);
                $repair = $pdo->prepare("UPDATE ai_surgery_generation_jobs SET status = 'repairing', repair_indexes_json = ?, next_stage_no = ?, last_error = ?, lock_expires_at = NULL WHERE id = ?");
                $repair->execute([json_encode($failedIndexes), $auditStage, json_encode($finalErrors, JSON_UNESCAPED_UNICODE), (int) $job['id']]);
                return ['ok' => true, 'done' => false, 'job_token' => $token, 'planned_steps' => count($steps), 'completed_steps' => count($steps), 'progress' => 94, 'message' => 'Semua tahap tersimpan. Putaran perbaikan ' . ($auditCount + 1) . ' menargetkan ' . count($failedIndexes) . ' tahap yang belum lolos validasi.'];
            }
            $errorText = 'Rencana gabungan belum lolos validasi: ' . implode('; ', $finalErrors);
            $pdo->beginTransaction();
            $failedPlan = $pdo->prepare("INSERT INTO ai_surgery_plans (user_id, unit_code, division_snapshot, jenis_operasi_kategori, jenis_anestesi_input, kompleksitas, kasus_tindakan, source_report_code, result_json, status, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, 'error', ?)");
            $failedPlan->execute([
                (int) $job['user_id'], (string) $job['unit_code'], $job['division_snapshot'],
                $job['jenis_operasi_kategori'], $job['jenis_anestesi_input'], $job['kompleksitas'],
                $job['kasus_tindakan'], $job['source_report_code'], $errorText,
            ]);
            $failedPlanId = (int) $pdo->lastInsertId();
            $fail = $pdo->prepare("UPDATE ai_surgery_generation_jobs SET status = 'error', final_plan_id = ?, last_error = ?, lock_expires_at = NULL WHERE id = ?");
            $fail->execute([$failedPlanId, $errorText, (int) $job['id']]);
            $pdo->commit();
            return ['ok' => false, 'message' => $errorText . '. Draf tiap tahap tersimpan untuk ditinjau; rencana final tidak dibuat.', 'retryable' => false];
        }

        $pdo->beginTransaction();
        $insert = $pdo->prepare("INSERT INTO ai_surgery_plans (user_id, unit_code, division_snapshot, jenis_operasi_kategori, jenis_anestesi_input, kompleksitas, kasus_tindakan, source_report_code, result_json, status, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'done', NULL)");
        $insert->execute([
            (int) $job['user_id'], (string) $job['unit_code'], $job['division_snapshot'],
            $job['jenis_operasi_kategori'], $job['jenis_anestesi_input'], $job['kompleksitas'],
            $job['kasus_tindakan'], $job['source_report_code'], json_encode($finalData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $planId = (int) $pdo->lastInsertId();
        $done = $pdo->prepare("UPDATE ai_surgery_generation_jobs SET status = 'done', final_plan_id = ?, last_error = NULL, lock_expires_at = NULL WHERE id = ?");
        $done->execute([$planId, (int) $job['id']]);
        $pdo->commit();
        return ['ok' => true, 'done' => true, 'plan_id' => $planId, 'stage_no' => $stageNo, 'progress' => 100, 'message' => 'Semua tahap tersimpan, dirakit, dan lolos validasi akhir.'];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $diagnostic = substr(bin2hex(random_bytes(6)), 0, 10);
        error_log('[AI Surgery Planner][' . $diagnostic . '] Stage exception: ' . $error->getMessage());
        try {
            $unlock = $pdo->prepare('UPDATE ai_surgery_generation_jobs SET lock_expires_at = NULL, last_error = ? WHERE job_token = ? AND user_id = ?');
            $unlock->execute(['Kesalahan server kode ' . $diagnostic, $token, $userId]);
        } catch (Throwable $ignored) {
        }
        return ['ok' => false, 'message' => 'Tahap belum selesai karena kesalahan server. Kode diagnostik: ' . $diagnostic . '. Tahap sebelumnya tetap tersimpan.', 'retryable' => true, 'job_token' => $token];
    }
}

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_diagnosis_surgery.php';
require_once __DIR__ . '/../actions/ai_gemini_client.php';

function ems_ai_ds_surgery_json_response(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'ai_surgery_planner.php', '/dashboard/index.php');
ems_ai_ds_ensure_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ems_ai_ds_surgery_json_response(['ok' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}
if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
    ems_ai_ds_surgery_json_response(['ok' => false, 'message' => 'Sesi kedaluwarsa, muat ulang halaman lalu coba lagi.'], 419);
}

$user = $_SESSION['user_rh'] ?? [];
$effectiveUnit = ems_effective_unit($pdo, $user);
$division = (string) ($user['division'] ?? '');

// Do not hold the per-user PHP session lock while waiting for the AI provider.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$stageAction = trim((string) ($_POST['stage_action'] ?? ''));
if ($stageAction === 'continue') {
    $jobToken = trim((string) ($_POST['job_token'] ?? ''));
    if (!preg_match('/^[a-f0-9-]{36}$/i', $jobToken)) {
        ems_ai_ds_surgery_job_json(['ok' => false, 'message' => 'Token proses tidak valid.'], 422);
    }
    $stageResult = ems_ai_ds_surgery_job_process($pdo, $jobToken, (int) ($user['id'] ?? 0), $effectiveUnit);
    ems_ai_ds_surgery_job_json($stageResult, !empty($stageResult['ok']) ? 200 : (!empty($stageResult['busy']) ? 409 : 502));
}

// "Generate Ulang" dari riwayat: pakai ulang PERSIS input dari baris asal
// (bukan dari form), dan lewati pengecekan kode-sudah-dipakai karena ini
// memang sengaja generate ulang dengan kode referensi yang sama.
$regenerateOfId = (int) ($_POST['regenerate_of'] ?? 0);
$diagnosisCode = null;
$isRegenerate = false;

if ($regenerateOfId > 0) {
    $origStmt = $pdo->prepare("SELECT * FROM ai_surgery_plans WHERE id = ? AND unit_code = ?");
    $origStmt->execute([$regenerateOfId, $effectiveUnit]);
    $orig = $origStmt->fetch(PDO::FETCH_ASSOC);
    if (!$orig) {
        ems_ai_ds_surgery_json_response(['ok' => false, 'message' => 'Rencana operasi asal untuk generate ulang tidak ditemukan.'], 404);
    }
    $jenisOperasi = (string) $orig['jenis_operasi_kategori'];
    $jenisAnestesi = (string) $orig['jenis_anestesi_input'];
    $kompleksitas = 'Auto';
    $kasusTindakan = (string) $orig['kasus_tindakan'];
    $diagnosisCode = $orig['source_report_code'] !== null ? (string) $orig['source_report_code'] : null;
    $isRegenerate = true;
} else {
    $jenisOperasi = in_array($_POST['jenis_operasi'] ?? '', ['Mayor', 'Minor'], true) ? $_POST['jenis_operasi'] : 'Mayor';
    $jenisAnestesi = trim((string) ($_POST['jenis_anestesi'] ?? ''));
    $kompleksitas = 'Auto'; // legacy DB enum only; it does not control plan length.
    $kasusTindakan = trim((string) ($_POST['kasus_tindakan'] ?? ''));
    $diagnosisCodeInput = trim((string) ($_POST['diagnosis_code'] ?? ''));
    $diagnosisCode = $diagnosisCodeInput !== '' ? $diagnosisCodeInput : null;

    if ($jenisAnestesi === '' || $kasusTindakan === '') {
        ems_ai_ds_surgery_json_response(['ok' => false, 'message' => 'Jenis anestesi dan kasus medis / tindakan wajib diisi.'], 422);
    }

    if ($diagnosisCode !== null && ems_ai_ds_report_code_used_on($pdo, 'ai_surgery_plans', $diagnosisCode, $effectiveUnit)) {
        ems_ai_ds_surgery_json_response(['ok' => false, 'message' => 'Kode referensi ini sudah pernah dipakai di AI Surgery Planner. Gunakan tombol "Generate Ulang" pada riwayat kalau ingin membuat ulang dengan kode yang sama, atau pakai kode referensi lain.'], 409);
    }
}

$jenisOperasi = ems_ai_ds_effective_operation_category($jenisOperasi, $kasusTindakan);

// Attach the canonical diagnosis context server-side. Do not rely only on the
// browser's copied textarea: it may be stale or edited after fetching a code.
$diagnosisContext = '';
if ($diagnosisCode !== null) {
    $sourceReport = ems_ai_ds_find_diagnosis_report_by_code($pdo, $diagnosisCode, $effectiveUnit);
    if (!$sourceReport) {
        ems_ai_ds_surgery_json_response(['ok' => false, 'message' => 'Kode laporan diagnosis tidak ditemukan pada unit aktif. Ambil ulang data diagnosis sebelum membuat rencana.'], 404);
    }
    $sourceData = [];
    $decodedSource = json_decode((string) ($sourceReport['result_json'] ?? ''), true);
    if (is_array($decodedSource)) {
        $sourceData = ems_ai_ds_normalize_diagnosis_result($decodedSource, (string) ($sourceReport['anamnesis'] ?? ''));
    }
    $sourceDob = trim((string) ($sourceReport['patient_dob'] ?? ''));
    $sourceAge = null;
    if ($sourceDob !== '') {
        try {
            $birthDate = new DateTimeImmutable($sourceDob);
            $sourceAge = $birthDate->diff(new DateTimeImmutable('today'))->y;
        } catch (Throwable $ignored) {
            $sourceAge = null;
        }
    }
    $diagnosisContext = json_encode([
        'kode_laporan' => (string) ($sourceReport['report_code'] ?? $diagnosisCode),
        'nama_pasien' => (string) ($sourceReport['patient_name'] ?? ''),
        'jenis_kelamin' => (string) ($sourceReport['patient_gender'] ?? ''),
        'tanggal_lahir' => $sourceDob,
        'usia_pada_tanggal_pemeriksaan' => $sourceAge,
        'anamnesis_awal' => (string) ($sourceReport['anamnesis'] ?? ''),
        'anamnesis_final' => (string) ($sourceData['anamnesis_lengkap'] ?? ''),
        'diagnosis_utama' => (string) ($sourceData['diagnosis_utama'] ?? ''),
        'diagnosis_banding' => $sourceData['diagnosis_banding'] ?? [],
        'kasus_tindakan' => (string) ($sourceData['kasus_tindakan'] ?? ''),
        'jenis_operasi' => (string) ($sourceData['jenis_operasi'] ?? ''),
        'jenis_anestesi' => (string) ($sourceData['jenis_anestesi'] ?? ''),
        'gcs' => (string) ($sourceData['gcs'] ?? ''),
        'ttv' => $sourceData['ttv'] ?? [],
        'tindakan_igd_selesai' => $sourceData['emergency'] ?? [],
        'hasil_lab_skenario' => $sourceData['lab'] ?? [],
        'rekomendasi_radiologi' => $sourceData['radiologi_terstruktur'] ?? [],
        'handoff' => $sourceData['handoff_pemeriksaan_penunjang'] ?? [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$complexityEvidence = implode("\n", array_filter([$kasusTindakan, $diagnosisContext], static fn ($value) => trim((string) $value) !== ''));
$kompleksitas = ems_ai_ds_recommend_surgery_complexity($complexityEvidence); // Legacy enum metadata; never used as a step-count target.

$systemPrompt = ems_ai_ds_build_system_prompt($pdo, 'ai_surgery_planner', ems_ai_ds_default_surgery_system_prompt());
$template = ems_ai_get_active_prompt_template($pdo, 'ai_surgery_planner');
$userPromptTemplate = trim((string) ($template['user_prompt_template'] ?? '')) !== ''
    ? (string) $template['user_prompt_template']
    : "JENIS OPERASI: {{jenis_operasi}}\nJENIS ANESTESI: {{jenis_anestesi}}\nJUMLAH TAHAP: ditentukan model sesuai kebutuhan klinis kasus, tanpa target angka\nKASUS MEDIS / TINDAKAN YANG DIPERLUKAN:\n{{kasus_tindakan}}";
// Older database templates explicitly demanded an exact preset count. Remove
// that legacy clause at runtime too, so a skipped DB migration cannot restore it.
$userPromptTemplate = preg_replace(
    '/,\s*dengan\s+"tahapan_prosedur"\s+berjumlah\s+PERSIS\s+\{\{jumlah_langkah\}\}\s+langkah\s+\(tidak\s+kurang,\s*tidak\s+lebih\)\./iu',
    '. Model menentukan jumlah tahap yang diperlukan dan tidak mengejar hitungan tertentu.',
    $userPromptTemplate
) ?? $userPromptTemplate;
$userPrompt = str_replace(
    ['{{jenis_operasi}}', '{{jenis_anestesi}}', '{{kompleksitas}}', '{{jumlah_langkah}}', '{{kasus_tindakan}}'],
    [$jenisOperasi, $jenisAnestesi, 'tanpa preset; model menentukan detail tahapan dari kasus', 'ditentukan model; tidak ada angka target', $kasusTindakan],
    $userPromptTemplate
);
$userPrompt = str_replace(
    'Kembalikan seluruh rencana dalam satu JSON sesuai schema.',
    'Kembalikan hanya bagian yang diminta pada tahap generasi saat ini sesuai schema respons.',
    $userPrompt
);
if ($diagnosisContext !== '') {
    $userPrompt .= "\n\nKONTEKS KANONIK DARI LAPORAN DIAGNOSIS " . $diagnosisCode . " (sumber utama; jangan bertentangan dengan ini). Identitas/usia dihitung tepat dari DOB; jangan menulis rentang usia atau mengubah jenis kelamin/nama:\n" . $diagnosisContext
        . "\n\nKESINAMBUNGAN TINDAKAN WAJIB: bagian tindakan_igd_selesai di atas adalah tindakan yang telah dilakukan sebelum transfer. Mulai skenario operasi dari keadaan pasien saat tiba di OK dan lanjutkan secara kronologis. Jika IGD menekan/membalut luka, pada awal tindakan OK DPJP membuka balutan spesifik itu dengan gunting perban sambil Asisten 1 menyiapkan kasa steril baru untuk mempertahankan tekanan. Jika laporan IGD menyebut darah menggenang/aktif, Asisten 2 menyerahkan kateter suction steril yang tersambung ke mesin suction bedah kepada DPJP untuk mengangkat darah; jangan menulis 'menghisap darah' tanpa mesin dan kateter suction, jangan menggunakan mulut. Jangan melakukan ulang penanganan IGD tanpa alasan klinis dalam skenario. Setiap tahap wajib menyebut instrumen/bahan yang dipakai di aksi /me. Jika Asisten mengambil alat, tulis perintah DPJP dengan nama alat, jawaban singkat Asisten, lalu aksi pengambilan/penyerahan alat.";
}

$userPrompt .= "\n\nKONTRAK JUMLAH TAHAP: Tentukan sendiri jumlah tahap yang wajar untuk menyelesaikan kasus ini secara runtut dan cukup detail. Tidak ada target cepat/sedang/lama maupun angka tahap yang harus dipenuhi. Jangan menambah tahap pengisi, mengulang tindakan, atau memecah satu tindakan hanya untuk memperbanyak jumlah. Kembalikan satu JSON lengkap sesuai schema, termasuk seluruh tahapan_prosedur dalam satu respons.";

$result = null;
if ($stageAction === 'start') {
    $token = trim((string) ($_POST['job_token'] ?? ''));
    if (!preg_match('/^[a-f0-9-]{36}$/i', $token)) {
        ems_ai_ds_surgery_job_json(['ok' => false, 'message' => 'Token proses tidak valid.'], 422);
    }
    $existingJob = $pdo->prepare('SELECT id FROM ai_surgery_generation_jobs WHERE job_token = ? AND user_id = ? AND unit_code = ? LIMIT 1');
    $existingJob->execute([$token, (int) ($user['id'] ?? 0), $effectiveUnit]);
    if ($existingJob->fetchColumn()) {
        $stageResult = ems_ai_ds_surgery_job_process($pdo, $token, (int) ($user['id'] ?? 0), $effectiveUnit);
        ems_ai_ds_surgery_job_json($stageResult, !empty($stageResult['ok']) ? 200 : (!empty($stageResult['busy']) ? 409 : 502));
    }
    $jobInsert = $pdo->prepare("INSERT INTO ai_surgery_generation_jobs (job_token, user_id, unit_code, division_snapshot, jenis_operasi_kategori, jenis_anestesi_input, kompleksitas, kasus_tindakan, source_report_code, diagnosis_context, system_prompt, user_prompt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $jobInsert->execute([
        $token, (int) ($user['id'] ?? 0), $effectiveUnit, $division, $jenisOperasi, $jenisAnestesi,
        $kompleksitas, $kasusTindakan, $diagnosisCode, $diagnosisContext, $systemPrompt, $userPrompt,
    ]);
    $stageResult = ems_ai_ds_surgery_job_process($pdo, $token, (int) ($user['id'] ?? 0), $effectiveUnit);
    ems_ai_ds_surgery_job_json($stageResult, !empty($stageResult['ok']) ? 200 : 502);
}

$result = ems_ai_ds_call_gemini(
    $pdo,
    $systemPrompt,
    $userPrompt,
    'ai_surgery_planner',
    isset($user['id']) ? (int) $user['id'] : null,
    ems_ai_ds_surgery_response_schema()
);
$data = is_array($result['data'] ?? null) ? $result['data'] : [];
$data['tahapan_prosedur'] = ems_ai_ds_extract_surgery_steps($data);
$checkPlanQuality = static function (array $candidate): array {
    $errors = ems_ai_ds_surgery_quality_errors($candidate);
    $serialized = json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (preg_match('/\b(?:usia|berusia|tahun)\b[^\n]{0,50}\b\d{1,3}\s*[-–]\s*\d{1,3}\s*tahun\b|\b\d{1,3}\s*[-–]\s*\d{1,3}\s*tahun\b/iu', (string) $serialized) === 1) {
        $errors[] = 'usia pasien ditulis sebagai rentang yang ambigu';
    }
    return array_values(array_unique($errors));
};
$qualityErrors = $result['ok'] ? $checkPlanQuality($data) : [(string) ($result['error'] ?? 'Model tidak mengembalikan JSON rencana operasi.')];

if (!$result['ok'] && $qualityErrors !== []) {
    $errorMessage = 'Model AI belum dapat menyelesaikan rencana operasi: ' . implode('; ', $qualityErrors) . '. Tidak disimpan sebagai rencana selesai.';

    $insertFail = $pdo->prepare("
        INSERT INTO ai_surgery_plans (user_id, unit_code, division_snapshot, jenis_operasi_kategori, jenis_anestesi_input, kompleksitas, kasus_tindakan, source_report_code, result_json, status, error_message)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, 'error', ?)
    ");
    $insertFail->execute([
        isset($user['id']) ? (int) $user['id'] : 0,
        $effectiveUnit,
        $division,
        $jenisOperasi,
        $jenisAnestesi,
        $kompleksitas,
        $kasusTindakan,
        $diagnosisCode,
        $errorMessage,
    ]);

    ems_ai_ds_surgery_json_response(['ok' => false, 'message' => $errorMessage], 502);
}
if ($qualityErrors !== []) {
    $message = 'Rencana operasi belum lolos validasi akhir: ' . implode('; ', $qualityErrors) . '. Tahapan yang lolos tetap diperiksa per bagian dan tidak diganti oleh perbaikan ringkasan. Tidak disimpan sebagai rencana selesai.';
    $failStmt = $pdo->prepare("INSERT INTO ai_surgery_plans (user_id, unit_code, division_snapshot, jenis_operasi_kategori, jenis_anestesi_input, kompleksitas, kasus_tindakan, source_report_code, result_json, status, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'error', ?)");
    $failStmt->execute([
        isset($user['id']) ? (int) $user['id'] : 0, $effectiveUnit, $division, $jenisOperasi,
        $jenisAnestesi, $kompleksitas, $kasusTindakan, $diagnosisCode,
        json_encode($data, JSON_UNESCAPED_UNICODE), $message,
    ]);
    ems_ai_ds_surgery_json_response(['ok' => false, 'message' => $message], 502);
}

$insert = $pdo->prepare("
    INSERT INTO ai_surgery_plans (user_id, unit_code, division_snapshot, jenis_operasi_kategori, jenis_anestesi_input, kompleksitas, kasus_tindakan, source_report_code, result_json, status, error_message)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'done', NULL)
");
$insert->execute([
    isset($user['id']) ? (int) $user['id'] : 0,
    $effectiveUnit,
    $division,
    $jenisOperasi,
    $jenisAnestesi,
    $kompleksitas,
    $kasusTindakan,
    $diagnosisCode,
    json_encode($data, JSON_UNESCAPED_UNICODE),
]);

$planId = (int) $pdo->lastInsertId();

ems_ai_ds_surgery_json_response(['ok' => true, 'message' => 'Rencana operasi berhasil dibuat.', 'plan_id' => $planId]);
