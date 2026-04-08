# Gemini AI Recruitment Foundation Plan

Dokumen ini menjadi blueprint implementasi awal integrasi Gemini API untuk modul rekrutmen EMS, dengan fokus pada:

- halaman setting AI yang hanya bisa diakses oleh user bernama `Programmer Roxwood`
- penyimpanan API key dan konfigurasi model secara aman di server
- pondasi service backend agar ke depan tinggal dipanggil dari fitur rekrutmen
- pemisahan concern antara konfigurasi, prompt, request logging, dan use case

Dokumen ini sengaja dibuat lebih dulu sebelum implementasi penuh agar sisa limit model tidak habis untuk trial tanpa arah yang jelas.

## Tujuan

- menambahkan provider AI baru berbasis Gemini untuk kebutuhan internal EMS
- menjaga API key tetap berada di server, tidak pernah terekspos ke browser
- menyiapkan fondasi untuk:
  - generate ringkasan calon asisten manager
  - generate ringkasan calon medis
  - generate pertanyaan interview berdasarkan jawaban assessment
  - generate saran nilai per kriteria interview yang relevan
- memastikan akses setting AI sangat terbatas, hanya untuk `Programmer Roxwood`

## Keputusan Teknis

### Provider yang dipakai

- provider awal: `Gemini Developer API`
- mode integrasi: `REST API server-side`
- alasan:
  - codebase ini berbasis PHP murni
  - dokumentasi resmi Google saat ini merekomendasikan SDK resmi untuk beberapa bahasa, tetapi `PHP` tidak termasuk bahasa dengan SDK resmi Google GenAI
  - untuk bahasa seperti `PHP`, Google menyarankan penggunaan API langsung

### Model default yang disiapkan

- default utama: `gemini-2.5-flash`
- alternatif kualitas lebih tinggi: `gemini-2.5-pro`
- fallback hemat kuota: `gemini-2.5-flash-lite`

Alasan:

- `Flash` cocok untuk ringkasan dan draft interview karena cepat dan murah
- `Pro` disiapkan untuk analisis yang lebih berat jika nanti dibutuhkan
- `Flash-Lite` berguna saat limit harian ketat atau untuk request background volume tinggi

## Informasi Limit Resmi Gemini

Catatan: limit ini bersifat dinamis dan harus selalu dicek ulang di Google AI Studio sebelum produksi.

Temuan resmi yang relevan:

- rate limits berlaku `per project`, bukan per API key
- kuota harian `RPD` reset pada `midnight Pacific time`
- free tier tersedia dan berbeda per model

Contoh angka free tier yang ditemukan pada dokumentasi resmi Google saat dokumen ini dibuat:

- `Gemini 2.5 Pro`: `5 RPM`, `250,000 TPM`, `100 RPD`
- `Gemini 2.5 Flash`: `10 RPM`, `250,000 TPM`, `250 RPD`
- `Gemini 2.5 Flash-Lite`: `15 RPM`, `250,000 TPM`, `1,000 RPD`

Implikasi untuk EMS:

- untuk fitur rekrutmen internal, `gemini-2.5-flash` lebih realistis sebagai default
- `pro` sebaiknya hanya dipakai untuk action yang memang butuh reasoning lebih berat
- pengaturan model perlu bisa diganti dari halaman setting tanpa ubah kode

## Sumber Resmi

- API key Gemini:
  - https://ai.google.dev/gemini-api/docs/api-key
- Rate limits Gemini:
  - https://ai.google.dev/gemini-api/docs/rate-limits
- Pricing Gemini:
  - https://ai.google.dev/gemini-api/docs/pricing
- Integrasi library dan direct API:
  - https://ai.google.dev/gemini-api/docs/partner-integration

## Aturan Akses Halaman Setting AI

Halaman setting AI hanya boleh diakses oleh user dengan nama:

- `Programmer Roxwood`

Normalisasi akses yang disarankan:

- trim spasi
- lowercase
- kompres multiple spaces menjadi single space

Contoh guard:

- nama session user: `$_SESSION['user_rh']['full_name'] ?? $_SESSION['user_rh']['name'] ?? ''`
- allowed hanya jika hasil normalisasi sama dengan `programmer roxwood`

Catatan:

- akses berbasis nama mengikuti kebutuhan user saat ini
- ke depan lebih baik ditingkatkan menjadi permission atau role khusus seperti `system_admin_ai`

## Ruang Lingkup Halaman Setting AI

Halaman baru yang direncanakan:

- `dashboard/ai_settings.php`
- `dashboard/ai_settings_action.php`

Sidebar:

- menu hanya muncul untuk `Programmer Roxwood`

Fitur minimal halaman:

- status provider aktif
- pilihan provider default
- model default untuk rekrutmen
- API key Gemini
- base URL Gemini
- timeout request
- max output tokens
- temperature
- topP
- topK
- batas request harian internal aplikasi
- toggle enable ringkasan kandidat
- toggle enable generator pertanyaan interview
- toggle enable rekomendasi nilai criteria
- tombol test connection

## Penyimpanan Konfigurasi

Disarankan memakai tabel baru agar setting tidak hardcoded.

### Tabel utama

Nama usulan:

- `system_ai_settings`

Kolom usulan:

- `id`
- `provider` varchar(50)
- `is_enabled` tinyint(1)
- `gemini_api_key` text
- `gemini_base_url` varchar(255)
- `default_model` varchar(100)
- `summary_model` varchar(100)
- `interview_question_model` varchar(100)
- `criteria_scoring_model` varchar(100)
- `temperature` decimal(4,2)
- `top_p` decimal(4,2)
- `top_k` int
- `max_output_tokens` int
- `timeout_seconds` int
- `daily_request_limit` int
- `created_by` int null
- `updated_by` int null
- `created_at`
- `updated_at`

### Tabel log

Nama usulan:

- `system_ai_request_logs`

Kolom usulan:

- `id`
- `feature_key` varchar(100)
- `provider` varchar(50)
- `model_name` varchar(100)
- `request_hash` char(64)
- `request_payload` mediumtext
- `response_payload` mediumtext
- `prompt_tokens` int null
- `response_tokens` int null
- `total_tokens` int null
- `http_status` int null
- `latency_ms` int null
- `success_flag` tinyint(1)
- `error_message` text null
- `created_by` int null
- `created_at`

### Tabel prompt template

Nama usulan:

- `system_ai_prompt_templates`

Kolom usulan:

- `id`
- `feature_key` varchar(100)
- `title` varchar(150)
- `system_prompt` mediumtext
- `user_prompt_template` longtext
- `is_active` tinyint(1)
- `version_label` varchar(50)
- `created_by` int null
- `updated_by` int null
- `created_at`
- `updated_at`

## Struktur Kode yang Disarankan

### Config

- `config/ai_settings.php`
  - loader setting aktif dari database
  - helper decrypt/mask API key jika nanti dibutuhkan

### Actions / Service Layer

- `actions/ai_guard.php`
  - validasi akses `Programmer Roxwood`
- `actions/ai_gemini_client.php`
  - wrapper HTTP Gemini REST API
- `actions/ai_prompt_builder.php`
  - builder prompt per feature
- `actions/ai_recruitment_service.php`
  - service utama untuk use case rekrutmen
- `actions/ai_usage_limiter.php`
  - pembatas request internal berdasarkan log harian

### Dashboard

- `dashboard/ai_settings.php`
- `dashboard/ai_settings_action.php`

### Docs / SQL

- `docs/sql/20_2026-04-08_gemini_ai_foundation.sql`
- `docs/GEMINI_AI_RECRUITMENT_FOUNDATION_PLAN.md`

## Kontrak Service yang Disiapkan

Service utama yang nanti dipanggil modul rekrutmen:

### 1. Ringkasan kandidat

Method usulan:

- `generateCandidateSummary(int $applicantId, string $recruitmentType): array`

Output target:

- `summary_short`
- `summary_full`
- `strengths`
- `risks`
- `follow_up_points`

### 2. Pertanyaan interview

Method usulan:

- `generateInterviewQuestionPack(int $applicantId, string $recruitmentType): array`

Output target:

- `opening_questions`
- `behavioral_questions`
- `risk_probe_questions`
- `criteria_mapping`

### 3. Saran penilaian criteria

Method usulan:

- `generateCriteriaScoringGuide(int $applicantId, string $recruitmentType): array`

Output target:

- daftar criteria yang relevan
- indikator jawaban kuat
- indikator jawaban lemah
- rentang skor `1-5`
- catatan probing lanjutan

## Data Input yang Dipakai untuk AI

### Kandidat medis

Sumber data:

- `medical_applicants`
- `ai_test_results`
- `applicant_documents` jika perlu
- `applicant_interview_scores` hanya untuk fase lanjutan

Field penting:

- identitas dasar
- motivasi
- work principle
- komitmen aturan
- tanggung jawab lain
- jawaban assessment
- hasil scoring trait
- risk flags

### Kandidat assistant manager

Sumber data sama, tetapi prompt harus menekankan:

- SOP
- koordinasi lintas divisi
- kontrol operasional
- konsistensi
- integritas

## Prinsip Prompting

Prompt tidak boleh langsung meminta model membuat keputusan lolos atau tidak lolos tanpa konteks sistem. Model dipakai sebagai asisten HR, bukan pengambil keputusan final.

Aturan utama:

- output harus terstruktur JSON
- bahasa output default: Bahasa Indonesia
- model hanya memberi:
  - ringkasan
  - pertanyaan interview
  - saran area penilaian
- keputusan final tetap ada di engine internal dan evaluator manusia

## Desain Respons JSON

Semua fitur AI disarankan memakai respons JSON agar stabil.

Contoh ringkasan:

```json
{
  "summary_short": "Calon menunjukkan integritas tinggi dan motivasi yang jelas terhadap peran General Affair.",
  "summary_full": "Calon memiliki pola jawaban yang konsisten ...",
  "strengths": ["integritas", "koordinasi", "konsistensi"],
  "risks": ["perlu verifikasi pengalaman memimpin"],
  "follow_up_points": ["minta contoh konflik lintas tim", "cek kesiapan SOP saat tekanan tinggi"]
}
```

Contoh paket interview:

```json
{
  "opening_questions": ["Ceritakan alasan utama Anda mengambil jalur ini."],
  "behavioral_questions": ["Ceritakan situasi saat Anda harus menegakkan SOP yang tidak populer."],
  "risk_probe_questions": ["Apa yang Anda lakukan saat tim inti melanggar prosedur?"],
  "criteria_mapping": [
    {
      "criteria_code": "sop_compliance",
      "reason": "Jawaban assessment menunjukkan area ini penting untuk diverifikasi"
    }
  ]
}
```

## Keamanan

- API key tidak boleh disimpan di JavaScript atau HTML
- API call hanya dari server PHP
- halaman setting wajib guarded sebelum render
- log request harus bisa dimatikan untuk payload sensitif
- ketika API key ditampilkan di form, tampilkan versi masked
- simpan audit `created_by` dan `updated_by`

## Rencana Implementasi Bertahap

### Tahap 1

- migration tabel setting, prompt, dan log
- helper access guard untuk `Programmer Roxwood`
- halaman setting AI
- wrapper Gemini REST API
- koneksi test sederhana

### Tahap 2

- prompt template untuk:
  - summary assistant manager
  - summary medical candidate
  - interview question generator
  - criteria scoring guide
- service layer rekrutmen AI

### Tahap 3

- tombol generate dari halaman kandidat
- simpan hasil AI ke tabel cache/artefak
- log token dan latency

### Tahap 4

- retry policy
- daily limiter internal
- fallback model
- template versioning yang lebih kuat

## Rekomendasi Implementasi Gemini untuk Repo Ini

Karena stack sekarang adalah PHP:

- gunakan `REST API` langsung ke endpoint Gemini
- pakai `curl` atau wrapper HTTP sederhana di PHP
- jangan memakai client-side browser call
- jangan menambah dependency komunitas Gemini PHP sebagai pondasi utama, kecuali memang benar-benar dibutuhkan nanti

Endpoint awal yang akan dipakai:

- `POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent`

Header:

- `x-goog-api-key`
- `Content-Type: application/json`

## Keputusan Pondasi Saat Ini

Untuk implementasi berikutnya, standar awal yang dipakai:

- provider awal: `gemini`
- model default: `gemini-2.5-flash`
- akses setting: hanya `Programmer Roxwood`
- mode integrasi: `REST server-side`
- semua output fitur AI wajib berbentuk JSON terstruktur
- keputusan final rekrutmen tetap milik sistem internal dan HR

## Catatan Lanjutan

Saat melanjutkan implementasi, urutan kerja yang disarankan:

1. buat migration SQL
2. buat helper guard `Programmer Roxwood`
3. buat halaman setting AI
4. buat Gemini client REST
5. buat endpoint test connection
6. buat prompt templates
7. baru integrasikan ke halaman kandidat

