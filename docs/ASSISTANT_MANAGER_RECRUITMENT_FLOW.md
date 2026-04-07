# Assistant Manager Recruitment Flow

Dokumen ini merangkum alur dan struktur implementasi jalur rekrutmen `Calon Asisten Manager` tanpa membuat tabel baru.

## Tujuan

- memisahkan data jalur `Calon Kandidat` medis dan `Calon Asisten Manager`
- tetap memakai tabel rekrutmen yang sudah ada
- menjaga alur public form -> assessment -> interview -> final decision tetap sama
- menghapus kebutuhan pilih batch dan jabatan pada final decision jalur manager
- menambah pilihan role final `Probation Manager` dan `Assisten Manager`

## Jalur Halaman

### Public

- `/public/recruitment_form.php`
  - jalur kandidat medis biasa
  - menyimpan `recruitment_type = medical_candidate`
- `/public/recruitment_form_assistant_manager.php`
  - jalur baru calon asisten manager
  - fokus narasi General Affair
  - menyimpan `recruitment_type = assistant_manager`
  - default `target_division = General Affair`
- `/public/recruitment_submit.php`
  - tetap satu endpoint
  - membedakan jalur berdasarkan `recruitment_type`
- `/public/ai_test.php`
  - tetap satu endpoint
  - membaca tipe pelamar lalu memuat bank soal sesuai jalur
  - medis: 50 soal
  - asisten manager: 70 soal acak dari bank 500 soal
- `/public/ai_test_submit.php`
  - tetap satu endpoint
  - scoring mengikuti profil rekrutmen pelamar

### Dashboard

- `/dashboard/candidates.php`
  - khusus `medical_candidate`
- `/dashboard/assistant_manager_candidates.php`
  - halaman baru khusus `assistant_manager`
  - masuk sidebar seperti halaman lain
- `/dashboard/candidate_detail.php`
  - tetap satu halaman
  - pertanyaan dan label menyesuaikan `recruitment_type`
- `/dashboard/candidate_interview_multi.php`
  - tetap satu halaman
  - daftar pertanyaan interview dan kriteria interview menyesuaikan `recruitment_type`
- `/dashboard/candidate_decision.php`
  - tetap satu halaman
  - medis: pilih `position` + `batch`
  - asisten manager: pilih `role` + `division`

## Struktur Data

### Tabel Existing yang Dipakai

- `medical_applicants`
- `ai_test_results`
- `applicant_documents`
- `applicant_interview_scores`
- `applicant_interview_results`
- `applicant_final_decisions`
- `interview_criteria`
- `user_rh`

### Kolom Baru di Tabel Existing

#### `medical_applicants`

- `recruitment_type`
  - `medical_candidate`
  - `assistant_manager`
- `target_role`
- `target_division`

#### `interview_criteria`

- `recruitment_type`
  - `all`
  - `medical_candidate`
  - `assistant_manager`

#### `applicant_final_decisions`

- `recommended_role`
- `recommended_division`
- `recommended_position`
- `recommended_batch`

#### `user_rh`

- enum `role` ditambah:
  - `Probation Manager`

## Assessment Asisten Manager

- total bank 500 soal
- tiap pelamar menjawab 70 soal acak
- tetap format `Ya / Tidak`
- fokus pada:
  - kepatuhan SOP
  - koordinasi General Affair
  - kontrol operasional
  - dokumentasi dan tindak lanjut
  - konsistensi jawaban
- beberapa soal dibuat mirip makna namun berbeda redaksi untuk membantu membaca konsistensi kandidat
- terdapat pertanyaan jebakan dengan kriteria masing-masing untuk membaca red flag

## Interview Asisten Manager

Kriteria interview tetap memakai tabel `interview_criteria`, tetapi sekarang dapat di-scope per jalur rekrutmen.

Kriteria tambahan untuk `assistant_manager`:

- `sop_compliance`
- `ga_coordination`
- `operational_control`

## Final Decision

### Medis

- tetap seperti alur lama
- jika lolos:
  - buat user `role = Staff`
  - `division = Medis`
  - `position` dipilih saat final decision
  - `batch` dipilih saat final decision

### Asisten Manager

- tidak ada pilih batch
- tidak ada pilih jabatan/position
- jika lolos:
  - pilih `role`:
    - `Probation Manager`
    - `Assisten Manager`
  - pilih `division`
  - buat user manager baru langsung ke `user_rh`
  - `position` dan `batch` dibiarkan `NULL`

## File yang Menjadi Pusat Konfigurasi

- `/config/recruitment_profiles.php`
  - normalisasi tipe rekrutmen
  - label jalur
  - bank soal assessment per jalur
- `/actions/ai_scoring_engine.php`
  - trait scoring per jalur
  - narasi hasil per jalur

## SQL

Migration yang dipakai untuk implementasi ini:

- [`19_2026-04-07_probation_manager_and_recruitment_tracks.sql`](/d:/Project/Web/ems2/docs/sql/19_2026-04-07_probation_manager_and_recruitment_tracks.sql)

Catatan:

- file SQL lama `18_2026-04-07_assistant_manager_recruitment.sql` membuat tabel baru
- implementasi ini tidak memakai pendekatan tersebut
- gunakan migration `19` untuk skema final yang sesuai kebutuhan saat ini
