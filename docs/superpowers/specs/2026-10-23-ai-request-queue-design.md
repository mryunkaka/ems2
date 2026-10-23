# Rancangan Antrean Permintaan AI

## Tujuan

Mencegah pemanggilan model AI secara bersamaan menghabiskan sumber daya hosting bersama, sekaligus memberi pengguna layar tunggu yang jelas sampai pekerjaan AI yang diminta selesai.

## Perilaku saat ini

- Pemanggilan teks global Gemini dan OpenAI-Compatible diteruskan melalui `ems_gemini_generate_content()` dan `ems_custom_chat_completion()` pada client AI. Pembuatan gambar juga memanggil Gemini secara langsung.
- Pengaturan AI global membatasi jumlah permintaan harian, tetapi belum membatasi jumlah permintaan yang berjalan bersamaan.
- `candidate_interview_multi.php` dapat membuat ringkasan kandidat dan paket pertanyaan interview saat halaman diminta ketika hasil belum tersimpan. Paket pertanyaan interview berisi 20 pertanyaan. Jika beberapa orang membuka halaman bersamaan, beberapa pemanggilan model dapat berjalan sekaligus dan menahan proses PHP.
- Pemanggil utama lainnya meliputi chat/riset mendalam Roxy, ringkasan kandidat dan paket pertanyaan interview, konten ulang tahun dan masa kerja, pembuatan kelompok pelatihan, serta uji koneksi provider.
- Tangkapan layar menunjukkan HTTP 500, tetapi kode lokal dan tangkapan layar tidak menunjukkan stack trace produksi. Lonjakan permintaan AI adalah masalah yang terkonfirmasi di kode; untuk memastikan penyebab HTTP 500 lainnya diperlukan log produksi.

## Rancangan

### Antrean dan worker

Tambahkan antrean AI MySQL yang menyimpan pekerjaan secara persisten. Setiap baris menyimpan jenis pekerjaan, ID pengguna peminta, label singkat, referensi/opsi minimum khusus fitur, waktu, status, serta hasil atau pesan kesalahan yang sudah disaring. Baris antrean tidak menyimpan API key provider atau prompt yang sudah dirangkai. Fitur harus menyimpan lebih dulu masukan yang belum tersimpan sebelum memasukkan pekerjaan ke antrean; worker akan memuatnya kembali berdasarkan referensi.

Alur status adalah `queued -> running -> completed|failed`. Pekerjaan diklaim secara atomik berdasarkan urutan masuk (FIFO). Pengunci slot worker berbasis database mencegah pemanggilan cron yang tumpang tindih melewati batas konkurensi. Batas bawaan adalah **1** pekerjaan model pada satu waktu; administrator dapat memilih 1, 2, atau 3. Batas antrean tunggu bawaan adalah **50**, dan dapat diatur dari 10 sampai 200. Worker CLI memproses pekerjaan sampai jendela kerjanya selama 50 detik habis, lalu keluar agar cron berikutnya melanjutkan. Pemanggilan model yang sudah berjalan tetap dibiarkan selesai meski melewati jendela tersebut; pengunci slot mencegah penggantinya dimulai terlalu cepat. Pekerjaan `running` yang melewati batas waktu provider ditambah masa toleransi 30 detik ditandai gagal dan tidak dicoba ulang otomatis, untuk mencegah biaya model atau efek samping ganda.

Halaman Pengaturan AI menampilkan batas konkurensi, batas antrean, dan pemantauan ringkas (jumlah antrean/berjalan/gagal serta pekerjaan aktif). Petunjuk deployment menjelaskan perintah cron PHP CLI yang berjalan sekali per menit. Jika antrean penuh, permintaan ditolak dengan pesan yang jelas agar antrean tidak tumbuh tanpa batas. Permintaan berulang dari pengguna yang sama untuk fitur/entitas yang sama saat masih menunggu atau berjalan akan menggunakan kembali pekerjaan yang sudah ada. Baris pekerjaan selesai dan gagal disimpan selama tujuh hari; pembersihan tidak menghapus pekerjaan aktif.

### Permintaan dan pengalaman pengguna

Interaksi yang memerlukan AI memasukkan pekerjaan ke antrean dan langsung mengembalikan ID pekerjaan; permintaan web asal tidak menunggu respons provider. Endpoint status yang memerlukan autentikasi hanya mengembalikan hasil pekerjaan pemanggil dan metadata antrean yang aman. Layar tunggu bersama memeriksa status berkala dengan jeda yang dibatasi dan menampilkan:

- nama peminta, fitur AI yang diminta, posisi antrean, dan status saat ini;
- nama pengguna dan label fitur dari pekerjaan aktif global, tanpa rincian kandidat atau prompt;
- indikator untuk `menunggu`, `sedang diproses`, `selesai`, atau `gagal` (provider tidak menyediakan progres terperinci, jadi status pemrosesan memakai indikator tanpa persentase);
- hasil akhir atau pesan kesalahan yang aman, lalu melanjutkan ke halaman/keadaan fitur terkait.

Endpoint polling melepaskan pengunci sesi PHP sebelum merespons. Akses ke ID pekerjaan diperiksa; pengguna tidak dapat membaca hasil milik pengguna lain. Tampilan pekerjaan aktif global hanya menampilkan nama pengguna peminta dan label fitur yang telah ditentukan.

### Integrasi fitur

Semua pemanggil provider AI global, termasuk pembuatan teks dan gambar, harus melewati worker antrean. Handler fitur mempertahankan keluaran dan aturan penyimpanan yang ada sambil memindahkan pekerjaan model keluar dari permintaan web. Integrasi halaman pertama adalah interview kandidat: membuka halaman tidak memulai pembuatan AI; jika ringkasan/pertanyaan yang dibutuhkan belum tersedia, pengguna melihat layar antrean, lalu halaman interview dibuka atau dimuat ulang setelah hasil tersimpan. Hasil tersimpan yang sudah ada tetap dapat langsung dimuat.

Chat Roxy, pembuatan kelompok pelatihan, konten ulang tahun/masa kerja, dan uji koneksi akan menggunakan antrean yang sama ketika memanggil provider global. Tugas terjadwal non-interaktif memasukkan pekerjaan ke antrean dan menyerahkan pengiriman/penyimpanan ke handler fitur yang ada. Konfigurasi provider pribadi tetap tunduk pada status aktif AI global dan kebijakan konkurensi; tidak boleh ada jalur yang melewati worker untuk memanggil model global secara langsung dari permintaan web.

### Penanganan kegagalan dan operasional

- Kesalahan provider/handler mengubah status pekerjaan menjadi `failed`, menyimpan pesan yang aman untuk pengguna, dan menyimpan rincian diagnostik di log server.
- Jika worker berhenti, pekerjaan `running` ditandai gagal setelah masa sewa habis; pekerjaan tidak hilang tanpa jejak dan tidak dieksekusi dua kali.
- Antrean memiliki batas jumlah pekerjaan tunggu yang dapat diatur serta masa simpan terbatas untuk baris selesai/gagal. Pembersihan tidak pernah menghapus pekerjaan aktif.
- Worker memeriksa pengaturan AI global sebelum menjalankan pekerjaan. Menonaktifkan AI mencegah pekerjaan baru dan membuat pekerjaan tertunda gagal dengan pesan yang jelas tanpa memanggil provider.
- Pemantauan antrean membantu menemukan worker yang macet. Perintah cron harus menggunakan PHP CLI dan jalur proyek pada server; worker tidak boleh dapat diakses melalui web.

## Keamanan dan privasi

Gunakan autentikasi pada endpoint antrean/status dan perlindungan CSRF untuk permintaan yang mengubah data. Batasi setiap pembacaan status/hasil ke peminta, kecuali pemantauan administrator terbatas. Escape semua nama dan label di layar tunggu. Jangan tampilkan prompt, respons provider, API key, atau data kandidat sensitif di status antrean global. Terapkan validasi ukuran pada referensi/opsi pekerjaan dan payload hasil.

## Kriteria penerimaan

1. Sepuluh pengguna yang meminta pekerjaan AI secara bersamaan membuat sepuluh pekerjaan yang terlihat, tetapi dengan pengaturan bawaan paling banyak satu worker memanggil model pada satu waktu; permintaan web kembali cepat tanpa menunggu model.
2. Layar tunggu menunjukkan siapa yang sedang menggunakan model untuk fitur apa, serta posisi/status antrean selama hasil belum tersedia.
3. Membuka halaman interview kandidat tidak pernah memulai pemanggilan AI. Konten interview yang belum ada dibuat melalui pekerjaan antrean, dan halaman interview dibuka dengan hasil tersimpan setelah pekerjaan selesai.
4. Hasil tersimpan tidak membuat pekerjaan duplikat, dan klik berulang untuk pengguna/fitur/entitas yang sama menggunakan kembali pekerjaan aktif.
5. Semua titik pemanggilan provider teks/gambar global melewati antrean; tidak ada jalur langsung dari permintaan web.
6. Kasus gagal, antrean penuh, provider nonaktif, worker kedaluwarsa, dan akses status tanpa izin ditangani dengan jelas tanpa membocorkan hasil pengguna lain.
7. Halaman pengaturan menampilkan batas konkurensi dan kondisi antrean; dokumentasi deployment menjelaskan worker cron sekali per menit.

## Di luar cakupan

Perubahan ini tidak membuktikan atau memperbaiki penyebab HTTP 500 produksi lainnya tanpa log server. Perubahan ini tidak menambahkan percobaan ulang otomatis, progres streaming provider, penjadwalan prioritas, atau message broker terdistribusi.
