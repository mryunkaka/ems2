# Rancangan: Pendaftaran Event dengan Login dan Riwayat Status

Tanggal: 2026-10-25

## Tujuan
Halaman `dashboard/events.php` hanya dapat diakses setelah login dan tampil memakai kerangka dashboard internal, termasuk sidebar seperti `medical_roster.php` dan alur pendaftaran seperti `sertifikat_heli_pendaftaran.php`. Pengguna mendaftar event memakai akun yang sedang login. Peserta dapat membatalkan pendaftaran atau mendaftar kembali dengan alasan yang tercatat.

## Cakupan
- Pengunjung tanpa sesi login diarahkan ke halaman login dan kembali ke event yang dituju setelah berhasil login.
- Halaman event memakai auth guard, header, sidebar, dan footer dashboard seperti halaman internal lain.
- Pendaftaran diikat ke `user_rh.id` sesi aktif; form tidak meminta identitas akun atau membuat akun baru otomatis.
- Pengguna dapat mendaftar, membatalkan dengan alasan wajib, dan mendaftar lagi dengan alasan wajib.
- Riwayat perubahan disimpan di database. `event_participants` tetap menjadi sumber pendaftaran aktif, sedangkan tabel riwayat menyimpan aksi dan alasan.
- Statistik, daftar peserta aktif, dan generator kelompok hanya memakai pendaftaran aktif. Daftar admin membedakan peserta aktif dan batal beserta waktu/alasan.

## Bukan tujuan
Mengubah pengelolaan event atau menambahkan alur admin untuk mengedit riwayat.

## Pendekatan yang dipilih
1. Menambahkan status dan alasan langsung pada `event_participants`: perubahan lebih kecil, tetapi membatasi/mengaburkan riwayat jika pengguna berulang kali batal dan daftar ulang.
2. Mempertahankan `event_participants` untuk status aktif dan menambahkan tabel riwayat pendaftaran: menjaga relasi peserta/kelompok yang ada dan mencatat setiap aksi dengan alasan serta waktu. **Dipilih** karena riwayat batal dan daftar ulang harus bisa ditelusuri.

## Rincian implementasi
- Gunakan auth guard dashboard dan pola redirect login yang aman dengan tujuan kembali ke `/dashboard/events.php?id=...`.
- Tambahkan migrasi database untuk tabel riwayat pendaftaran event yang menyimpan event, user, aksi (`registered`/`cancelled`), alasan, dan waktu. Tidak mengubah atau menghapus catatan pendaftaran lama.
- Terapkan pendaftaran dan perubahan status sebagai aksi POST dengan validasi CSRF, sesi, kepemilikan pendaftaran, dan alasan non-kosong.
- Pendaftaran pertama dan daftar ulang menambahkan baris aktif pada `event_participants`; pembatalan menghapus baris aktif dan menulis riwayat, sehingga peserta batal tidak masuk hitungan/kelompok.
- Tampilkan status pribadi pengguna, aksi daftar/batal yang sesuai, dan riwayat alasan yang relevan pada daftar admin.
- Gunakan header, sidebar, footer, styling, dan pola halaman pendaftaran yang sama dengan halaman sertifikat heli.

## Kriteria selesai
Pengguna tanpa login tidak dapat membuka halaman event; setelah login halaman memakai sidebar dashboard. Pendaftaran aktif muncul sekali, pembatalan mengharuskan alasan dan mengeluarkan pengguna dari hitungan/kelompok aktif, serta daftar ulang mengharuskan alasan dan menyimpan jejak semua aksi. Alasan tersedia pada daftar peserta batal.
