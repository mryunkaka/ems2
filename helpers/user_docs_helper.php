<?php

/**
 * Dokumen tambahan disimpan di kolom `user_rh.dokumen_lainnya`.
 * Format baru: JSON array of objects: [{id, name, path}]
 * Format lama: string path (single file) -> akan dinormalisasi saat dipakai.
 */

function parseAcademyDocs(?string $raw): array
{
    $raw = trim((string)$raw);
    if ($raw === '') return [];

    // Coba parse JSON
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        if (is_array($decoded)) {
            $items = $decoded;
            // Support format: {"items":[...]}
            if (array_key_exists('items', $decoded) && is_array($decoded['items'])) {
                $items = $decoded['items'];
            }

            $out = [];
            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $id = trim((string)($it['id'] ?? ''));
                $name = trim((string)($it['name'] ?? ''));
                $path = trim((string)($it['path'] ?? ''));
                if ($path === '') continue;
                $out[] = [
                    'id' => $id !== '' ? $id : null,
                    'name' => $name !== '' ? $name : 'File Lainnya',
                    'path' => $path,
                ];
            }
            return $out;
        }
    }

    // Legacy: path string tunggal
    return [[
        'id' => null,
        'name' => 'File Lainnya',
        'path' => $raw,
    ]];
}

function academyDocIdFromPath(string $path): string
{
    $path = trim($path);
    return 'legacy_' . substr(sha1($path), 0, 12);
}

function ensureAcademyDocIds(array $docs): array
{
    $out = [];
    foreach ($docs as $d) {
        if (!is_array($d)) continue;
        $path = trim((string)($d['path'] ?? ''));
        if ($path === '') continue;
        $id = trim((string)($d['id'] ?? ''));
        if ($id === '') $id = academyDocIdFromPath($path);
        $out[] = [
            'id' => $id,
            'name' => (string)($d['name'] ?? 'File Lainnya'),
            'path' => $path,
        ];
    }
    return $out;
}

function sanitizeAcademyDocName(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name));
    $name = str_replace(["\r", "\n", "\t"], ' ', $name);
    return $name;
}
