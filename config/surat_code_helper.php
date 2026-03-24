<?php

if (!function_exists('surat_month_to_roman')) {
    function surat_month_to_roman(int $month): string
    {
        $map = [
            1 => 'I',
            2 => 'II',
            3 => 'III',
            4 => 'IV',
            5 => 'V',
            6 => 'VI',
            7 => 'VII',
            8 => 'VIII',
            9 => 'IX',
            10 => 'X',
            11 => 'XI',
            12 => 'XII',
        ];

        return $map[$month] ?? 'I';
    }
}

if (!function_exists('surat_institution_abbreviation')) {
    function surat_institution_abbreviation(?string $value, string $fallback = 'SR'): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return $fallback;
        }

        $normalized = strtoupper(preg_replace('/[^A-Z0-9]+/i', ' ', $value) ?? '');
        $parts = preg_split('/\s+/', trim($normalized)) ?: [];
        $stopwords = ['AND', 'OF', 'THE', 'FOR', 'TO', 'DAN', 'DI', 'KE', 'DE', 'LA'];
        $filtered = array_values(array_filter($parts, static function ($part) use ($stopwords) {
            return $part !== '' && !in_array($part, $stopwords, true);
        }));

        if (count($filtered) >= 2) {
            $abbr = '';
            foreach (array_slice($filtered, 0, 6) as $part) {
                $abbr .= substr($part, 0, 1);
            }

            return substr($abbr, 0, 12) ?: $fallback;
        }

        $single = $filtered[0] ?? ($parts[0] ?? '');
        $single = preg_replace('/[^A-Z0-9]/', '', $single) ?? '';

        if ($single === '') {
            return $fallback;
        }

        return substr($single, 0, 3);
    }
}

if (!function_exists('surat_generate_sequence')) {
    function surat_generate_sequence(PDO $pdo, string $table, string $codeColumn, string $dateColumn, DateTimeImmutable $date): int
    {
        $stmt = $pdo->prepare("
            SELECT {$codeColumn}
            FROM {$table}
            WHERE YEAR({$dateColumn}) = ?
              AND MONTH({$dateColumn}) = ?
            ORDER BY id ASC
        ");
        $stmt->execute([
            (int)$date->format('Y'),
            (int)$date->format('n'),
        ]);

        $rowCount = 0;
        $maxSequence = 0;

        while ($code = $stmt->fetchColumn()) {
            $rowCount++;
            if (preg_match('/^(\d{3,})\//', (string)$code, $matches)) {
                $maxSequence = max($maxSequence, (int)$matches[1]);
            }
        }

        return max($rowCount, $maxSequence) + 1;
    }
}

if (!function_exists('surat_generate_formatted_code')) {
    function surat_generate_formatted_code(
        PDO $pdo,
        string $table,
        string $codeColumn,
        string $dateColumn,
        string $letterType,
        string $dateValue,
        ?string $institutionName,
        string $fallbackInstitution = 'SR'
    ): string {
        $date = new DateTimeImmutable($dateValue);
        $sequence = surat_generate_sequence($pdo, $table, $codeColumn, $dateColumn, $date);
        $institutionCode = surat_institution_abbreviation($institutionName, $fallbackInstitution);

        return sprintf(
            '%03d/%s-%s/RH/%s/%s',
            $sequence,
            $letterType,
            $institutionCode,
            surat_month_to_roman((int)$date->format('n')),
            $date->format('Y')
        );
    }
}

if (!function_exists('surat_resolve_minutes_institution')) {
    function surat_resolve_minutes_institution(PDO $pdo, int $incomingLetterId, int $outgoingLetterId): string
    {
        if ($outgoingLetterId > 0) {
            $stmt = $pdo->prepare("
                SELECT institution_name
                FROM outgoing_letters
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$outgoingLetterId]);
            $value = $stmt->fetchColumn();
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        if ($incomingLetterId > 0) {
            $stmt = $pdo->prepare("
                SELECT institution_name
                FROM incoming_letters
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->execute([$incomingLetterId]);
            $value = $stmt->fetchColumn();
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return 'SR';
    }
}

if (!function_exists('surat_normalize_code')) {
    function surat_normalize_code(?string $code): string
    {
        $code = strtoupper(trim((string)$code));
        return preg_replace('/\s+/', '', $code) ?? '';
    }
}

if (!function_exists('surat_assert_code_unique')) {
    function surat_assert_code_unique(PDO $pdo, string $table, string $column, string $code, int $excludeId = 0): void
    {
        $sql = "SELECT id FROM {$table} WHERE {$column} = ?";
        $params = [$code];

        if ($excludeId > 0) {
            $sql .= " AND id <> ?";
            $params[] = $excludeId;
        }

        $sql .= " LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->fetchColumn()) {
            throw new Exception('Nomor surat sudah digunakan. Gunakan nomor lain.');
        }
    }
}

if (!function_exists('surat_resolve_requested_code')) {
    function surat_resolve_requested_code(
        PDO $pdo,
        string $table,
        string $column,
        ?string $requestedCode,
        callable $generator,
        int $excludeId = 0
    ): string {
        $code = surat_normalize_code($requestedCode);
        if ($code === '') {
            $code = surat_normalize_code((string)$generator());
        }

        surat_assert_code_unique($pdo, $table, $column, $code, $excludeId);

        return $code;
    }
}
