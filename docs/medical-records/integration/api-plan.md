# Rencana Integrasi Rekam Medis EMS2 ↔ Medical Center API

Status: **Rancangan dan audit saja — belum ada kode aplikasi yang diubah oleh pekerjaan ini.**

> **Keputusan final:** EMS2 hanya memakai satu endpoint **GET**. Input, edit, dan delete dilakukan di Medical Center. EMS2 tidak memakai POST, PUT, PATCH, DELETE, outbox, atau Idempotency-Key.

Tanggal audit: 2026-09-12

## Aturan waktu dan batas data — keputusan terbaru

Tanggal mulai integrasi ditetapkan:

```text
2026-09-21 00:00:00 Asia/Jakarta
```

Aturan wajib:

1. EMS2 hanya mengambil record remote melalui endpoint GET yang disediakan:
   `https://medicalcenterime.my.id/api/rekam-medis?hospital=roxwood`.
2. EMS2 memfilter record dengan waktu bisnis `tanggal_waktu >= 2026-09-21 00:00:00 Asia/Jakarta`.
3. Record remote sebelum tanggal tersebut tidak ditampilkan atau disimpan sebagai cache EMS2.
4. Data lama yang sudah ada di EMS2 tidak dikirim ke Medical Center API.
5. Tidak ada backfill historis.
6. EMS2 tidak memiliki alur input, edit, atau delete untuk record remote.
7. Record tanpa tanggal bisnis valid masuk `needs_review`, bukan diberi tanggal buatan.
8. Jika API tidak mendukung filter tanggal, aplikasi tetap wajib menyaring record lama setelah membaca response GET.
9. Cutoff memakai timezone `Asia/Jakarta`; semua perbandingan waktu harus dikonversi ke timezone ini.
10. Tanggal cutoff harus menjadi konfigurasi integrasi yang immutable untuk production, bukan input browser.

Definisi record lama:

```text
remote: tanggal_waktu < 2026-09-21 00:00:00 Asia/Jakarta
local:  event_at < 2026-09-21 00:00:00 Asia/Jakarta
        atau record sudah ada sebelum integrasi diaktifkan
```

## 1. Maksud

Target integrasi:

1. Medis input, edit, dan delete rekam medis hanya di Medical Center melalui `https://medicalcenterime.my.id/staff/dashboard`.
2. EMS2 hanya memanggil satu endpoint GET Medical Center untuk `hospital=roxwood`:
   `https://medicalcenterime.my.id/api/rekam-medis?hospital=roxwood`.
3. Data hasil GET ditampilkan read-only di `dashboard/rekam_medis_list.php`.
4. Data remote dapat disimpan sebagai cache/read model berdasarkan remote ID tanpa menjadi sumber input.
5. Kegagalan GET tidak menghapus cache terakhir; status stale/error dan notifikasi ditampilkan kepada admin.
6. Tidak ada pengiriman data lokal EMS2 ke Medical Center.
7. Tidak ada POST, PUT, PATCH, DELETE, outbox, atau Idempotency-Key.
8. Data forensic/private tetap terpisah dan tidak bocor ke tampilan umum.

Endpoint yang dipakai EMS2:

```http
GET https://medicalcenterime.my.id/api/rekam-medis?hospital=roxwood
```

Itu satu-satunya method dan endpoint integrasi EMS2. Tidak ada endpoint write dari EMS2.

Autentikasi API key harus disimpan di environment/server secret store, bukan di PHP, database biasa, dokumentasi, JavaScript, URL, atau log.

## 2. Audit kode EMS2 saat ini

EMS2 sudah memakai tabel utama:

```text
medical_records
```

Kolom yang sudah ada dan dapat dipertahankan:

| Kolom EMS2 | Fungsi sekarang | Rencana penggunaan |
|---|---|---|
| `id` | ID lokal | Tetap ID lokal; jangan disamakan dengan ID API |
| `record_code` | Nomor rekam medis lokal | Tetap sebagai nomor lokal |
| `patient_name` | Nama pasien | Isi dari `nama_pasien` remote |
| `patient_citizen_id` | Citizen ID | Isi dari `medical_details.pasien.citizen_id` |
| `patient_dob` | Tanggal lahir | Isi dari `medical_details.pasien.dob` jika ada |
| `patient_phone` | Nomor HP | Isi dari `medical_details.pasien.no_hp` jika ada |
| `patient_gender` | Jenis kelamin | Isi dari `medical_details.pasien.jenis_kelamin` |
| `jenis_operasi` | Nama/deskripsi operasi | Isi dari `jenis_operasi` remote |
| `operasi_type` | `major`/`minor` | Normalisasi dari `jenis_operasi` bila dapat dipastikan |
| `medical_result_html` | Hasil rich text lokal | Jangan menjadi satu-satunya penyimpanan payload remote |
| `doctor_id` | FK `user_rh` lokal | Jangan diisi berdasarkan nama remote tanpa mapping aman |
| `assistant_id` | Asisten utama lokal | Jangan diisi berdasarkan nama remote tanpa mapping aman |
| `created_by` | User EMS2 yang membuat | Untuk record lokal; record remote perlu actor/system khusus |
| `visibility_scope` | `standard`/`forensic_private` | Record remote default `standard`, kecuali ada aturan eksplisit |
| `created_at` / `updated_at` | Audit waktu lokal | Jangan ditimpa waktu remote |

Relasi yang sudah ada:

```text
medical_record_assistants
medical_record_supporting_images
```

Kesimpulan: jangan membuat tabel rekam medis kedua. Gunakan `medical_records` sebagai data aplikasi, lalu tambahkan identitas/source/sync secara terpisah.

## 3. Hasil audit response API

Response yang berhasil dibaca berbentuk:

```json
{
  "success": true,
  "data": [],
  "meta": {
    "current_page": 1,
    "last_page": 6,
    "per_page": 15,
    "total": 84
  }
}
```

Record remote memiliki field utama:

```text
id
tanggal_waktu
lokasi
jenis_operasi
hospital
nama_pasien
diagnosa
tindakan_operasi
hasil_operasi
catatan
medical_details
creator
dpjp
members
photos
poin
created_at
updated_at
```

Struktur nested yang terlihat:

```text
medical_details.pasien
medical_details.tim
medical_details.anamnesis
medical_details.obstetri
medical_details.ttv
medical_details.tindakan
medical_details.anestesi
medical_details.penunjang
medical_details.obat_obatan
medical_details.saran_anjuran
```

Catatan kualitas data yang harus ditangani:

- `dob` dapat `null`, sedangkan form EMS2 saat ini mewajibkan tanggal lahir.
- `jenis_kelamin` dan beberapa nama pasien dapat memiliki variasi penulisan.
- Nama anggota tim tidak selalu memiliki `staff_id`.
- `photos` memakai URL remote.
- `creator` dan `dpjp` memiliki ID remote, bukan otomatis ID `user_rh` EMS2.
- `poin.base` dan `poin.dpjp` belum punya kolom setara di `medical_records`.
- Detail medis nested dapat bertambah di masa depan.
- API memakai pagination; sinkronisasi tidak boleh hanya mengambil halaman pertama.

## 4. Usulan kolom ter-normalisasi

Kolom berikut layak ditambahkan ke `medical_records` jika cache/read model remote membutuhkan pencarian lokal. Perubahan schema belum dilakukan dan hanya untuk membaca/menampilkan data GET:

| Kolom usulan | Tipe usulan | Sumber | Alasan |
|---|---|---|---|
| `event_at` | `DATETIME NULL` | `tanggal_waktu` | Waktu kejadian/tindakan, berbeda dari waktu import |
| `location` | `VARCHAR(255) NULL` | `lokasi` | Pencarian dan filter lokasi |
| `source_hospital` | `VARCHAR(100) NULL` | `hospital` | Menjaga asal fasilitas |
| `diagnosis_text` | `TEXT NULL` | `diagnosa` | Pencarian/ringkasan diagnosis |
| `operation_procedure_text` | `TEXT NULL` | `tindakan_operasi` | Ringkasan tindakan |
| `operation_result_text` | `TEXT NULL` | `hasil_operasi` | Hasil tindakan plain text |
| `record_notes` | `TEXT NULL` | `catatan` | Catatan umum remote |
| `remote_creator_name` | `VARCHAR(255) NULL` | `creator.name` | Snapshot, bukan FK |
| `remote_creator_staff_id` | `VARCHAR(100) NULL` | `creator.staff_id` | Mapping audit bila tersedia |
| `remote_dpjp_name` | `VARCHAR(255) NULL` | `dpjp.name` | Snapshot, bukan FK |
| `remote_dpjp_staff_id` | `VARCHAR(100) NULL` | `dpjp.staff_id` | Mapping audit bila tersedia |
| `score_base` | `DECIMAL(10,2) NULL` | `poin.base` | Nilai remote |
| `score_dpjp` | `DECIMAL(10,2) NULL` | `poin.dpjp` | Nilai remote |

Kolom yang **tidak** sebaiknya dimasukkan langsung sebagai puluhan kolom baru:

```text
medical_details.*
```

Detail tersebut lebih aman disimpan sebagai JSON versioned di tabel payload remote. Kolom ringkasan tetap dipakai untuk list/search.

## 5. Tabel integrasi yang disarankan

### 5.1 `medical_record_integrations`

Satu baris untuk identitas record remote dan status sinkronisasi.

Kolom inti:

```text
id
medical_record_id NULL
provider              -- medical_center
hospital_code         -- roxwood
remote_record_id      -- API id
source                 -- remote
sync_state             -- synced / failed / needs_review / stale
remote_created_at NULL
remote_updated_at NULL
last_pulled_at NULL
last_error NULL
payload_hash NULL
created_at
updated_at
```

Unique key wajib:

```text
(provider, hospital_code, remote_record_id)
```

Tujuan: record `id=206` dari API tidak boleh masuk dua kali walaupun poller berjalan berulang.

### 5.2 `medical_record_remote_payloads`

Menyimpan payload asli secara aman dan versioned.

Kolom inti:

```text
id
integration_id
payload_version
payload_json
payload_hash
fetched_at
```

Aturan:

- Payload disimpan untuk audit dan re-proses mapping.
- Jangan tampilkan payload mentah ke user tanpa ACL.
- Jangan menaruh API key di payload atau log.
- Payload remote dianggap data tidak tepercaya; output HTML harus disanitasi.

### 5.3 `medical_record_external_members`

Dipakai bila anggota remote perlu ditampilkan terpisah tanpa memaksa mapping ke `user_rh`.

Kolom inti:

```text
id
medical_record_id
remote_member_id NULL
member_role NULL
member_name
member_staff_id NULL
sort_order
```

### 5.4 `medical_record_external_photos`

Dipakai untuk daftar foto remote.

Kolom inti:

```text
id
medical_record_id
remote_photo_id NULL
remote_url
local_path NULL
sort_order
url_host_verified
```

URL hanya boleh dirender/download dari hostname allowlist yang disetujui. Jangan menerima URL arbitrary untuk mencegah SSRF.

## 6. Mapping data

### 6.1 Record utama

```text
API id                         → medical_record_integrations.remote_record_id
hospital                       → medical_records.source_hospital
 tanggal_waktu                 → medical_records.event_at
lokasi                         → medical_records.location
jenis_operasi                  → medical_records.jenis_operasi
nama_pasien                    → medical_records.patient_name
diagnosa                       → medical_records.diagnosis_text
tindakan_operasi               → medical_records.operation_procedure_text
hasil_operasi                  → medical_records.operation_result_text
catatan                        → medical_records.record_notes
created_at remote              → integration.remote_created_at
updated_at remote              → integration.remote_updated_at
```

### 6.2 Identitas pasien

```text
medical_details.pasien.citizen_id       → patient_citizen_id
medical_details.pasien.dob              → patient_dob
medical_details.pasien.jenis_kelamin    → patient_gender
medical_details.pasien.no_hp            → patient_phone
```

Jika `dob` null, simpan `patient_dob = NULL`. Jangan mengisi tanggal dummy.

### 6.3 Tim

```text
creator → remote_creator_* snapshot
 dpjp   → remote_dpjp_* snapshot
members → medical_record_external_members
```

Mapping ke `user_rh.id` hanya boleh dilakukan lewat `staff_id` yang unik dan tervalidasi. Nama sama tidak cukup.

### 6.4 Detail medis

```text
medical_details → medical_record_remote_payloads.payload_json
poin.base        → score_base
poin.dpjp        → score_dpjp
photos           → medical_record_external_photos
```

## 7. Alur aman yang disarankan

### 7.1 Sumber input tunggal

```text
Medis input dan edit hanya di:
https://medicalcenterime.my.id/staff/dashboard

EMS2 Roxwood tidak menyediakan input, edit, atau delete untuk data remote.
```

EMS2 hanya membaca endpoint yang tersedia:

```text
GET /api/rekam-medis?hospital=roxwood
```

Roxwood hanya melakukan GET pull dari Medical Center. Tidak ada alur write balik ke Medical Center.

### 7.2 Remote Medical Center → EMS2 read-only

```text
1. Scheduler EMS2 memanggil GET dengan hospital=roxwood.
2. API key dikirim server-side, bukan browser.
3. Scheduler mengambil seluruh halaman yang tersedia.
4. Setiap record divalidasi bentuk, tipe, hospital, dan tanggal bisnis.
5. Record dengan tanggal < 2026-09-21 00:00:00 Asia/Jakarta dilewati.
6. Record tanpa tanggal valid dicatat sebagai needs_review, bukan diberi tanggal buatan.
7. Record valid dicari berdasarkan provider + hospital + remote_record_id.
8. Record baru disimpan ke database EMS2.
9. Record remote yang berubah diperbarui dari response terbaru.
10. Payload nested disimpan sebagai JSON versioned.
11. Foto ditampilkan dari URL host allowlist.
12. Status pull dan error dicatat untuk monitoring.
```

Database EMS2 menjadi **read cache**, bukan sumber edit untuk record remote. Jika API sedang error, data cache terakhir tetap ditampilkan dengan status waktu sinkronisasi/error yang jelas.

Tidak ada import historis sebelum cutoff. Jika API hanya menyediakan pagination tanpa filter tanggal, response tetap disaring lokal berdasarkan cutoff. GET polling tidak boleh menganggap record sebelum cutoff sebagai data baru.

Jika API mendukung, gunakan parameter berikut:

```text
updated_since
page
per_page
cursor
include_deleted
```

Jangan mengasumsikan parameter tersebut tersedia sebelum kontrak API mengonfirmasinya.

### 7.3 Perubahan data remote

Medical Center menjadi sumber utama untuk record remote. Setiap polling membandingkan `remote_updated_at` atau hash payload.

- Record baru dibuat di cache EMS2.
- Record berubah diperbarui dari response API.
- Record yang sama tidak boleh digandakan.
- EMS2 tidak mengedit data remote.
- Jika response berubah tidak dapat diproses, cache lama tetap dipertahankan dan status menjadi `needs_review`.

### 7.4 Error GET dan notifikasi

```text
Scheduler EMS2 memanggil GET
→ GET berhasil: cache diperbarui
→ GET gagal: cache terakhir tetap tampil
→ status sync/error dicatat
→ notifikasi admin dikirim
→ polling berikutnya mencoba ulang
```

Notifikasi wajib memuat:

```text
hospital=roxwood
waktu polling
status: synced / failed / needs_review
jumlah record diproses
ringkasan error yang aman
```

Notifikasi tidak boleh memuat API key atau payload medis penuh.

### 7.5 Monitoring admin

Halaman monitoring khusus hanya boleh diakses oleh:

```text
Programmer Roxwood
Executive
```

Fitur:

- waktu GET terakhir;
- status `synced`, `failed`, `needs_review`, atau `stale`;
- jumlah record baru/berubah/dilewati;
- detail error tanpa secret;
- remote ID dan local cache ID;
- payload hash/version;
- filter cutoff dan hospital;
- tombol `sync now` yang hanya menjalankan GET;
- audit perubahan cache.

Tidak ada tombol input, edit, push, retry, POST, PUT, PATCH, DELETE, atau write action remote di EMS2.

Nama Programmer Roxwood adalah pengecualian legacy yang sudah ada di EMS2. Saat implementasi, sebaiknya gunakan helper ACL terpusat dan jangan menyebar pengecekan nama ke banyak file.

## 8. Status kontrak API

Keputusan integrasi:

| Area | Keputusan | Status validasi |
|---|---|---|
| Sumber utama record yang sudah terhubung | Medical Center API | Disetujui |
| Record remote di EMS2 | Tampil sebagai `standard` | Disetujui |
| `dob` remote `null` | Boleh masuk sebagai `NULL` | Disetujui |
| Nested detail | JSON versioned | Disetujui |
| Foto remote | Tampilkan dari URL allowlist | Disetujui |
| Arah data | Medical Center GET → EMS2 | Disetujui |
| Input/edit/delete remote | Hanya di Medical Center | Di luar scope EMS2 |
| Method EMS2 | GET saja | Disetujui |
| POST/PUT/PATCH/DELETE | Tidak digunakan | Di luar scope EMS2 |
| Outbox/Idempotency-Key | Tidak digunakan | Di luar scope EMS2 |
| Monitoring admin | Programmer Roxwood dan Executive | Disetujui |

EMS2 hanya membutuhkan kontrak GET: pagination, filter tanggal bila tersedia, rate limit, format error, dan aturan API key read-only.

## 9. Audit API yang sudah dilakukan

Yang terbukti dari response yang tersedia:

```text
GET endpoint merespons JSON valid.
success=true.
data berisi record rekam medis.
Pagination tersedia.
Total response yang terlihat: 84.
Per page yang terlihat: 15.
Nested medical_details tersedia.
```

Method yang **tidak digunakan EMS2** dan berada di luar scope:

```text
POST
PUT
PATCH
DELETE
Idempotency-Key
outbox
webhook
```

EMS2 hanya memakai GET. Input, edit, dan delete dilakukan di Medical Center `/staff/dashboard`.

## 10. Rencana pengujian GET read-only

```text
[ ] GET dengan API key: HTTP 200 + JSON valid
[ ] `hospital=roxwood` tidak mencampur hospital lain
[ ] Semua halaman pagination terbaca
[ ] Cutoff 2026-09-21 00:00:00 Asia/Jakarta diterapkan
[ ] Record sebelum cutoff tidak masuk cache EMS2
[ ] Record tanpa tanggal ditandai needs_review
[ ] Remote ID yang sama tidak menjadi duplikat
[ ] Record remote berubah memperbarui cache EMS2
[ ] GET timeout/error tidak menghapus cache terakhir
[ ] Error dicatat dan notifikasi admin dikirim
[ ] Foto hanya dari host allowlist
[ ] API key tidak masuk HTML, browser, atau log
[ ] Halaman rekam medis EMS2 tidak memiliki input/edit/delete remote
[ ] ACL standard/private tetap berlaku
```

EMS2 hanya menjalankan GET. EMS2 tidak pernah mengirim POST, PUT, PATCH, atau DELETE ke Medical Center.

## 11. ACL dan keamanan

1. API key hanya di environment/service secret.
2. API key tidak boleh dikirim dari browser.
3. Endpoint internal sync harus login + role/permission khusus.
4. Scheduler memakai lock agar dua sync tidak berjalan bersamaan.
5. Semua query memakai prepared statement.
6. Payload dan teks remote disanitasi sebelum HTML output.
7. Foto remote hanya dari host allowlist dan HTTPS.
8. Data `visibility_scope=forensic_private` tidak boleh ikut API umum tanpa aturan eksplisit.
9. Semua perubahan cache dan hasil polling masuk audit log.
10. Log hanya mencatat status, remote ID, hash, dan error aman; bukan API key atau payload sensitif penuh.
11. API response harus divalidasi ukuran maksimal agar payload abnormal tidak menghabiskan memory.
12. Sync gagal sebagian tidak boleh rollback record yang sudah berhasil diproses pada batch berbeda tanpa alasan jelas.

## 12. Keputusan final sebelum implementasi

Keputusan dari user:

| No. | Keputusan | Implementasi yang wajib mengikuti |
|---:|---|---|
| 1 | Medical Center API menjadi sumber utama setelah record memiliki `remote_record_id` | EMS2 tidak diam-diam menimpa perubahan remote |
| 2 | Record remote tampil di list EMS2 sebagai `standard` | Tetap tunduk pada ACL halaman umum |
| 3 | `dob` remote `null` boleh masuk | `patient_dob` harus nullable; tidak boleh memakai tanggal dummy |
| 4 | Detail nested disimpan sebagai JSON versioned | Payload asli tetap dapat diaudit dan diproses ulang |
| 5 | Foto remote ditampilkan dari URL allowlist | Tidak wajib cache lokal; blokir URL di luar host allowlist |
| 6 | Arah data | Medical Center GET → EMS2 read-only |
| 7 | Input/edit/delete | Dilakukan hanya di Medical Center `/staff/dashboard` |
| 8 | Method EMS2 | GET saja |
| 9 | POST/PUT/PATCH/DELETE, outbox, Idempotency-Key | Tidak digunakan |
| 10 | Monitoring admin diperlukan | Hanya Programmer Roxwood dan Executive |

### Alur bisnis final

```text
1. Medis input/edit/delete hanya di Medical Center `/staff/dashboard`.
2. Medical Center menyimpan record.
3. EMS2 memanggil GET dengan `hospital=roxwood`.
4. EMS2 memfilter record mulai 2026-09-21 00:00:00 Asia/Jakarta.
5. EMS2 menyimpan atau memperbarui cache berdasarkan remote ID.
6. EMS2 menampilkan record remote di `dashboard/rekam_medis_list.php` sebagai read-only.
7. Jika GET error, cache terakhir tetap tampil dengan status stale/error.
8. EMS2 tidak mengirim data lokal ke Medical Center.
9. EMS2 tidak mengubah atau menghapus data Medical Center.
```

### Scope data

- Cutoff remote: `2026-09-21 00:00:00 Asia/Jakarta`.
- Record remote sebelum cutoff tidak diimpor.
- Record lokal sebelum cutoff tidak dikirim.
- Tidak ada backfill historis.
- Record remote menjadi sumber utama untuk cache read-only.
- Record remote dicocokkan memakai `provider + hospital + remote_record_id`, bukan nama pasien.

### Hak monitoring

Halaman monitoring pull/cache hanya untuk:

```text
Programmer Roxwood
Executive
```

Akun lain tidak boleh melihat API key, payload mentah, error internal, atau tombol retry/delete remote.

### Dokumentasi API

Endpoint yang digunakan EMS2 hanya GET production. Tidak ada kebutuhan dokumentasi write untuk alur ini. Kontrak GET yang perlu dipastikan saat implementasi: pagination, filter tanggal bila tersedia, rate limit, format error, dan aturan API key read-only.

## 13. Kesimpulan

Rancangan paling aman bukan menyalin seluruh JSON API ke kolom `medical_records` atau membuat tabel rekam medis kedua.

Pola yang disarankan:

```text
medical_records
  + kolom ringkasan yang sering dicari
  + tabel integration untuk remote identity/status
  + tabel payload JSON untuk detail API yang berubah
  + tabel members/photos untuk relasi remote
  + read cache metadata dan error terakhir
```

Dengan pola ini:

- kolom EMS2 yang sudah ada tetap dipertahankan;
- field baru yang stabil dapat ditambahkan secara terukur;
- detail API tidak hilang;
- polling dapat diulang tanpa duplikasi;
- cache terakhir tetap tersedia saat GET error;
- EMS2 tidak mengirim perubahan ke Medical Center.
