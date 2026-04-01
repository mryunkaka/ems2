<?php
date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/surat_code_helper.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $type = strtolower(trim((string)($_GET['type'] ?? $_POST['type'] ?? '')));
    $institutionName = trim((string)($_GET['institution_name'] ?? $_POST['institution_name'] ?? ''));
    $counterpartyName = trim((string)($_GET['counterparty_name'] ?? $_POST['counterparty_name'] ?? ''));
    $dateValue = trim((string)($_GET['date'] ?? $_POST['date'] ?? ''));
    $incomingLetterId = (int)($_GET['incoming_letter_id'] ?? $_POST['incoming_letter_id'] ?? 0);
    $outgoingLetterId = (int)($_GET['outgoing_letter_id'] ?? $_POST['outgoing_letter_id'] ?? 0);
    $letterDirection = strtolower(trim((string)($_GET['letter_direction'] ?? $_POST['letter_direction'] ?? 'incoming')));

    if ($type === '') {
        throw new Exception('Tipe surat wajib diisi.');
    }

    if ($type === 'incoming') {
        if ($institutionName === '') {
            throw new Exception('Nama instansi wajib diisi.');
        }

        $date = new DateTimeImmutable('now');
        $code = surat_generate_formatted_code(
            $pdo,
            'incoming_letters',
            'letter_code',
            'submitted_at',
            'SM',
            $date->format('Y-m-d H:i:s'),
            $institutionName,
            'SR'
        );
    } elseif ($type === 'outgoing') {
        if ($institutionName === '') {
            throw new Exception('Nama instansi wajib diisi.');
        }

        $date = new DateTimeImmutable('now');
        $code = surat_generate_formatted_code(
            $pdo,
            'outgoing_letters',
            'outgoing_code',
            'created_at',
            'SK',
            $date->format('Y-m-d H:i:s'),
            $institutionName,
            'SR'
        );
    } elseif ($type === 'minutes') {
        if ($dateValue === '') {
            throw new Exception('Tanggal notulen wajib diisi.');
        }

        $code = surat_generate_formatted_code(
            $pdo,
            'meeting_minutes',
            'minutes_code',
            'meeting_date',
            'NOT',
            $dateValue,
            surat_resolve_minutes_institution($pdo, $incomingLetterId, $outgoingLetterId),
            'SR'
        );
    } elseif ($type === 'visit_agenda') {
        if ($dateValue === '') {
            throw new Exception('Tanggal agenda wajib diisi.');
        }

        $code = surat_generate_formatted_code(
            $pdo,
            'secretary_visit_agendas',
            'agenda_code',
            'visit_date',
            'AGD',
            $dateValue,
            $institutionName,
            'SR'
        );
    } elseif ($type === 'internal_coordination') {
        if ($dateValue === '') {
            throw new Exception('Tanggal koordinasi wajib diisi.');
        }

        $code = surat_generate_formatted_code(
            $pdo,
            'secretary_internal_coordinations',
            'coordination_code',
            'coordination_date',
            'KOR',
            $dateValue,
            $institutionName,
            'SR'
        );
    } elseif ($type === 'confidential') {
        if ($dateValue === '') {
            throw new Exception('Tanggal surat wajib diisi.');
        }

        if ($counterpartyName === '') {
            throw new Exception('Pengirim / penerima utama wajib diisi.');
        }

        if (!in_array($letterDirection, ['incoming', 'outgoing'], true)) {
            throw new Exception('Arah surat tidak valid.');
        }

        $code = surat_generate_formatted_code(
            $pdo,
            'secretary_confidential_letters',
            'register_code',
            'letter_date',
            $letterDirection === 'outgoing' ? 'SKR' : 'SMR',
            $dateValue,
            $counterpartyName,
            'SR'
        );
    } elseif ($type === 'secretary_file') {
        $fileCategory = strtolower(trim((string)($_GET['file_category'] ?? $_POST['file_category'] ?? 'other')));

        if ($dateValue === '') {
            throw new Exception('Tanggal file wajib diisi.');
        }

        if ($counterpartyName === '') {
            throw new Exception('Pihak terkait wajib diisi.');
        }

        if (!in_array($fileCategory, ['proposal', 'cooperation', 'contract', 'report', 'other'], true)) {
            throw new Exception('Jenis file tidak valid.');
        }

        $code = surat_generate_formatted_code(
            $pdo,
            'secretary_file_records',
            'file_code',
            'document_date',
            surat_secretary_file_type_prefix($fileCategory),
            $dateValue,
            $counterpartyName,
            'SR'
        );
    } else {
        throw new Exception('Tipe surat tidak dikenali.');
    }

    echo json_encode([
        'success' => true,
        'code' => $code,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
