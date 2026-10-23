# Rencana Implementasi Antrean Permintaan AI

> **Untuk pelaksana:** Gunakan subskill `superpowers:executing-plans` untuk mengerjakan rencana ini tahap demi tahap setelah rencana disetujui.

**Tujuan:** Menjalankan permintaan model AI melalui antrean MySQL dan worker PHP CLI agar beban model terkendali serta pengguna melihat status tunggu sampai hasil tersedia.

**Arsitektur:** Permintaan web menyimpan job berisi referensi data, peminta, dan label fitur; worker CLI mengambil job secara FIFO dengan pengunci slot dan memanggil provider. Endpoint status, layar tunggu bersama, dan integrasi fitur menghubungkan hasil worker ke halaman yang meminta AI.

**Teknologi:** PHP 8, PDO/MySQL, JavaScript browser, cron PHP CLI, migrasi SQL proyek.

**Spesifikasi:** `docs/superpowers/specs/2026-10-23-ai-request-queue-design.md`

## Batasan Global

- Konkurensi bawaan: **1** pekerjaan model; pilihan administrator: **1, 2, atau 3**.
- Batas antrean bawaan: **50** job; nilai yang diizinkan **10 sampai 200**.
- Worker menggunakan jendela **50 detik** per proses cron; job yang sedang memanggil model dibiarkan selesai.
- Job `running` kedaluwarsa setelah timeout provider ditambah toleransi **30 detik**, lalu gagal tanpa percobaan ulang.
- Retensi job `completed` dan `failed`: **7 hari**.
- Job menyimpan referensi/opsi minimum, bukan API key atau prompt yang sudah dirangkai.
- Pengguna hanya dapat membaca hasil job miliknya; pemantauan global dibatasi ke metadata nama peminta dan label fitur.
- Jangan menjalankan `git add`, `git commit`, atau `git push`; berikan rekomendasi pesan commit di laporan akhir.
- Tambahkan pengujian otomatis lokal tanpa akses provider eksternal atau database produksi; gunakan SQLite in-memory/file sementara untuk layanan antrean dan fake HTTP provider lokal bila diperlukan.
- Jalankan pengujian antrean setelah setiap tugas serta pemeriksaan sintaks PHP dan perubahan akhir; uji cron pada hosting produksi tetap perlu dilakukan operator.

## Fokus Tinjauan

1. Dua worker mengambil job bersamaan: tidak boleh melewati batas konkurensi dan tidak boleh mengambil satu job yang sama.
2. Job membawa referensi yang sudah tidak valid atau pengguna tidak lagi berhak: worker/status harus gagal aman tanpa membocorkan data.
3. Worker berhenti saat provider berjalan: lease harus mencegah duplikasi dan memberi status gagal yang bisa dilihat.
4. Pengguna menekan tombol berulang atau mengirim dua permintaan bersamaan: job aktif yang sama dipakai kembali.
5. Job interview, Roxy, notifikasi, dan pembuatan grup selesai: setiap handler harus menyimpan hasil domain satu kali dan menjaga alur UI yang sudah ada.

---

## Peta Berkas

- Buat `docs/sql/103_2026-10-23_ai_request_queue.sql` untuk tabel job dan kolom pengaturan konkurensi/batas antrean; deploy menggunakan runner migrasi yang menerima nomor 103 ke atas.
- Buat `config/ai_queue.php` untuk enqueue, deduplikasi, pembacaan status/posisi, ringkasan monitor, klaim/lease, dan transisi state.
- Buat `actions/ai_queue_handlers.php` sebagai dispatcher job bertipe dan penyimpanan hasil khusus fitur.
- Buat `actions/ai_queue_worker.php` sebagai entry point CLI-only yang mengambil slot dan memproses FIFO sampai jendela 50 detik berakhir.
- Buat `ajax/ai_queue_submit.php` serta `ajax/ai_queue_status.php` untuk endpoint terautentikasi; endpoint status melepaskan sesi PHP dan hanya mengembalikan job milik peminta.
- Buat `partials/ai_queue_screen.php`, `dashboard/ai_queue_wait.php`, dan `assets/js/ai_queue.js` untuk layar tunggu, polling, status global yang aman, dan kelanjutan ke fitur asal.
- Ubah `config/ai_settings.php`, `dashboard/ai_settings.php`, dan `dashboard/ai_settings_action.php` untuk pengaturan concurrency/queue serta monitor admin.
- Ubah `actions/ai_gemini_client.php`, `actions/ai_custom_client.php`, `actions/groq_client.php`, dan `config/ai_provider_core.php` agar pemanggilan langsung hanya dapat dilakukan dalam konteks worker; endpoint fitur tidak boleh melewati antrean.
- Integrasikan handler dan pemanggil: `actions/ai_recruitment_service.php`, `dashboard/candidate_interview_multi.php`, `actions/roxy_chat_action.php`, `config/roxy_chatbot.php`, `config/training_groups.php`, `dashboard/training_group_generator.php`, `config/birthday_helper.php`, `actions/birthday_notices.php`, `actions/work_anniversary_notices.php`, dan `partials/header.php`.
- Integrasikan uji koneksi provider pada `dashboard/ai_settings_action.php`, `dashboard/ai_settings_personal_action.php`, dan `dashboard/ai_settings_personal.php` sehingga kredensial tidak dimasukkan ke payload antrean.
- Buat `docs/deploy/ai-queue-worker.md` untuk cron PHP CLI sekali per menit, pemantauan worker, dan prasyarat migrasi.
- Buat `tests/ai_queue/run.php` sebagai runner test PHP mandiri dan file pengujian per domain; test tidak membutuhkan PHPUnit atau kredensial `.env`.

## Antarmuka Antrean

- `ems_ai_queue_enqueue(PDO $pdo, string $jobType, int $requesterId, string $featureLabel, array $references, string $dedupeKey): array` mengembalikan `['job_id' => int, 'status' => 'queued'|'running', 'reused' => bool]`.
- `ems_ai_queue_status(PDO $pdo, int $jobId, int $requesterId): array` memverifikasi kepemilikan dan mengembalikan state, label, nomor posisi bila menunggu, metadata active-job yang aman, hasil saat selesai, atau pesan gagal.
- `ems_ai_queue_monitor(PDO $pdo): array` hanya dipanggil setelah pemeriksaan akses Programmer Roxwood; mengembalikan count tiap state dan metadata job aktif.
- `ems_ai_queue_run(PDO $pdo, int $maxRunSeconds = 50): array` mengunci slot, memproses job FIFO sampai batas waktu, menyelesaikan lease/state, dan membersihkan retensi.
- `ems_ai_queue_handle(PDO $pdo, array $job): array` mengirim `job_type` ke handler khusus dan mengembalikan hasil serializable untuk status job.
- Dispatcher menerima payload yang tervalidasi dan hanya memuat referensi/opsi fitur; handler memuat data faktual dari tabel domain sebelum membuat prompt.

---

## Task 1: Skema Antrean dan Pengaturan Global

**Berkas:**

- Buat `docs/sql/103_2026-10-23_ai_request_queue.sql`.
- Ubah `config/ai_settings.php`.

**Antarmuka:**

- Menghasilkan kolom `ai_max_concurrent_requests` dengan default 1 dan `ai_max_queue_size` dengan default 50 pada `system_ai_settings`.
- Menghasilkan tabel `system_ai_request_queue` dengan state `queued`, `running`, `completed`, `failed`; label, peminta, payload referensi, hasil, error, dedupe key, waktu enqueue/start/finish/lease; serta indeks FIFO, deduplikasi, peminta/state, dan retensi.
- Tambahkan helper pembaca batas konfigurasi dengan fallback aman sebelum kolom tersedia.

- [ ] Tulis test merah untuk default batas antrean dan validasi min/max konfigurasi.
- [ ] Buat migrasi bernomor 103 sesuai pola migrasi deploy; tabel job menggunakan InnoDB dan kolom `active_dedupe_key` unik yang menjadi `NULL` saat job terminal agar job baru dapat dibuat setelah job lama selesai.
- [ ] Tambahkan default dan validasi batas 1 sampai 3 konkurensi serta 10 sampai 200 antrean pada helper pengaturan sampai test hijau.
- [ ] Jalankan `php tests/ai_queue/run.php settings` dan pastikan test pengaturan lolos.
- [ ] Pastikan migrasi mengikuti pola deployment baru dan tidak mengubah file migrasi lama.
- [ ] Periksa nama kolom, default, indeks dan kompatibilitas jenis SQL dengan runner deployment proyek.

## Task 2: Layanan Antrean dan Worker CLI

**Berkas:**

- Buat `config/ai_queue.php`.
- Buat `actions/ai_queue_handlers.php`.
- Buat `actions/ai_queue_worker.php`.
- Ubah `actions/ai_gemini_client.php`, `actions/ai_custom_client.php`, `actions/groq_client.php`, dan `config/ai_provider_core.php`.

**Antarmuka:**

- Gunakan empat fungsi antrean pada bagian **Antarmuka Antrean**.
- Definisikan konstanta konteks `EMS_AI_QUEUE_WORKER` hanya pada entry point CLI setelah memastikan `PHP_SAPI === 'cli'`.
- Provider dipanggil dari handler worker; pemanggilan client langsung dari permintaan web melempar error terkontrol.

- [ ] Tulis test merah untuk enqueue FIFO, deduplikasi paralel, batas antrean, transisi status, kepemilikan hasil, dan pemulihan job kedaluwarsa pada database SQLite lokal.
- [ ] Implementasikan enqueue transaksional: validasi peminta/label/referensi, kembalikan job aktif dengan dedupe key yang sama, dan tolak jika antrean mencapai batas sampai test enqueue hijau.
- [ ] Implementasikan status dan posisi FIFO; status milik pengguna lain tidak mengembalikan result atau active metadata sensitif.
- [ ] Implementasikan penguncian slot database, klaim atomik, heartbeat/lease, penanganan job basi, penyimpanan result/error, dan retensi 7 hari.
- [ ] Implementasikan worker CLI yang memproses job berurutan sampai 50 detik, mengizinkan model aktif selesai, menolak eksekusi dari web, dan mencatat error internal tanpa kredensial.
- [ ] Pindahkan jalur dispatch provider global Gemini/OpenAI-Compatible serta gambar ke handler worker-only; jalur Gemini/OpenAI-Compatible/Groq personal untuk uji koneksi juga hanya berjalan dari handler worker.
- [ ] Pastikan pencatatan `system_ai_request_logs` dan batas request harian tetap berlaku saat model dipanggil worker.
- [ ] Jalankan `php tests/ai_queue/run.php core` dan pastikan seluruh skenario layanan antrean hijau.
- [ ] Periksa PHP syntax pada tiga client, helper, handler, dan entry worker.

## Task 3: Endpoint, Layar Tunggu, dan Pengaturan Admin

**Berkas:**

- Buat `ajax/ai_queue_submit.php` dan `ajax/ai_queue_status.php`.
- Buat `partials/ai_queue_screen.php`, `dashboard/ai_queue_wait.php`, dan `assets/js/ai_queue.js`.
- Ubah `config/ai_settings.php`, `dashboard/ai_settings.php`, dan `dashboard/ai_settings_action.php`.

**Antarmuka:**

- Endpoint submit memeriksa sesi, CSRF, tipe job yang diizinkan, dan payload rujukan; lalu mengirim job ID dan URL layar tunggu.
- Endpoint status menerima job ID, menerapkan pemeriksaan pemilik/role dan melepaskan session lock sebelum query/polling.
- JS polling menggunakan jeda bertahap sampai status terminal, menghentikan polling sesudah selesai/gagal, lalu menampilkan hasil atau menuju URL kelanjutan yang tervalidasi.

- [ ] Tulis test merah untuk validasi job type, CSRF, akses ID job milik orang lain, active metadata aman, dan pemulihan polling sampai state terminal.
- [ ] Buat endpoint submit/status JSON dengan kode respons untuk antrean penuh, request invalid, sesi kedaluwarsa, dan akses job tanpa izin; endpoint hanya menerima job type allowlist dan memeriksa akses peminta ke setiap referensi fitur sebelum enqueue sampai test endpoint hijau.
- [ ] Buat layar tunggu bahasa Indonesia dengan nama peminta, label fitur, posisi, status, progress indeterminate, nama/fitur job global aktif, dan pesan hasil/gagal.
- [ ] Batasi URL kelanjutan ke path lokal yang diizinkan; escape semua nama/label; jangan tampilkan prompt, respons provider, data kandidat, atau API key dalam monitor global.
- [ ] Tambahkan input konfigurasi konkurensi dan batas antrean serta monitor count/active job yang hanya terlihat oleh Programmer Roxwood.
- [ ] Pastikan polling session tidak menahan lock dan status milik pengguna lain tidak dapat diambil melalui manipulasi ID.
- [ ] Jalankan `php tests/ai_queue/run.php http` dan pemeriksaan sintaks PHP/JS tanpa memanggil provider.

## Task 4: Ringkasan dan Pertanyaan Interview Kandidat

**Berkas:**

- Ubah `actions/ai_recruitment_service.php` dan `dashboard/candidate_interview_multi.php`.
- Tambah handler `candidate_interview_prepare` pada `actions/ai_queue_handlers.php`.

**Antarmuka:**

- Payload job berisi `applicant_id`, `hr_id`, dan `recruitment_type`; handler memuat ulang kandidat, hasil tes, jawaban, dan kriteria.
- Handler membuat ringkasan dan paket pertanyaan yang belum tersedia, menyimpan keduanya ke tabel domain, dan mengembalikan URL interview.

- [ ] Tulis test merah yang memastikan GET interview tidak memanggil provider, job persiapan hanya satu untuk kandidat/HR yang sama, dan paket hasil tersimpan dibuka setelah job selesai.
- [ ] Pisahkan fungsi persiapan interview dari side effect render halaman; GET halaman hanya membaca data tersimpan sampai skenario interview lulus.
- [ ] Jika ringkasan/paket wajib belum tersedia, GET hanya merender keadaan persiapan; halaman mengirim POST CSRF untuk enqueue satu job dedupe berdasarkan kandidat + HR + jenis rekrutmen, lalu mengarahkan peminta ke layar tunggu.
- [ ] Handler memakai fungsi service saat ini, mempertahankan fallback pertanyaan/ringkasan yang ada, dan menyimpan hasil satu kali.
- [ ] Jika AI nonaktif atau job gagal, layar menampilkan pesan/fallback yang sesuai tanpa membuat GET berulang memanggil model.
- [ ] Pastikan paket tersimpan tidak memicu job baru dan halaman interview hanya terbuka setelah data job siap.
- [ ] Jalankan `php tests/ai_queue/run.php interview` dan pemeriksaan syntax PHP pada service/halaman.

## Task 5: Chat Roxy

**Berkas:**

- Ubah `actions/roxy_chat_action.php`, `config/roxy_chatbot.php`, dan UI chat di `dashboard/ai_assistant.php`.
- Tambah handler `roxy_chat` pada `actions/ai_queue_handlers.php`.

**Antarmuka:**

- Request awal memvalidasi pesan/conversation, menyimpan pesan pengguna, lalu mengantrekan `conversation_id`, `user_message_id`, dan unit; tidak menyimpan prompt/history penuh di job.
- Handler memuat ulang riwayat hingga pesan itu, memanggil `ems_roxy_ask()` di konteks worker, dan menyimpan balasan bot beserta metadata.
- Polling memakai `ai_queue_status.php`; hasil job mengembalikan metadata pesan yang diperlukan UI.

- [ ] Tulis test merah untuk satu pesan pengguna per request, dedupe submit berulang, isolasi conversation/unit, dan satu balasan bot setelah job selesai.
- [ ] Pisahkan alur submit AJAX dari pemanggilan `ems_roxy_ask()` langsung dan simpan pesan pengguna secara idempoten sebelum job dibuat sampai test chat lulus.
- [ ] Implementasikan handler yang membangun kembali user/unit/riwayat dari database serta menyimpan jawaban bot sekali.
- [ ] Ubah UI untuk menampilkan layar/status antrean per pesan, menerapkan batas ukuran/rate yang ada, dan menampilkan hasil/failure tanpa mengirim ulang prompt.
- [ ] Pastikan job owner dan unit conversation diverifikasi oleh handler/status.
- [ ] Jalankan `php tests/ai_queue/run.php roxy` dan pemeriksaan syntax PHP/JS pada action, chatbot, dan halaman assistant.

## Task 6: Training, Notifikasi, Masa Kerja, dan Uji Koneksi

**Berkas:**

- Ubah `config/training_groups.php`, `dashboard/training_group_generator.php`, `config/birthday_helper.php`, `actions/birthday_notices.php`, `actions/work_anniversary_notices.php`.
- Ubah `partials/header.php` untuk polling notifikasi apresiasi masa kerja.
- Ubah `dashboard/ai_settings_action.php`, `dashboard/ai_settings_personal_action.php`, dan `dashboard/ai_settings_personal.php`.
- Tambah handler `training_group_generate`, `birthday_message`, `work_anniversary_generate`, dan `provider_connection_test` di `actions/ai_queue_handlers.php`.

**Antarmuka:**

- Payload hanya menyimpan ID/opsi referensi (unit, batch, ukuran grup, daftar manager tervalidasi, user/ tahun perayaan, jenis provider tersimpan).
- Handler menyusun prompt setelah memuat data domain, mempertahankan penyimpanan/notification lama, dan memakai actor ID peminta pada log AI.
- Uji koneksi membaca kredensial yang sudah tersimpan; antrean tidak pernah menyimpan kredensial yang baru dikirim dari form.

- [ ] Tulis test merah untuk handler grup dan konten hari perayaan yang memverifikasi input referensi, idempotensi, dan penyimpanan domain satu kali.
- [ ] Pindahkan pembuatan identitas grup ke job; POST halaman training mengantrekan permintaan, menampilkan layar tunggu, lalu kembali ke batch setelah penyimpanan selesai sampai test domain lulus.
- [ ] Pindahkan pembuatan pesan ulang tahun dari request notifikasi ke job dedupe per pengguna/tanggal; handler menyimpan inbox hanya jika belum ada.
- [ ] Pindahkan pembuatan teks dan gambar apresiasi masa kerja ke worker; ubah `partials/header.php` agar menunggu job status sebelum menampilkan greeting/gambar, dan simpan hasil dengan aturan file serta batas ukuran yang ada.
- [ ] Pindahkan uji koneksi global dan personal Gemini/OpenAI-Compatible/Groq ke antrean. Form uji memakai kredensial tersimpan; jika pengguna memasukkan kredensial baru, minta simpan pengaturan dahulu. Jangan memasukkan rahasia ke job.
- [ ] Pastikan semua pemanggil client provider di luar handler/worker tidak lagi membuat panggilan model langsung.
- [ ] Jalankan `php tests/ai_queue/run.php domain` dan pemeriksaan syntax PHP untuk seluruh file pemanggil/handler.

## Task 7: Dokumentasi Deployment dan Pemeriksaan Akhir

**Berkas:**

- Buat `docs/deploy/ai-queue-worker.md`.
- Periksa semua file yang berubah pada Tugas 1–6.

- [ ] Dokumentasikan penerapan migrasi `103_2026-10-23_ai_request_queue.sql` melalui runner deployment 103+ dan cron `/usr/local/bin/php /home/fouf9972/public_html/rh_ems/actions/ai_queue_worker.php` setiap menit; jelaskan cara memverifikasi path PHP CLI pada cPanel.
- [ ] Dokumentasikan pemantauan status, batas konfigurasi bawaan, kondisi worker macet, retensi, dan cara membaca log; jangan meminta operator menampilkan kredensial.
- [ ] Jalankan `php tests/ai_queue/run.php all`, `php -l` pada semua file PHP baru/berubah, dan `git diff --check`; jangan melakukan request provider atau operasi database produksi.
- [ ] Tinjau `git diff` penuh untuk memastikan tidak ada file rahasia, `.env`, kredensial, atau file hasil generated yang ikut berubah.
- [ ] Laporan akhir mencantumkan perubahan, hasil pemeriksaan, batas verifikasi, dan rekomendasi pesan commit; jangan menjalankan add/commit/push.
