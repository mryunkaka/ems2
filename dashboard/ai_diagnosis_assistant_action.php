<?php
date_default_timezone_set('Asia/Jakarta');
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../auth/auth_guard.php';
require_once __DIR__ . '/../auth/csrf.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/ai_diagnosis_surgery.php';
require_once __DIR__ . '/../config/ai_radiology.php';
require_once __DIR__ . '/../config/ai_laboratory.php';
require_once __DIR__ . '/../actions/ai_gemini_client.php';

function ems_ai_ds_merge_stage_reports(array $core, array $final): array
{
    if (is_array($core['ttv'] ?? null) && is_array($final['ttv'] ?? null)) {
        foreach ($final['ttv'] as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $finalValue = trim((string) ($item['value'] ?? ''));
            if (preg_match('/data\s+belum|belum\s+(?:diukur|dinilai|tersedia)/iu', $finalValue) === 1 && isset($core['ttv'][$index]) && is_array($core['ttv'][$index])) {
                $coreValue = trim((string) ($core['ttv'][$index]['value'] ?? ''));
                $core['ttv'][$index] = $coreValue !== '' && preg_match('/data\s+belum|belum\s+(?:diukur|dinilai|tersedia)/iu', $coreValue) !== 1 ? $core['ttv'][$index] : $item;
            } else {
                $core['ttv'][$index] = $item;
            }
        }
        $final['ttv'] = $core['ttv'];
    }
    foreach (['gcs_score' => 'gcs', 'tanda_vital' => 'ttv', 'vital_signs' => 'ttv', 'laboratorium' => 'lab', 'emergency_actions' => 'emergency', 'tindakan_emergency' => 'emergency', 'penanganan_emergency' => 'emergency'] as $source => $target) {
        if ((!isset($final[$target]) || $final[$target] === '' || $final[$target] === []) && isset($final[$source])) {
            $final[$target] = $final[$source];
        }
        if ((!isset($core[$target]) || $core[$target] === '' || $core[$target] === []) && isset($core[$source])) {
            $core[$target] = $core[$source];
        }
    }
    if (is_array($core['gcs'] ?? null)) {
        $coreGcs = $core['gcs'];
        $coreComponents = trim((string) ($coreGcs['komponen'] ?? $coreGcs['components'] ?? $coreGcs['score_components'] ?? ''));
        $coreTotal = $coreGcs['total'] ?? $coreGcs['score'] ?? $coreGcs['value'] ?? null;
        $coreERaw = $coreGcs['skor_e'] ?? $coreGcs['e'] ?? $coreGcs['eye'] ?? null;
        $coreVRaw = $coreGcs['skor_v'] ?? $coreGcs['v'] ?? $coreGcs['verbal'] ?? null;
        $coreMRaw = $coreGcs['skor_m'] ?? $coreGcs['m'] ?? $coreGcs['motor'] ?? null;
        if ($coreERaw !== null && $coreVRaw !== null && $coreMRaw !== null) {
            $e = (int) $coreERaw; $v = (int) $coreVRaw; $m = (int) $coreMRaw;
            if ($e >= 1 && $e <= 4 && $v >= 1 && $v <= 5 && $m >= 1 && $m <= 6) {
                $coreComponents = "E{$e} V{$v} M{$m}";
                $coreTotal = $e + $v + $m;
            }
        }
        $core['gcs'] = $coreComponents !== ''
            ? $coreComponents . ($coreTotal !== null ? ' (' . $coreTotal . ')' : '')
            : trim((string) ($coreGcs['score'] ?? $coreGcs['value'] ?? $coreGcs['total'] ?? json_encode($coreGcs, JSON_UNESCAPED_UNICODE)));
    }
    if (is_array($final['gcs'] ?? null)) {
        $finalGcs = $final['gcs'];
        $finalComponents = trim((string) ($finalGcs['komponen'] ?? $finalGcs['components'] ?? $finalGcs['score_components'] ?? ''));
        $finalTotal = $finalGcs['total'] ?? $finalGcs['score'] ?? $finalGcs['value'] ?? null;
        $finalERaw = $finalGcs['skor_e'] ?? $finalGcs['e'] ?? $finalGcs['eye'] ?? null;
        $finalVRaw = $finalGcs['skor_v'] ?? $finalGcs['v'] ?? $finalGcs['verbal'] ?? null;
        $finalMRaw = $finalGcs['skor_m'] ?? $finalGcs['m'] ?? $finalGcs['motor'] ?? null;
        if ($finalERaw !== null && $finalVRaw !== null && $finalMRaw !== null) {
            $e = (int) $finalERaw; $v = (int) $finalVRaw; $m = (int) $finalMRaw;
            if ($e >= 1 && $e <= 4 && $v >= 1 && $v <= 5 && $m >= 1 && $m <= 6) {
                $finalComponents = "E{$e} V{$v} M{$m}";
                $finalTotal = $e + $v + $m;
            }
        }
        $final['gcs'] = $finalComponents !== ''
            ? $finalComponents . ($finalTotal !== null ? ' (' . $finalTotal . ')' : '')
            : trim((string) ($finalGcs['score'] ?? $finalGcs['value'] ?? $finalGcs['total'] ?? json_encode($finalGcs, JSON_UNESCAPED_UNICODE)));
    }
    if (is_string($core['gcs'] ?? null) && preg_match('/\bE\s*[1-4]\s*V\s*[1-5]\s*M\s*[1-6]\b/iu', $core['gcs']) === 1
        && preg_match('/\bE\s*[1-4]\s*V\s*[1-5]\s*M\s*[1-6]\b/iu', (string) ($final['gcs'] ?? '')) !== 1) {
        $final['gcs'] = $core['gcs'];
    }
    foreach (['core', 'final'] as $stage) {
        if (preg_match('/\bE\s*([1-4])\s*V\s*([1-5])\s*M\s*([1-6])(?:\s*[-,;:]\s*(?:total\s*)?(\d{1,2})|\s*\(\s*(\d{1,2})\s*\))?/iu', (string) ($$stage['gcs'] ?? ''), $gcsParts) === 1) {
            $sum = (int) $gcsParts[1] + (int) $gcsParts[2] + (int) $gcsParts[3];
            $$stage['gcs'] = 'E' . $gcsParts[1] . ' V' . $gcsParts[2] . ' M' . $gcsParts[3] . ' (' . $sum . ')';
        }
    }
    foreach (['core', 'final'] as $stage) {
        $stageData =& $$stage;
        if (is_array($stageData['ttv'] ?? null) && is_array($stageData['ttv_estimasi_ai'] ?? null)) {
            $estimated = [];
            foreach ($stageData['ttv_estimasi_ai'] as $item) {
                if (is_array($item) && trim((string) ($item['label'] ?? '')) !== '') {
                    $estimated[mb_strtolower(trim((string) $item['label']))] = $item;
                }
            }
            foreach ($stageData['ttv'] as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $key = mb_strtolower(trim((string) ($item['label'] ?? '')));
                $value = trim((string) ($item['value'] ?? ''));
                $estimateItem = $estimated[$key] ?? ($stageData['ttv_estimasi_ai'][$index] ?? null);
                if (preg_match('/data\s+belum|belum\s+(?:diukur|dinilai|tersedia)/iu', $value) === 1 && is_array($estimateItem)) {
                    $item['value'] = $estimateItem['value'] ?? $value;
                    $item['note'] = $estimateItem['note'] ?? ($item['note'] ?? '');
                    $stageData['ttv'][$index] = $item;
                }
            }
        }
        unset($stageData);
    }
    foreach (['core', 'final'] as $stage) {
        $stageData =& $$stage;
        if ((!is_array($stageData['lab'] ?? null) || $stageData['lab'] === []) && is_array($stageData['laboratorium_terstruktur'] ?? null)) {
            $lab = $stageData['laboratorium_terstruktur'];
            $stageData['lab'] = [trim(implode(' > ', array_filter([
                $lab['department'] ?? '', $lab['category'] ?? '', $lab['level3_option'] ?? '',
                'Hasil: ' . ($lab['result'] ?? $lab['clinical_finding'] ?? 'Dalam batas skenario final')
            ])))];
        }
        if ((!is_array($stageData['radiologi'] ?? null) || $stageData['radiologi'] === []) && is_array($stageData['radiologi_terstruktur'] ?? null)) {
            $rad = $stageData['radiologi_terstruktur'];
            $stageData['radiologi'] = [trim(implode(' > ', array_filter([
                $rad['modality'] ?? '', $rad['category'] ?? '', $rad['body_region'] ?? '', $rad['projection'] ?? '',
                'Temuan: ' . ($rad['result'] ?? $rad['clinical_finding'] ?? 'Dalam batas skenario final')
            ])))];
        }
        unset($stageData);
    }
    foreach (['core', 'final'] as $stage) {
        if (!is_array(($$stage)['emergency'] ?? null)) continue;
        $normalizedEmergency = [];
        foreach (($$stage)['emergency'] as $item) {
            if (is_string($item)) {
                // Some Gemini versions serialize a complete /me ... /do ... RP
                // card as one string despite the requested object schema. Split
                // only the model-authored text; never invent action/result text.
                $raw = trim($item);
                $actor = '';
                if (preg_match('/^\s*(DPJP|Asisten\s*[12])\s*:\s*/iu', $raw, $actorMatch) === 1) {
                    $actor = preg_replace('/\s+/u', ' ', trim($actorMatch[1])) ?? trim($actorMatch[1]);
                    $raw = substr($raw, strlen($actorMatch[0]));
                }
                $action = '';
                $result = '';
                $animation = '';
                if (preg_match('/\/me\b(.*?)(?=\/do\b|\/e\b|$)/isu', $raw, $actionMatch) === 1) $action = trim($actionMatch[1]);
                if (preg_match('/\/do\b(.*?)(?=\/e\b|$)/isu', $raw, $resultMatch) === 1) $result = trim($resultMatch[1]);
                if (preg_match('/\/e\s+([a-z0-9_]+)/iu', $raw, $animationMatch) === 1) $animation = trim($animationMatch[1]);
                $normalizedEmergency[] = ['pelaku' => $actor, 'aksi' => $action, 'hasil' => $result, 'animasi' => $animation];
                continue;
            }
            if (!is_array($item)) {
                $normalizedEmergency[] = $item;
                continue;
            }
            foreach (['actor' => 'pelaku', 'action' => 'aksi', 'result' => 'hasil', 'animation' => 'animasi', 'instruction' => 'instruksi'] as $source => $target) {
                if ((!isset($item[$target]) || trim((string) $item[$target]) === '') && isset($item[$source])) $item[$target] = $item[$source];
            }
            foreach (['aksi', 'hasil'] as $field) {
                if (isset($item[$field]) && is_string($item[$field])) $item[$field] = trim((string) preg_replace('/^\s*\/(?:me|do)\s+/iu', '', $item[$field]));
            }
            $normalizedEmergency[] = $item;
        }
        $$stage['emergency'] = $normalizedEmergency;
    }
    foreach ($final as $key => $value) {
        if (is_array($value) && is_array($core[$key] ?? null) && $value !== []) {
            $core[$key] = $value;
            continue;
        }
        if (is_string($value) && trim($value) === '' && isset($core[$key])) {
            continue;
        }
        if ($value !== null && $value !== [] && $value !== '') {
            $core[$key] = $value;
        }
    }
    return $core;
}

/**
 * Model Gemini kadang memakai nama properti sinonim walaupun maknanya sama.
 * Selaraskan sinonim ke schema laporan sebelum kontrak final diperiksa.
 * Fungsi ini hanya memetakan nama field; isi klinis tetap berasal dari model.
 */
function ems_ai_ds_align_model_schema(array $data): array
{
    // Provider/model kadang memberi nama Indonesia atau salah ketik pada key
    // panjang. Petakan alias semantik tanpa mengisi konten dengan teks statis.
    $fieldAliases = [
        'roleplay_note' => ['rolepy_note', 'catatan_medis_roleplay', 'catatan_medis_dan_roleplay', 'catatan_roleplay', 'medical_roleplay_note', 'note'],
        'diagnosis_utama' => ['diagnosis_primer', 'diagnosa_utama', 'primary_diagnosis'],
        'diagnosis_banding' => ['diagnosis_diferensial', 'diagnosis_pembanding', 'differential_diagnoses'],
        'kasus_tindakan' => ['kasus_medis_tindakan', 'kasus_medis', 'medical_case_and_action'],
        'status_rencana_operasi' => ['rencana_operasi', 'operation_status', 'surgical_plan_status'],
        'sop_references' => ['rujukan_sop', 'referensi_sop', 'sop_reference'],
        'handoff' => ['serah_terima', 'handover', 'handoff_ke_ruang_operasi'],
    ];
    foreach ($fieldAliases as $canonical => $aliases) {
        if (isset($data[$canonical]) && $data[$canonical] !== '' && $data[$canonical] !== []) {
            continue;
        }
        foreach ($aliases as $alias) {
            if (isset($data[$alias]) && $data[$alias] !== '' && $data[$alias] !== []) {
                $data[$canonical] = $data[$alias];
                break;
            }
        }
    }

    // Nilai yang sudah disajikan model sebagai skenario final tidak boleh
    // membawa label internal "Estimasi AI" ke laporan pemain.
    unset($data['gcs_estimasi_ai'], $data['ttv_estimasi_ai']);
    if (isset($data['diagnosis_banding']) && !is_array($data['diagnosis_banding'])) {
        $data['diagnosis_banding'] = [(string) $data['diagnosis_banding']];
    }

    if (is_array($data['laboratorium_dan_radiologi'] ?? null)) {
        $examDecision = $data['laboratorium_dan_radiologi'];
        foreach (['laboratorium' => 'lab', 'radiologi' => 'radiologi'] as $source => $target) {
            if (isset($examDecision[$source]) && $examDecision[$source] !== '' && $examDecision[$source] !== []) {
                $entry = $examDecision[$source];
                if (!is_array($entry)) {
                    $data[$target] = [trim((string) $entry)];
                    continue;
                }
                $lines = [];
                $status = trim((string) ($entry['status'] ?? ''));
                $reason = trim((string) ($entry['alasan'] ?? $entry['reason'] ?? ''));
                if ($status !== '' || $reason !== '') {
                    $lines[] = trim(implode(' â€” ', array_filter([$status, $reason])));
                }
                $results = $entry['hasil'] ?? $entry['results'] ?? [];
                if (is_array($results)) {
                    foreach ($results as $resultName => $resultValue) {
                        if (is_array($resultValue)) {
                            foreach ($resultValue as $detailName => $detailValue) {
                                if (is_scalar($detailValue) && trim((string) $detailValue) !== '') {
                                    $lines[] = trim((string) $detailName) . ': ' . trim((string) $detailValue);
                                }
                            }
                        } elseif (is_scalar($resultValue) && trim((string) $resultValue) !== '') {
                            $lines[] = is_string($resultName) ? trim($resultName) . ': ' . trim((string) $resultValue) : trim((string) $resultValue);
                        }
                    }
                } elseif (is_string($results) && trim($results) !== '') {
                    $lines[] = trim($results);
                }
                if ($lines === [] && $reason !== '') {
                    $lines[] = $reason;
                }
                $data[$target] = $lines !== [] ? $lines : [trim((string) ($entry['deskripsi'] ?? $entry['description'] ?? ''))];
                if ($target === 'lab' && is_array($entry['laboratorium_terstruktur'] ?? null)) {
                    $structured = $entry['laboratorium_terstruktur'];
                    $data['laboratorium_terstruktur'] = [
                        'department' => trim((string) ($structured['department'] ?? $structured['departemen'] ?? '')),
                        'category' => trim((string) ($structured['category'] ?? $structured['kategori'] ?? '')),
                        'level3_option' => trim((string) ($structured['level3_option'] ?? $structured['level3'] ?? '')),
                        'specimen_type' => trim((string) ($structured['specimen_type'] ?? $structured['spesimen'] ?? '')),
                    ];
                }
                if ($target === 'radiologi' && is_array($entry['radiologi_terstruktur'] ?? null)) {
                    $structured = $entry['radiologi_terstruktur'];
                    $data['radiologi_terstruktur'] = [
                        'modality' => trim((string) ($structured['modality'] ?? $structured['modalitas'] ?? '')),
                        'category' => trim((string) ($structured['category'] ?? $structured['kategori'] ?? '')),
                        'body_region' => trim((string) ($structured['body_region'] ?? $structured['region'] ?? '')),
                        'projection' => trim((string) ($structured['projection'] ?? $structured['projection_options'] ?? '')),
                        'clinical_finding' => trim((string) ($structured['clinical_finding'] ?? $structured['temuan'] ?? '')),
                    ];
                }
            }
        }
        if ((!isset($data['laboratorium_terstruktur']) || $data['laboratorium_terstruktur'] === null) && is_array($examDecision['laboratorium_terstruktur'] ?? null)) {
            $data['laboratorium_terstruktur'] = $examDecision['laboratorium_terstruktur'];
        }
        if ((!isset($data['radiologi_terstruktur']) || $data['radiologi_terstruktur'] === null) && is_array($examDecision['radiologi_terstruktur'] ?? null)) {
            $data['radiologi_terstruktur'] = $examDecision['radiologi_terstruktur'];
        }
    }
    $supporting = $data['rekomendasi_pemeriksaan_penunjang'] ?? $data['pemeriksaan_penunjang'] ?? null;
    if (is_array($supporting)) {
        foreach (['laboratorium' => 'lab', 'radiologi' => 'radiologi'] as $source => $target) {
            if (!is_array($supporting[$source] ?? null) || $supporting[$source] === []) {
                continue;
            }
            $mappedResults = array_map(static function ($item): string {
                if (!is_array($item)) {
                    return trim((string) $item);
                }
                $category = $item['kategori'] ?? $item['category'] ?? $item['jenis'] ?? $item['nama'] ?? '';
                $result = $item['hasil_skenario'] ?? $item['clinical_finding'] ?? $item['deskripsi'] ?? $item['hasil'] ?? $item['result'] ?? '';
                return trim(implode(' â€” ', array_filter([(string) $category, (string) $result], static fn (string $part): bool => trim($part) !== '')));
            }, $supporting[$source]);
            $mappedResults = array_values(array_filter($mappedResults, static fn (string $item): bool => $item !== ''));
            if ($mappedResults !== []) {
                $data[$target] = $mappedResults;
            }
        }
    }
    foreach (['ttv', 'tanda_vital', 'vital_signs'] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            continue;
        }
        $normalized = [];
        foreach ($data[$key] as $rawLabel => $item) {
            if (is_string($item)) {
                $parts = explode(':', $item, 2);
                $item = count($parts) > 1
                    ? ['label' => trim($parts[0]), 'value' => trim($parts[1])]
                    : ['label' => is_string($rawLabel) ? trim($rawLabel) : '', 'value' => trim($item)];
            }
            if (!is_array($item)) continue;
            $item['label'] = trim((string) ($item['label'] ?? $item['parameter'] ?? $item['name'] ?? $item['jenis'] ?? (is_string($rawLabel) ? $rawLabel : '')));
            $item['value'] = trim((string) ($item['value'] ?? $item['nilai'] ?? $item['reading'] ?? $item['hasil'] ?? ''));
            $item['note'] = trim((string) ($item['note'] ?? $item['catatan'] ?? $item['keterangan'] ?? $item['status'] ?? $item['interpretasi'] ?? ''));
            if ($item['label'] !== '' || $item['value'] !== '') {
                $normalized[] = $item;
            }
        }
        if ($normalized !== []) {
            $data['ttv'] = $normalized;
            break;
        }
    }

    foreach (['emergency', 'emergency_actions', 'tindakan_emergency', 'penanganan_emergency'] as $key) {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            continue;
        }
        $normalized = [];
        foreach ($data[$key] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $normalized[] = ems_ai_ds_normalize_emergency_item_aliases($item);
        }
        if ($normalized !== []) {
            $data['emergency'] = $normalized;
            break;
        }
    }

    if (isset($data['gcs']) && is_string($data['gcs'])) {
        $decodedGcs = json_decode($data['gcs'], true);
        if (is_array($decodedGcs)) {
            $data['gcs'] = $decodedGcs;
        }
    }
    if (isset($data['gcs']) && is_array($data['gcs'])) {
        $score = trim((string) ($data['gcs']['score'] ?? $data['gcs']['value'] ?? $data['gcs']['total'] ?? ''));
        $e = $data['gcs']['e'] ?? $data['gcs']['eye'] ?? null;
        $v = $data['gcs']['v'] ?? $data['gcs']['verbal'] ?? null;
        $m = $data['gcs']['m'] ?? $data['gcs']['motor'] ?? null;
        if ($e !== null && $v !== null && $m !== null) {
            $score = 'E' . (int) $e . ' V' . (int) $v . ' M' . (int) $m . ($score !== '' ? ' (' . $score . ')' : '');
        }
        $data['gcs'] = $score;
    }

    return $data;
}

/**
 * Model kadang membalas satu field sebagai array (mis. daftar semua opsi
 * proyeksi) padahal diminta satu string Ã¢â‚¬â€ ambil elemen pertama sebagai
 * fallback yang masuk akal alih-alih langsung menolak seluruh object.
 */
function ems_ai_ds_scalar_or_first($value): string
{
    if (is_array($value)) {
        $value = reset($value);
    }
    return trim((string) $value);
}

function ems_ai_ds_sanitize_structured_radiology($input): ?array
{
    if (!is_array($input)) {
        return null;
    }

    $modality = ems_ai_ds_scalar_or_first($input['modality'] ?? '');
    $category = ems_ai_ds_scalar_or_first($input['category'] ?? '');
    $bodyRegion = ems_ai_ds_scalar_or_first($input['body_region'] ?? '');
    $projection = ems_ai_ds_scalar_or_first($input['projection'] ?? '');
    $clinicalFinding = ems_ai_ds_scalar_or_first($input['clinical_finding'] ?? '');

    // Pasien tidak butuh pencitraan sama sekali Ã¢â‚¬â€ tetap valid, hanya tanpa target modality.
    if ($modality === '' && $category === '' && $bodyRegion === '' && $projection === '') {
        return $clinicalFinding !== '' ? ['modality' => '', 'category' => '', 'body_region' => '', 'projection' => '', 'clinical_finding' => $clinicalFinding] : null;
    }

    if (!in_array($modality, ems_ai_radiology_modalities(), true)) {
        return null;
    }
    if (!in_array($category, ems_ai_radiology_categories($modality), true)) {
        return null;
    }
    if (!in_array($bodyRegion, ems_ai_radiology_body_regions_for($modality, $category), true)) {
        return null;
    }
    if (!ems_ai_radiology_is_valid_selection($modality, $category, $bodyRegion, $projection)) {
        return null;
    }
    if (!in_array($clinicalFinding, ems_ai_radiology_clinical_findings(), true)) {
        return null;
    }

    return [
        'modality' => $modality,
        'category' => $category,
        'body_region' => $bodyRegion,
        'projection' => $projection,
        'clinical_finding' => $clinicalFinding,
    ];
}

function ems_ai_ds_sanitize_structured_laboratory($input): ?array
{
    if (!is_array($input)) {
        return null;
    }

    $department = ems_ai_ds_scalar_or_first($input['department'] ?? '');
    $category = ems_ai_ds_scalar_or_first($input['category'] ?? '');
    $level3Option = ems_ai_ds_scalar_or_first($input['level3_option'] ?? '');
    $specimenType = ems_ai_ds_scalar_or_first($input['specimen_type'] ?? '');

    // Pasien tidak butuh pemeriksaan laboratorium sama sekali.
    if ($department === '' && $category === '' && $specimenType === '') {
        return null;
    }

    if (!in_array($department, ems_ai_laboratory_departments(), true)) {
        return null;
    }
    if (!in_array($category, ems_ai_laboratory_categories($department), true)) {
        return null;
    }

    $catInfo = ems_ai_laboratory_category_info($department, $category);
    $level3Value = ($catInfo['type'] ?? '') === 'select' ? $level3Option : null;

    if (!ems_ai_laboratory_is_valid_selection($department, $category, $level3Value, $specimenType)) {
        return null;
    }

    return [
        'department' => $department,
        'category' => $category,
        'level3_option' => $level3Value ?? '',
        'specimen_type' => $specimenType,
    ];
}

function ems_ai_ds_json_response(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function ems_ai_ds_is_retryable_model_error(string $error): bool
{
    return preg_match('/timeout|timed\s*out|temporar|high demand|overloaded|resource[_ ]exhausted|rate limit|\b(?:429|500|502|503|504)\b|connection reset|could not resolve host|respons kosong|bukan format JSON/iu', $error) === 1;
}

function ems_ai_ds_call_diagnosis_with_retry(PDO $pdo, string $systemPrompt, string $userPrompt, string $featureKey, ?int $createdBy): array
{
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $result = ems_ai_ds_call_gemini($pdo, $systemPrompt, $userPrompt, $featureKey, $createdBy);
        if (!empty($result['ok'])) {
            return $result;
        }
        $error = (string) ($result['error'] ?? '');
        if ($attempt >= 2 || !ems_ai_ds_is_retryable_model_error($error)) {
            return $result;
        }
        usleep(500000);
    }
    return ['ok' => false, 'error' => 'Model AI gagal setelah percobaan ulang sementara.'];
}

function ems_ai_ds_store_failure(PDO $pdo, array $user, string $unit, string $division, string $anamnesis, string $patientName, ?string $patientGender, ?string $patientDob, string $patientCitizenId, string $errorMessage): void
{
    try {
        $stmt = $pdo->prepare("INSERT INTO ai_diagnosis_reports
            (user_id, unit_code, division_snapshot, anamnesis, patient_name, patient_gender, patient_dob, patient_citizen_id, result_json, status, error_message)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, 'error', ?)");
        $stmt->execute([(int) ($user['id'] ?? 0), $unit, $division, $anamnesis,
            $patientName !== '' ? $patientName : null, $patientGender, $patientDob,
            $patientCitizenId !== '' ? $patientCitizenId : null, $errorMessage]);
    } catch (Throwable $storageError) {
        error_log('AI Diagnosis Assistant gagal menyimpan riwayat error: ' . $storageError->getMessage());
    }
}

ems_enforce_dashboard_page_access($_SESSION['user_rh']['division'] ?? '', 'ai_diagnosis_assistant.php', '/dashboard/index.php');
ems_ai_ds_ensure_tables($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ems_ai_ds_json_response(['ok' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}
if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
    ems_ai_ds_json_response(['ok' => false, 'message' => 'Sesi kedaluwarsa, muat ulang halaman lalu coba lagi.'], 419);
}

$user = $_SESSION['user_rh'] ?? [];
$anamnesis = trim((string) ($_POST['anamnesis'] ?? ''));

if ($anamnesis === '') {
    ems_ai_ds_json_response(['ok' => false, 'message' => 'Anamnesis / temuan medis wajib diisi.'], 422);
}

$patientName = trim((string) ($_POST['patient_name'] ?? ''));
$patientGenderInput = trim((string) ($_POST['patient_gender'] ?? ''));
$patientGender = in_array($patientGenderInput, ['Laki-laki', 'Perempuan'], true) ? $patientGenderInput : null;
// Validasi koherensi identitas: kehamilan/riwayat janin tidak mungkin
// dipasangkan dengan identitas laki-laki. Ini koreksi konflik input, bukan
// pengganti penalaran klinis model.
if ($patientGender === 'Laki-laki' && preg_match('/\b(?:hamil|janin|plasenta|gravid|abrupsi|kehamilan|seksio\s+sesarea|caesar)\b/iu', $anamnesis) === 1) {
    $patientGender = 'Perempuan';
}
$patientDobInput = trim((string) ($_POST['patient_dob'] ?? ''));
$patientDob = null;
if ($patientDobInput !== '') {
    $dobTimestamp = strtotime($patientDobInput);
    $patientDob = $dobTimestamp ? date('Y-m-d', $dobTimestamp) : null;
}
$patientCitizenId = mb_strtoupper(trim((string) ($_POST['patient_citizen_id'] ?? '')), 'UTF-8');

$effectiveUnit = ems_effective_unit($pdo, $user);
$division = (string) ($user['division'] ?? '');

// Persist the authenticated context before the long model call. The default
// PHP file-session handler locks this user's session until the request ends;
// releasing it lets their other tabs/pages continue loading during generation.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$systemPrompt = ems_ai_ds_build_system_prompt($pdo, 'ai_diagnosis_assistant', ems_ai_ds_default_diagnosis_system_prompt());
$systemPrompt .= "\n\nREFERENSI KATALOG RADIOLOGI (format: Modality > Category > Body Region > [Projection/Options], pilih PERSIS salah satu kombinasi untuk \"radiologi_terstruktur\"):\n"
    . ems_ai_radiology_catalog_reference_text()
    . "\n\nREFERENSI TEMUAN KLINIS (pilih PERSIS salah satu untuk \"radiologi_terstruktur.clinical_finding\"):\n"
    . ems_ai_radiology_clinical_findings_reference_text();
$systemPrompt .= "\n\nREFERENSI KATALOG LABORATORIUM (format: Department > Category > [Level3 Options] > Spesimen: [opsi spesimen], pilih PERSIS salah satu kombinasi untuk \"laboratorium_terstruktur\"):\n"
    . ems_ai_laboratory_catalog_reference_text();

$stageOneSystemPrompt = ems_ai_ds_build_system_prompt($pdo, 'ai_diagnosis_assistant_stage_1', ems_ai_ds_default_diagnosis_system_prompt(), false);
$template

 = ems_ai_get_active_prompt_template($pdo, 'ai_diagnosis_assistant');
$userPromptTemplate = trim((string) ($template['user_prompt_template'] ?? '')) !== ''
    ? (string) $template['user_prompt_template']
    : "ANAMNESIS:\n{{anamnesis}}";
$userPrompt = str_replace('{{anamnesis}}', $anamnesis, $userPromptTemplate);

// Kalau identitas pasien diisi, sisipkan sebagai konteks pasti di depan anamnesis
// supaya model AI memakai data NYATA ini (usia/jenis kelamin dari input, bukan
// menebak sendiri) Ã¢â‚¬â€ usia dihitung dari DOB kalau ada.
$identityLines = [];
if ($patientName !== '') {
    $identityLines[] = 'Nama: ' . $patientName;
}
if ($patientGender !== null) {
    $identityLines[] = 'Jenis Kelamin: ' . $patientGender;
}
if ($patientDob !== null) {
    $identityLines[] = 'Usia: ' . ems_ai_radiology_age_label($patientDob);
}
if ($identityLines !== []) {
    $userPrompt = "IDENTITAS PASIEN:\n" . implode("\n", $identityLines) . "\n\n" . $userPrompt;
}

$stageOnePrompt = $userPrompt
    . "\n\nTAHAP 1/2 Ã¢â‚¬â€ SUSUN JSON INTI SAJA. Tangani identitas pasien, anamnesis final, diagnosis utama dan banding, GCS, TTV, pemeriksaan pupil, riwayat operasi, serta kasus medis/tindakan."
    . " Susun JSON INTI ringkas dengan key wajib: anamnesis_lengkap, diagnosis_utama, diagnosis_banding, gcs, ttv, pemeriksaan_pupil, riwayat_operasi, kasus_tindakan, jenis_operasi, jenis_anestesi, status_rencana_operasi."
    . " TTV wajib tepat 5 objek: Tekanan Darah, Nadi / HR, Suhu, Respirasi / RR, Saturasi O2; setiap value dan note wajib konkret. GCS wajib berupa teks E/V/M dan total. Karena ini skenario roleplay, tulis angka TTV final sebagai fakta skenario, bukan 'Estimasi AI — wajib verifikasi'. Jangan mengosongkan field inti, memakai placeholder, atau menulis markdown di luar JSON.";
$stageOnePrompt .= "\nSTATUS TRANSFER WAJIB: status_rencana_operasi harus menyatakan pasien dipindahkan ke Ruang Operasi setelah stabilisasi awal; hasil laboratorium atau radiologi tidak boleh menjadi syarat transfer.";
$result = ems_ai_ds_call_diagnosis_with_retry($pdo, $stageOneSystemPrompt, $stageOnePrompt, 'ai_diagnosis_assistant_stage_1', isset($user['id']) ? (int) $user['id'] : null);

if (!$result['ok']) {
    $errorMessage = (string) ($result['error'] ?? 'Model AI gagal merespons. Silakan coba lagi.');

    ems_ai_ds_store_failure($pdo, $user, $effectiveUnit, $division, $anamnesis, $patientName, $patientGender, $patientDob, $patientCitizenId, $errorMessage);

    ems_ai_ds_json_response(['ok' => false, 'message' => 'Model AI error: ' . $errorMessage . ' Harap coba lagi.'], 502);
}

try {
    if (!is_array($result['data'] ?? null)) {
        throw new RuntimeException('Tahap pertama tidak mengembalikan JSON inti.');
    }
    $draftJson = json_encode($result['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stageTwoPrompt = $userPrompt
        . "\nHASIL PENUNJANG FINAL: semua hasil laboratorium yang dicantumkan harus sudah final. Bila mencantumkan crossmatch, tulis hasil uji silang selesai dan kompatibel; jangan tulis sampel dikirim, in progress, pending, atau menunggu hasil."
        . "\nATURAN PRA-OPERASI: semua item emergency hanya stabilisasi IGD dan handoff ke Ruang Operasi; jangan menjahit atau melakukan tindakan definitif di IGD, termasuk pada kasus Minor. Catatan roleplay harus berisi urutan tindakan yang langsung dijalankan pemain.
KEY FINAL WAJIB: lab=array hasil laboratorium konkret; radiologi=array hasil radiologi konkret; emergency=array 8-14 OBJEK JSON (bukan string), setiap objek punya key pelaku, aksi, hasil, animasi. Jangan gabungkan pelaku, aksi, dan hasil dalam satu string. Teks aksi/hasil tanpa awalan /me atau /do. Contoh: {\"pelaku\":\"DPJP\",\"aksi\":\"memeriksa respons mata menggunakan senter pupil\",\"hasil\":\"Pasien membuka mata spontan; GCS E4 V5 M6 (15)\",\"animasi\":\"clipboard\"}. Key lab dan radiologi tidak boleh kosong atau diganti nama."
        . "\n\nTAHAP 2/2 Ã¢â‚¬â€ LANJUTKAN JSON INTI BERIKUT MENJADI LAPORAN FINAL LENGKAP."
        . " Pertahankan fakta dan keputusan klinis dari JSON inti, lalu isi seluruh kartu yang belum ada: laboratorium, radiologi, Penanganan Emergency ABCDE 8Ã¢â‚¬â€œ14 langkah, handoff, rujukan SOP, roleplay note, dan seluruh field schema."
        . " Audit internal sebelum output: anamnesis lengkap; tiga diagnosis banding berbeda yang didukung; GCS E/V/M dengan total; tepat lima TTV; pemeriksaan penunjang relevan dengan hasil final spesifik; satu teknik anestesi tanpa alternatif; klasifikasi operasi sesuai cedera; 8-14 tindakan IGD playable tanpa prosedur definitif. Semua angka dan temuan adalah fakta final di dalam skenario roleplay, bukan estimasi; jangan tulis 'Estimasi AI — wajib verifikasi', 'data belum tersedia', 'belum tersedia', atau 'menunggu hasil'. Dua tindakan emergency pertama wajib (1) pemeriksaan GCS dengan metode disebut dan /do E/V/M serta total persis sama dengan field gcs; (2) pengukuran TTV dengan alat disebut dan /do kelima nilai persis sama dengan field ttv. Pada perdarahan/luka kepala atau dugaan cedera intrakranial, rekomendasi radiologi terstruktur harus CT Scan > Kepala & Otak > CT Kepala Non-Kontras > Axial; jangan memilih X-Ray Chest hanya karena template atau ada kata GCS. Untuk fraktur ekstremitas yang memakai X-Ray, pilih opsi proyeksi gabungan dua/tiga view yang tercantum persis di katalog (contoh Forearm > AP & Lateral), dan tulis proyeksi yang sama pada rekomendasi teks. Field status operasi wajib menyatakan pasien dipindahkan ke Ruang Operasi setelah stabilisasi awal, tanpa mensyaratkan pemeriksaan/evaluasi laboratorium atau radiologi sebagai prasyarat transfer. Pemeriksaan yang wajib tetap dilakukan bila relevan, tetapi tidak menahan perpindahan pra-operasi. Jika status operasi cito menyatakan tanpa menunggu CT karena tanda herniasi akut, handoff tetap menyebut CT kepala sebagai wajib tetapi tidak boleh memerintahkan menunggu hasilnya atau menyatakan hasil CT sudah ada; arahkan transfer langsung ke Ruang Operasi dan CT segera bila tidak menunda tindakan. Jangan jadikan komunikasi/laporan lisan sebagai aksi emergency; ringkasan di field handoff terpisah dan tindakan terakhir emergency wajib berupa pemindahan fisik aktual ke Ruang Operasi (aksi: memindahkan/mengantar/mendorong/membawa pasien sampai tiba). Jangan mengulang anamnesis mentah, jangan membuat pilihan tindakan, jangan mengubah jenis kelamin, dan jangan menulis penjelasan di luar satu JSON final."
        . "\nJSON INTI TAHAP 1:\n" . $draftJson;
    $stageTwoPrompt .= "\n\nATURAN ALAT PLAYABLE: setiap aksi emergency /me menyebut alat, instrumen, atau bahan spesifik yang dipakai. Bila pelaku asisten, tulis instruksi 'DPJP: ambil/pasang [nama alat]' dan balasan asisten sebelum aksi pengambilan/penyerahan. Perdarahan yang digenangi darah dibersihkan dengan mesin suction bedah dan kateter suction steril; luka ditekan dengan kasa steril dan perban yang disebut; irigasi menyebut spuit irigasi serta NaCl 0,9%; intubasi menyebut laringoskop dan ETT; transfer menyebut brankar. Jangan hanya menulis tindakan tanpa alat.";
    $finalResult = ems_ai_ds_call_diagnosis_with_retry($pdo, $systemPrompt, $stageTwoPrompt, 'ai_diagnosis_assistant_stage_2', isset($user['id']) ? (int) $user['id'] : null);
    if (!$finalResult['ok']) {
        throw new RuntimeException('Tahap kedua gagal: ' . (string) ($finalResult['error'] ?? 'respons tidak valid'));
    }
    $data = ems_ai_ds_align_model_schema(ems_ai_ds_merge_stage_reports($result['data'], $finalResult['data']));
    ems_ai_ds_ensure_igd_radiology($data, $anamnesis);
    $data = ems_ai_ds_reconcile_transfer_status($data);
    $data['emergency'] = ems_ai_ds_normalize_roleplay_cards(
        is_array($data['emergency'] ?? null) ? $data['emergency'] : []
    );
    if (ems_ai_ds_instrument_action_issues(
        is_array($data['emergency'] ?? null) ? $data['emergency'] : [],
        'Emergency IGD',
        'GCS ' . (string) ($data['gcs'] ?? '') . ' ' . (string) ($data['anamnesis_lengkap'] ?? '') . ' ' . (string) ($data['diagnosis_utama'] ?? '') . ' ' . (string) ($data['kasus_tindakan'] ?? '')
    ) !== []) {
        $instrumentRepair = ems_ai_ds_repair_emergency_instruments($pdo, $data, (int) ($user['id'] ?? 0));
        if (!empty($instrumentRepair['ok']) && is_array($instrumentRepair['data'] ?? null)) {
            $data = $instrumentRepair['data'];
        }
    }
    // Run the deterministic normalization after model repair as well as before
    // it; the model may still omit tool wording in its second response.
    $data['emergency'] = ems_ai_ds_normalize_roleplay_cards(
        is_array($data['emergency'] ?? null) ? $data['emergency'] : []
    );
    try {
        $data = ems_ai_ds_require_complete_model_report($data);
    } catch (Throwable $qualityError) {
        // Stage 3 is conditional repair; valid reports normally finish in two calls.
        $auditJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $operationLabel = trim((string) ($data['jenis_operasi'] ?? 'tindakan definitif')) ?: 'tindakan definitif';
        $transferStatusExample = 'Setelah stabilisasi awal, pasien dipindahkan ke Ruang Operasi untuk ' . $operationLabel . '. Pemeriksaan penunjang tidak menjadi prasyarat pemindahan.';
        $gcsExpected = trim((string) ($data['gcs'] ?? ''));
        $expectedVitals = [];
        foreach (is_array($data['ttv'] ?? null) ? $data['ttv'] : [] as $vital) {
            if (is_array($vital)) {
                $expectedVitals[] = trim((string) ($vital['label'] ?? 'TTV')) . ': ' . trim((string) ($vital['value'] ?? $vital['nilai'] ?? ''));
            }
        }
        $gcsAndVitalsRepairRule = "\nATURAN ANGKA TETAP: tindakan emergency[0].hasil wajib memuat persis skor GCS final {$gcsExpected}; tindakan emergency[1].hasil wajib memuat semua angka berikut tanpa mengubahnya: " . implode('; ', $expectedVitals) . '. Jangan mengubah nilai pada field gcs/ttv untuk menyesuaikan kalimat tindakan. FORMAT: emergency harus berupa array 8-14 OBJEK JSON, setiap objek memisahkan pelaku, aksi, hasil, animasi; jangan pernah menggabungkan data sebagai string. Field aksi/hasil berisi teks saja tanpa awalan /me atau /do. Jika respons sebelumnya memakai string gabungan, pecah teks yang sama menjadi field-field objek tanpa mengganti maknanya.';
        $stageThreePrompt = $userPrompt
            . "\n\nTAHAP PERBAIKAN KONDISIONAL. Laporan gagal pada pemeriksaan kualitas ini: " . $qualityError->getMessage() . ". Pertahankan fakta dan keputusan yang benar; perbaiki hanya bagian yang gagal. Semua angka/temuan harus menjadi fakta final skenario roleplay, hapus label estimasi, data kosong, dan menunggu hasil. Jika validator menyebut status operasi menahan transfer untuk hasil pemeriksaan, ganti seluruh status_rencana_operasi dengan kalimat ini dan jangan menambahkan syarat lain: \"" . $transferStatusExample . "\" Field status operasi harus secara eksplisit menyatakan pasien dipindahkan ke Ruang Operasi setelah stabilisasi awal; jangan mensyaratkan evaluasi/radiologi/laboratorium selesai untuk transfer. Pastikan dua tindakan emergency pertama memeriksa GCS lalu mengukur TTV, menyebut metode/alat, dan /do menampilkan skor GCS dalam format E# V# M# (total) serta angka kelima nilai TTV yang sama dengan field final (satuan boleh ditulis dengan variasi umum). Hapus aksi emergency yang hanya komunikasi, letakkan ringkasan pada field handoff, dan jadikan tindakan terakhir emergency sebagai pemindahan fisik aktual pasien sampai tiba di Ruang Operasi. Bila validator menyebut suatu langkah hanya komunikasi atau item terakhir bukan transfer fisik, ubah item yang gagal dan langkah terakhir secara eksplisit: aksi terakhir harus berbunyi sebagai tindakan langsung memindahkan pasien dengan brankar dari IGD sampai masuk ke Ruang Operasi; hasilnya menyatakan pasien telah tiba di Ruang Operasi dengan monitoring terpasang. Jangan sekadar menulis \"handoff\", \"melaporkan kondisi\", atau \"menyampaikan ringkasan\" sebagai tindakan itu. Untuk fraktur ekstremitas pada X-Ray, pilih dan tulis proyeksi gabungan lengkap yang valid pada katalog (Forearm AP & Lateral, Wrist PA & Lateral, Hand/Foot AP, Oblique & Lateral). Pastikan hasil pemeriksaan relevan, satu teknik anestesi, 8-14 tindakan pra-operasi, serta handoff ke Ruang Operasi. Untuk frasa validator terkait tindakan, hapus \"sesuai protokol\", \"sesuai instruksi\", atau \"sesuai arahan\" dari aksi dan gantikan dengan satu aksi fisik yang langsung dilakukan serta hasilnya. Jangan menambah cedera tanpa dukungan dan kembalikan satu JSON lengkap." . $gcsAndVitalsRepairRule . "\nLAPORAN UNTUK DIPERBAIKI:\n" . $auditJson;
        $repairError = $qualityError;
        for ($repairAttempt = 1; $repairAttempt <= 3; $repairAttempt++) {
            $repairFeature = 'ai_diagnosis_assistant_stage_' . ($repairAttempt + 2);
            $repairPrompt = $stageThreePrompt;
            if ($repairAttempt >= 2) {
                $currentJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $repairPrompt = $userPrompt
                    . "\n\nPERBAIKAN TERARAH TERAKHIR. Quality gate masih gagal: " . $repairError->getMessage()
                    . ". Perbaiki hanya kegagalan tersebut; pertahankan semua fakta kasus yang sudah benar. Jika status operasi menahan transfer untuk hasil pemeriksaan, ganti status_rencana_operasi tanpa syarat dengan: \"" . $transferStatusExample . "\". Jika langkah terakhir gagal validasi karena hanya berupa komunikasi, ganti dengan aksi fisik: memindahkan pasien menggunakan brankar dari IGD sampai masuk ke Ruang Operasi; /do menyatakan pasien telah tiba dengan monitoring terpasang. Hapus komunikasi itu dari emergency (catatan komunikasi tetap hanya pada handoff)." . $gcsAndVitalsRepairRule . " Kembalikan satu JSON laporan lengkap, bukan penjelasan.\nLAPORAN TERKINI:\n" . $currentJson;
            }
            $auditResult = ems_ai_ds_call_diagnosis_with_retry($pdo, $systemPrompt, $repairPrompt, $repairFeature, isset($user['id']) ? (int) $user['id'] : null);
            if (empty($auditResult['ok'])) {
                throw new RuntimeException('Perbaikan laporan gagal: ' . (string) ($auditResult['error'] ?? 'respons tidak valid'));
            }
            $data = ems_ai_ds_align_model_schema(ems_ai_ds_merge_stage_reports($data, $auditResult['data']));
            ems_ai_ds_ensure_igd_radiology($data, $anamnesis);
            $data = ems_ai_ds_reconcile_transfer_status($data);
            $data['emergency'] = ems_ai_ds_normalize_roleplay_cards(
                is_array($data['emergency'] ?? null) ? $data['emergency'] : []
            );
            try {
                $data = ems_ai_ds_require_complete_model_report($data);
                break;
            } catch (Throwable $nextQualityError) {
                $repairError = $nextQualityError;
                if ($repairAttempt >= 3) {
                    throw $nextQualityError;
                }
            }
        }
    }
} catch (Throwable $finalError) {
    // Dua tahap normal, dengan tahap perbaikan hanya jika quality gate gagal.
    $errorMessage = 'Laporan final tidak memenuhi kontrak: ' . $finalError->getMessage();
    ems_ai_ds_store_failure($pdo, $user, $effectiveUnit, $division, $anamnesis, $patientName, $patientGender, $patientDob, $patientCitizenId, $errorMessage);
    ems_ai_ds_json_response(['ok' => false, 'message' => $errorMessage . ' Silakan ulangi analisis.'], 422);
}

$reportCode = null;
for ($codeAttempt = 0; $codeAttempt < 5; $codeAttempt++) {
    $candidate = ems_ai_ds_generate_report_code();
    $dupCheck = $pdo->prepare('SELECT 1 FROM ai_diagnosis_reports WHERE report_code = ?');
    $dupCheck->execute([$candidate]);
    if (!$dupCheck->fetchColumn()) {
        $reportCode = $candidate;
        break;
    }
}

$insert = $pdo->prepare("
    INSERT INTO ai_diagnosis_reports
        (report_code, user_id, unit_code, division_snapshot, anamnesis, patient_name, patient_gender, patient_dob, patient_citizen_id, result_json, status, error_message)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'done', NULL)
");
$insert->execute([
    $reportCode,
    isset($user['id']) ? (int) $user['id'] : 0,
    $effectiveUnit,
    $division,
    $anamnesis,
    $patientName !== '' ? $patientName : null,
    $patientGender,
    $patientDob,
    $patientCitizenId !== '' ? $patientCitizenId : null,
    json_encode($data, JSON_UNESCAPED_UNICODE),
]);

$reportId = (int) $pdo->lastInsertId();

ems_ai_ds_json_response(['ok' => true, 'message' => 'Diagnosis berhasil dibuat.', 'report_id' => $reportId]);
