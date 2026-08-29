# Document Library Module (Menu "Dokumen")

Dokumen ini adalah rencana (PRD + ERD + rancangan teknis) untuk fitur baru:
perpustakaan dokumen internal dengan folder/subfolder per division, upload
oleh manager, dan pencarian cepat berbasis isi dokumen (bukan cuma nama
file). Ditulis dulu sebagai draft sebelum implementasi, sesuai permintaan —
mohon dibaca dan dikoreksi bagian "Pertanyaan Terbuka" di paling bawah
sebelum saya mulai ngoding, supaya alur, ACL, dan struktur tabel tidak perlu
dirombak ulang di tengah jalan.

Catatan penting soal folder `Dokumen/` yang sebelumnya ada di root repo:
sudah dipindah ke `storage/dokumen_import/` (2026-08-27) — lokasi ini
otomatis ikut ter-`.gitignore` karena seluruh `storage/` sudah diabaikan
git, jadi tidak perlu entry terpisah lagi di `.gitignore`. Folder ini
**bukan** storage yang dikelola aplikasi — storage resmi fitur ini nanti
tetap flat di `storage/documents/` (lihat §7). `storage/dokumen_import/`
murni jadi **sumber seed**: struktur asli (`Handbook-EMS/1. KEBIJAKAN DAN
SOP SMA/...` dst, sudah berbentuk hierarki mirip nama division/kategori)
akan dibaca oleh satu script import sekali-jalan (lihat §2 &amp; §10) yang
otomatis membuat `document_folders`/`document_files` + menjalankan
ekstraksi teks — jadi hierarki folder yang sudah Anda susun manual ini
langsung jadi struktur folder di dalam aplikasi, tanpa upload ulang satu
per satu lewat UI.

## 1. Tujuan Modul

- Menyediakan satu tempat terpusat untuk dokumen referensi (SOP, handbook
  medis, script "spell" operasi, voucher faction, dll — termasuk isi
  `docs/EMS/` yang selama ini cuma jadi file lepas tak tercari).
- Pencarian **berbasis isi dokumen**: ketik kata kunci (misal "paramedic"),
  langsung muncul semua dokumen yang isinya mengandung kata itu, klik →
  langsung ke isi dokumennya. Tidak boleh lambat, artinya isi dokumen harus
  sudah diekstrak & diindeks **sebelum** pencarian terjadi (saat upload),
  bukan dibaca ulang dari file setiap kali user mengetik.
- Manager tiap division bisa upload dokumen ke folder division-nya sendiri.
- Division `Executive` punya kontrol penuh lintas-division: buat/ubah
  nama/hapus folder & subfolder, pindahkan dokumen atau folder ke tempat
  lain, hapus dokumen siapa pun.
- **Semua user yang login** (lintas division, dalam unit yang sama) bisa
  browse folder/subfolder dan search — jadi basis pengetahuan bersama,
  tanpa bisa upload/edit/hapus kalau bukan pemiliknya (lihat §3).

## 2. Ruang Lingkup Halaman & File Baru

| File | Fungsi |
|---|---|
| `dashboard/dokumen.php` | Halaman utama: search bar (live/instant) + browse folder/subfolder + daftar dokumen. Dibuka semua user yang berhak lihat. |
| `dashboard/document_view.php` | Tampilan satu dokumen: hasil ekstraksi teks dirender sebagai HTML rapi (untuk docx/odt/doc/txt/pdf-teks), atau embed langsung untuk PDF/gambar. Ada tombol unduh file asli. |
| `ajax/document_search.php` | Endpoint JSON untuk live search (dipanggil dari `dokumen.php` saat mengetik, di-debounce). |
| `dashboard/document_manage.php` | Halaman kelola: upload (manager-plus), plus tree folder + tombol rename/hapus/pindah untuk Executive. Satu halaman, kapabilitas UI menyesuaikan role — sama seperti pola `dispatcher.php` (semua bisa lihat, aksi mutasi digating per hak akses). |
| `dashboard/document_manage_action.php` | Controller POST+CSRF: upload, replace file, edit judul/tag, hapus dokumen, buat/rename/hapus/pindah folder, pindah dokumen antar folder. |
| `config/document_library.php` | `ensure_tables()`, helper ekstraksi teks per tipe file, helper permission/ACL, pembangun tree folder, query builder pencarian. |
| `docs/sql/70_2026-08-27_document_library_module.sql` | Migration tabel baru (nomor lanjutan setelah `69_...`). |
| `bin/import_dokumen_seed.php` (CLI, sekali-jalan) | Membaca `storage/dokumen_import/` secara rekursif, memetakan tiap subfolder jadi `document_folders` (subfolder bertingkat → parent_id berjenjang), tiap file jadi `document_files` (extract teks + copy ke `storage/documents/`). Dijalankan manual lewat CLI (`php bin/import_dokumen_seed.php`), bukan lewat web — sesuai pola script satu-kali lain di project ini (`pindah.php`, `backfill_operations.php`). |

## 3. Akses & Role (usulan)

Mengikuti pola ACL yang sudah ada di project ini (§3 & §10 CLAUDE.md): role
manager-plus = `probation manager, assisten manager, lead manager, head
manager, vice director, director` (`ems_is_manager_plus_role()`), division
`Executive` sudah otomatis "lihat semua" lewat `ems_can_access_division_menu()`.

| Aksi | Staff biasa | Manager-plus (division X, bukan Executive) | Executive (role apa pun di division itu) |
|---|---|---|---|
| Cari & buka dokumen | ✅ semua division, semua folder (basis pengetahuan bersama) | ✅ semua | ✅ semua |
| Upload dokumen baru | ❌ | ✅ hanya ke folder division X yang sudah ada | ✅ ke folder mana pun |
| Edit judul/tag dokumen | ❌ | ✅ **semua dokumen di division-nya** | ✅ semua dokumen |
| Hapus dokumen | ❌ | ✅ **semua dokumen di division-nya** | ✅ semua dokumen |
| Pindah dokumen antar folder | ❌ | ❌ | ✅ |
| Buat folder/subfolder baru | ❌ | ❌ (strict upload-only ke folder yang sudah ada) | ✅ |
| Rename folder | ❌ | ❌ | ✅ |
| Hapus folder (termasuk hapus paksa/cascade) | ❌ | ❌ | ✅ |
| Pindah folder/subfolder | ❌ | ❌ | ✅ |

Setiap endpoint mutasi (`document_manage_action.php`) mengecek ulang
permission di server, tidak cukup sembunyikan tombol di UI — pola yang sama
persis dipakai di modul Forensic Private Access ("setiap mutation surface
mengecek permission sendiri-sendiri").

## 4. Model Data (ERD)

Tiga tabel baru, semuanya `InnoDB` + `utf8mb4` (standar project), dengan
`unit_code` untuk mendukung multi-unit (roxwood/alta) mengikuti pola
feature-detect `ems_column_exists()` yang sudah jadi konvensi project.

### `document_folders`
| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | INT PK AI | |
| `unit_code` | VARCHAR(20) DEFAULT 'roxwood' | |
| `division` | VARCHAR(60) NOT NULL | Division pemilik folder (level top maupun subfolder — subfolder **mewarisi** division parent-nya, tidak bisa beda sendiri) |
| `parent_id` | INT NULL FK→`document_folders.id` | NULL = folder level atas |
| `name` | VARCHAR(150) NOT NULL | |
| `description` | VARCHAR(255) NULL | |
| `sort_order` | INT DEFAULT 0 | Urutan tampil manual |
| `created_by` | INT FK→`user_rh.id` | |
| `created_at`, `updated_at` | DATETIME | |

Tree folder dibangun di PHP (ambil semua folder per division, susun jadi
tree via `parent_id`), **bukan** pakai `WITH RECURSIVE` SQL — supaya tidak
bergantung versi MariaDB tertentu, konsisten dengan gaya project yang
menghindari fitur SQL eksotis.

### `document_files`
| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | INT PK AI | |
| `unit_code` | VARCHAR(20) DEFAULT 'roxwood' | |
| `folder_id` | INT NOT NULL FK→`document_folders.id` | Setiap dokumen wajib punya folder (folder "Umum" dibuat default per division saat migration) |
| `division` | VARCHAR(60) NOT NULL | Salinan division folder saat itu — supaya query ACL tidak perlu JOIN; disinkronkan ulang tiap kali dokumen dipindah folder |
| `title` | VARCHAR(255) NOT NULL | Judul tampil (boleh beda dari nama file asli) |
| `original_filename` | VARCHAR(255) NOT NULL | |
| `file_path` | VARCHAR(255) NOT NULL | `storage/documents/...`, akses lewat `secure_file.php` |
| `file_ext` | VARCHAR(10) | |
| `mime_type` | VARCHAR(100) | |
| `file_size_bytes` | INT | |
| `tags` | VARCHAR(255) NULL | Tag manual pemisah koma, ikut kena index pencarian |
| `extracted_text` | LONGTEXT NULL | Hasil ekstraksi isi dokumen — inti dari pencarian cepat |
| `extraction_status` | ENUM('pending','done','unsupported','failed') DEFAULT 'pending' | `unsupported` = tipe file tanpa extractor (mis. scan gambar tanpa teks) |
| `uploaded_by` | INT FK→`user_rh.id` | |
| `created_at`, `updated_at` | DATETIME | |
| **FULLTEXT KEY** `(title, tags, extracted_text)` | | Lihat §6 |

### `document_activity_logs`
Sama persis pola `forensic_private_record_logs` (actor name di-snapshot
supaya histori tetap kebaca walau akun di-rename):
`id, document_id NULL, folder_id NULL, action ENUM('uploaded','edited',
'replaced','moved','deleted','folder_created','folder_renamed',
'folder_moved','folder_deleted'), actor_id, actor_name, note, created_at`.

## 5. Alur Bisnis

### Upload dokumen (manager-plus)
1. Manager buka `document_manage.php`, pilih folder tujuan (dibatasi ke
   folder division-nya sendiri — dropdown/tree hanya menampilkan cabang
   division dia), isi judul + tag opsional, pilih file.
2. `document_manage_action.php` (`action=upload`) validasi CSRF, role,
   ukuran file (lihat §7), simpan file apa adanya ke `storage/documents/`
   (nama file `uniqid()_time().ext`, pola sama seperti
   `uploadAndCompressFile()` yang sudah ada — tanpa kompresi, dokumen tidak
   dikompres seperti gambar).
3. Server menjalankan ekstraksi teks sesuai tipe file (lihat §6) secara
   **sinkron** dalam request yang sama (project ini tidak punya
   infrastruktur job queue — semua proses lain juga sinkron, jadi ini
   konsisten), simpan ke `extracted_text` + set `extraction_status`.
4. Baris baru di `document_files` + log `uploaded`.

### Pencarian (semua user yang berhak lihat)
1. User ketik di search box pada `dokumen.php` → JS debounce ~250ms →
   `GET ajax/document_search.php?q=...`.
2. Endpoint query `document_files` dengan `MATCH(title, tags,
   extracted_text) AGAINST(... IN BOOLEAN MODE)` (tiap kata diberi akhiran
   `*` supaya "match sambil mengetik", bukan cuma kata utuh) DIGABUNG
   (`UNION`/`OR`) dengan `title LIKE '%q%'` untuk menjaring query pendek
   yang tidak lolos ambang `ft_min_word_len`/`innodb_ft_min_token_size`
   MySQL/MariaDB (biasanya minimal 3-4 karakter).
3. Filter ACL division ikut di `WHERE` yang sama (lihat §9) — pencarian
   tidak pernah menampilkan dokumen di luar hak lihat user.
4. Tiap hasil bawa cuplikan (snippet) potongan `extracted_text` di sekitar
   kata yang cocok, di-escape lalu `<mark>` di kata kuncinya.
5. Klik hasil → langsung ke `document_view.php?id=...`.

### Kelola folder (Executive)
Tree folder ditampilkan per division (expand/collapse). Tiap node folder
ada aksi: tambah subfolder, rename, hapus (diblokir kalau masih berisi
dokumen/subfolder — kecuali Executive pilih opsi hapus paksa dengan
konfirmasi eksplisit yang menyebutkan jumlah isi yang akan ikut terhapus),
pindahkan (modal pemilih folder tujuan, otomatis mengecualikan folder itu
sendiri & keturunannya supaya tidak terjadi nested-loop). Tiap dokumen ada
aksi: pindah folder, ganti file (upload ulang → re-extract, ID/URL dokumen
tetap sama), edit judul/tag, hapus.

## 6. Strategi Ekstraksi Teks (per tipe file)

| Tipe | Cara | Status di codebase |
|---|---|---|
| `.txt .md .csv .json .xml .log .ini` | Baca langsung isi file | Pola sudah ada (lihat cabang di `emsConvertDocumentToPng()`) |
| `.docx` | `emsExtractDocxText()` | **Sudah ada**, siap pakai lintas OS |
| `.odt` | `emsExtractOdtText()` | **Sudah ada**, siap pakai lintas OS |
| `.doc` (legacy binary) | `emsExtractLegacyDocText()` | **Sudah ada**, best-effort (strip biner), hasil tidak serapi format lain |
| `.pdf` | **Butuh library baru** — lihat §7 | Belum ada extractor PDF sama sekali di project ini |
| `.xlsx .xls` | Ambil teks tiap cell via `phpoffice/phpspreadsheet` (sudah terpasang); untuk tampilan di `document_view.php` dirender ulang sebagai tabel HTML asli (bukan teks polos) langsung dari file, tanpa perlu download | **Sudah didukung** (2026-08-30, tidak jadi fase 2) |
| Gambar (jpg/png) tanpa OCR | Tidak diekstrak, `extraction_status='unsupported'` | OCR.Space API sudah ada di project (dipakai untuk KTP) tapi reuse untuk scan dokumen halaman-banyak = biaya/skalabilitas beda kelas — **fase 2/opsional**, bukan MVP |

Dokumen dengan `extraction_status != 'done'` tetap bisa dicari lewat judul
& tag manual — makanya field `tags` penting sebagai jaring pengaman manual.

Ada batas ukuran/panjang ekstraksi (misal skip setelah N karakter atau file
di atas ukuran tertentu) supaya upload tetap responsif — dokumen sangat
besar tidak bikin request timeout.

## 7. Penyimpanan File, Keamanan & Batas Upload

- File fisik disimpan flat di `storage/documents/<uniqid>_<time>.<ext>` —
  folder/subfolder murni konsep database (`folder_id`), bukan struktur
  folder fisik. Memindahkan dokumen antar folder = `UPDATE folder_id`
  saja, tidak perlu memindah file di disk.
- Akses baca lewat `ajax/secure_file.php` (menambah 1 rule prefix baru
  `storage/documents/`) — otorisasi: query `division` dokumen itu, cek
  `ems_can_access_division_menu($userDivision, $docDivision)`, sama pola
  dengan rule Forensic yang sudah ada di file itu.
- **Batas ukuran upload perlu helper baru**, tidak bisa pakai
  `emsUploadLimitBytes()` yang ada sekarang — itu hard-cap **1MB**,
  memang didesain untuk gambar terkompresi, terlalu kecil untuk PDF/Word
  asli. Diusulkan: `emsDocumentUploadLimitBytes()` terpisah (contoh: 15MB)
  khusus modul ini.
- Catatan infrastruktur: `.user.ini` project saat ini mengizinkan **10MB**
  di level PHP (`upload_max_filesize`/`post_max_size` — lihat §7 CLAUDE.md
  poin gotcha upload). Kalau batas modul ini mau di atas 10MB,
  `.user.ini` juga perlu dinaikkan di server produksi (di luar kendali
  kode aplikasi).

## 8. Dependency Baru

- **`smalot/pdfparser`** (composer, pure-PHP, tanpa binary eksternal) perlu
  ditambah ke `composer.json` — satu-satunya cara realistis mengekstrak
  teks PDF di project ini. Dipilih dibanding pendekatan render-ke-gambar
  (`emsRenderUrlToPng`/headless Chrome yang sudah ada) karena fitur itu
  **Windows-only** dan tidak akan jalan di host produksi Linux/cPanel
  (§10 poin 6 CLAUDE.md) — jadi tidak bisa dipakai untuk fitur yang wajib
  jalan di production.
- Tidak perlu Elasticsearch/Algolia/search-engine eksternal — FULLTEXT
  index bawaan MySQL/MariaDB (InnoDB) sudah cukup untuk skala dokumen
  internal komunitas RP (ratusan–ribuan file), dan project ini memang
  tidak punya infrastruktur search eksternal apa pun saat ini.

## 9. Sidebar & Registrasi ACL

Mengikuti pola "Roxwood Hospital AI" / `police_partnership.php` /
`konsumen.php`: semua halaman modul ini didaftarkan sebagai **pengecualian
page-level-open** di `ems_enforce_dashboard_page_access()`
(`config/helpers.php`) — bisa dibuka semua user login. Untuk `dokumen.php`
+ `document_view.php` + `ajax/document_search.php`, isi yang tampil
**tidak** difilter per division (keputusan §11 poin 3) — hanya difilter
`unit_code` (poin 7). Untuk `document_manage.php` +
`document_manage_action.php`, tombol/opsi mutasi tetap digating per role
(manager-plus lihat folder division-nya saja untuk upload; Executive lihat
semua), persis pola Dispatcher (semua bisa lihat board, mutasi digating
role).

- `dokumen.php` → masuk grup `Utama` di `partials/sidebar.php` (selalu
  terlihat, sejajar Dashboard/Daftar Medis Roxwood) — supaya gampang
  ditemukan sesuai permintaan "mudah dicari".
- `document_manage.php` → masuk grup `Administrasi`, **hanya tampil untuk
  manager-plus** (sama seperti item manager-only lain di grup itu, mis.
  generator kelompok).

## 10. Fase Pengembangan

**Fase 1 (MVP)** — semua yang dijelaskan di atas: folder+subfolder,
Executive full CRUD folder, manager upload ke division sendiri, ekstraksi
txt/docx/odt/doc/pdf, live search FULLTEXT+LIKE, viewer (teks hasil
ekstraksi jadi HTML rapi + embed PDF/gambar + unduh file asli), activity
log.

**Fase 2 (opsional, belakangan)** — OCR untuk
PDF/gambar hasil scan, subfolder oleh manager (bukan cuma Executive),
highlight-scroll-ke-kata-yang-cocok di viewer, drag-and-drop UI pemindahan.

## 11. Keputusan Final (dikonfirmasi 2026-08-29)

1. **Subfolder oleh manager non-Executive**: **tidak boleh**. Strict
   upload-only ke folder yang sudah disiapkan Executive — manager cuma
   pilih folder tujuan dari daftar yang ada, tidak bisa bikin folder
   baru sendiri.
2. **Edit/hapus dokumen oleh manager non-Executive** *(dikoreksi
   2026-08-30 — pemahaman awal salah, sudah diperbaiki di kode)*:
   **semua dokumen di division-nya sendiri**, siapa pun yang upload —
   bukan cuma upload-annya sendiri. `ems_document_can_edit_or_delete()`
   sekarang murni cek `division cocok ATAU Executive`, tidak ada lagi
   pengecekan `uploaded_by`.
3. **Visibilitas pencarian/browse**: **basis pengetahuan bersama** —
   SEMUA user yang login bisa cari & buka dokumen apa pun, lintas
   division, lintas unit sekalipun (lihat poin 7). Tidak ada filter
   `ems_can_access_division_menu()` untuk baca. Upload/edit/hapus tetap
   dibatasi per division seperti poin 1 & 2.
4. **Hapus folder yang masih berisi**: diblokir secara default; Executive
   punya opsi "hapus paksa" yang men-cascade — menghapus seluruh
   subfolder & dokumen di dalamnya (baris DB + file fisik di
   `storage/documents/`), dengan konfirmasi eksplisit yang menyebut
   jumlah item yang akan ikut terhapus.
5. **Batas ukuran file**: **10 MB** — pas sama batas `.user.ini` produksi
   yang sudah ada saat ini, jadi **tidak perlu** perubahan infrastruktur
   apa pun di server. `emsDocumentUploadLimitBytes()` baru akan
   mengembalikan `10 * 1024 * 1024` secara independen dari
   `emsUploadLimitBytes()` (yang tetap 1MB, khusus gambar).
6. **PDF text extraction**: pakai **`smalot/pdfparser`** — dipilih karena
   ini library ekstraksi teks PDF pure-PHP paling populer & paling
   banyak dipakai di ekosistem PHP (jutaan install lewat Packagist,
   tanpa dependency binary eksternal seperti `pdftotext`/Ghostscript,
   jadi aman untuk hosting shared/cPanel), lisensi open-source (LGPL),
   dan hasil ekstraksi teksnya termasuk paling stabil dibanding
   alternatif pure-PHP lain untuk PDF standar (non-scan).
7. **Multi-unit (roxwood/alta)**: **dibedakan** — perpustakaan dokumen
   tetap di-scope per `unit_code` mengikuti pola tabel lain di project
   ini. Dampaknya ke poin 3: "basis pengetahuan bersama" berarti semua
   user di **unit yang sama** bisa saling lihat; user `can_view_all_units`
   yang switch unit (§3 CLAUDE.md) otomatis ikut melihat dokumen unit
   yang sedang aktif, sama seperti fitur lain yang unit-scoped.
8. **Pemetaan folder→division untuk seed `storage/dokumen_import/`**:
   karena visibilitas baca sudah global (poin 3), pemetaan ini
   **hanya menentukan siapa yang punya hak edit/hapus/upload-lanjutan**
   ke folder itu, bukan siapa yang boleh membacanya. Pemetaan yang akan
   dipakai importer (silakan koreksi sebelum saya jalankan):

   | Folder asli | Division pemilik |
   |---|---|
   | `Handbook-EMS/1. KEBIJAKAN DAN SOP SMA/*` | `Specialist Medical Authority` |
   | `Handbook-EMS/4. SOP EMS/2. SOP Committee Discipline` | `Disciplinary Committee` |
   | `Handbook-EMS/4. SOP EMS/3. SOP General Affairs` | `General Affair` |
   | `Handbook-EMS/4. SOP EMS/4. SOP Sekretariat Relation` | `Secretary` |
   | `Handbook-EMS/4. SOP EMS/5. SOP Human Resource` | `Human Resource` |
   | `Handbook-EMS/4. SOP EMS/6. SOP Forensic` | `Forensic` |
   | Sisanya (`2. PROSEDUR PENANGAN MEDIS`, `3. Medical Handbook`,
     `4. SOP EMS/0,1,7`, `5. PERTOLONGAN PERTAMA...`, `6. VOUCHER`,
     `Kamus Me Do...txt`, dll) | `Medis` (default binder ini memang EMS) |

   Folder milik `Executive`/`Human Capital` tidak ada di seed ini —
   kalau perlu, Executive bisa bikin folder baru lewat UI kapan saja
   setelah fitur jadi.
