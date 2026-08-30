<?php

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai_diagnosis_surgery.php';

/**
 * Groq — provider "tingkat 1" (model gratis, open-weight) untuk chat bot
 * internal Roxy, lihat docs/AI_ASSISTANT_MODULE.md §3. PER-USER, bukan
 * global — dicek langsung lewat header rate-limit API sungguhan
 * (2026-08-30): tier gratis Groq cuma 1.000 request/hari + 8.000
 * token/menit PER AKUN. Dengan 154 staff aktif (119 divisi Medis) dan
 * desain Roxy yang mengirim ulang seluruh riwayat percakapan tiap
 * balasan, satu key global dibagi rata semua orang jelas tidak cukup —
 * satu obrolan panjang saja bisa menghabiskan jatah harian untuk semua
 * orang. Jadi kolom `groq_api_key`/`groq_default_model` ditambahkan ke
 * `user_ai_settings` (tabel yang sama dipakai Gemini pribadi), BUKAN
 * tabel baru — lihat ems_ai_ds_ensure_tables() di
 * config/ai_diagnosis_surgery.php untuk guard kolomnya.
 */

function ems_groq_get_user_settings(PDO $pdo, int $userId): ?array
{
    return ems_ai_ds_get_user_settings($pdo, $userId);
}

function ems_groq_save_user_settings(PDO $pdo, int $userId, string $apiKey, string $model): void
{
    // INSERT dengan default kosong untuk kolom Gemini kalau user ini belum
    // pernah setting apa pun sama sekali (baris user_ai_settings-nya belum
    // ada) — ON DUPLICATE KEY UPDATE hanya menyentuh kolom Groq, jadi tidak
    // akan pernah menimpa Gemini key yang mungkin sudah tersimpan user ini.
    $stmt = $pdo->prepare("
        INSERT INTO user_ai_settings (user_id, gemini_api_key, gemini_base_url, default_model, groq_api_key, groq_default_model)
        VALUES (?, '', 'https://generativelanguage.googleapis.com/v1beta', 'gemini-3.5-flash-lite', ?, ?)
        ON DUPLICATE KEY UPDATE
            groq_api_key = VALUES(groq_api_key),
            groq_default_model = VALUES(groq_default_model),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$userId, $apiKey, $model]);
}

// Model open-weight untuk chat di tier gratis Groq — diverifikasi
// langsung lewat GET /openai/v1/models pakai API key real (2026-08-30),
// BUKAN ditebak dari nama model yang pernah populer (Llama 3.3/3.1,
// Gemma2 sama sekali sudah tidak ada lagi di katalog Groq saat ini —
// katalognya berubah-ubah, sama seperti kasus Gemini 2.5 yang dulu
// ternyata sudah deprecated, lihat CLAUDE.md §10.6b). Model non-chat
// (whisper*/orpheus* = speech, llama-prompt-guard* = classifier kecil)
// dan groq/compound* (agentic, punya tool-use/browsing bawaan yang
// bertentangan dengan guardrail "tidak ada tool-calling" di PRD) sengaja
// tidak dimasukkan di sini.
function ems_groq_model_options(): array
{
    return [
        'openai/gpt-oss-120b' => 'GPT-OSS 120B (OpenAI, open-weight) — direkomendasikan, paling pintar, context 131k',
        'openai/gpt-oss-20b' => 'GPT-OSS 20B (OpenAI, open-weight) — lebih cepat/ringan',
        'qwen/qwen3.8-27b' => 'Qwen3.8 27B (Alibaba, open-weight) — alternatif',
    ];
}

function ems_groq_mask_key(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (strlen($value) <= 8) {
        return str_repeat('*', strlen($value));
    }

    return substr($value, 0, 4) . str_repeat('*', max(4, strlen($value) - 8)) . substr($value, -4);
}
