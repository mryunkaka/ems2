# cPanel Git Deployment

Checkout produksi dan direktori aplikasi adalah
`/home/fouf9972/public_html/rh_ems`. Remote `origin` mengarah ke
`https://github.com/mryunkaka/ems2.git`; branch produksi adalah `main`.

`.cpanel.yml` menyalin `HEAD` checkout cPanel menggunakan `git archive`.
File yang tidak dilacak Git seperti `.env`, `.git/`, `storage/`, `vendor/`,
dan upload tidak dihapus. cPanel menjalankan task YAML ketika ada `git push`
langsung ke repository yang dikelola melalui **Git Version Control**.

## Mengambil push GitHub secara otomatis

Push ke GitHub tidak menjalankan hook `post-receive` milik cPanel. Untuk
mengambil push GitHub ke hosting, buka **cPanel → Advanced → Cron Jobs** dan
tambahkan cron berikut:

```cron
*/5 * * * * /bin/bash /home/fouf9972/public_html/rh_ems/deploy.sh >/dev/null 2>&1
```

Cron memeriksa `main` setiap lima menit. Jika ada commit baru, `deploy.sh`
menyiapkan file SQL dari commit terbaru, menjalankan migrasi baru, lalu hanya
jika migrasi berhasil memperbarui checkout kode. Jika tidak ada perubahan,
script selesai tanpa mengubah situs. Script mencatat hasil di
`/home/fouf9972/git-deploy.log`. Ini memerlukan koneksi keluar hosting ke
GitHub dan remote `origin` yang dapat diakses oleh akun cPanel.

### Aktifkan pencatatan migrasi satu kali

Riwayat lama di `docs/sql/` tidak dijalankan ulang. Runner hanya memproses
file bernomor **103 ke atas** dan mencatat file serta checksum-nya di tabel
`ems_deploy_migrations`. Migrasi harus memakai pola nama seperti
`103_2026-10-12_deskripsi.sql`.

Sebelum cron deployment baru berjalan, buat backup database dan pastikan
database produksi sudah menerapkan seluruh migrasi lama sampai nomor 102.
Lalu tambahkan baris berikut ke `.env` di hosting:

```dotenv
EMS_MIGRATION_BASELINE=102
```

Pada eksekusi pertama, runner hanya mencatat baseline 102; tidak menjalankan
SQL lama. Jika database produksi belum sampai migrasi 102, jangan set baseline
dulu: terapkan migrasi lama yang masih kurang secara terencana. Akun database
juga perlu izin membuat tabel serta membaca dan menulis tabel pencatatan.

Setiap file migrasi baru dijalankan satu kali sebelum versi kode baru aktif.
Jika migrasi gagal, deploy kode dihentikan dan detailnya dicatat di
`/home/fouf9972/git-deploy.log`; perbaiki database/migrasi sebelum melanjutkan.
Runner menandai migrasi yang sedang berjalan agar eksekusi berikutnya berhenti
dan tidak mengulang SQL yang mungkin sudah berjalan sebagian.
File migrasi yang sudah tercatat tidak boleh diedit; buat nomor migrasi baru.
Tetap tinjau dan backup sebelum migrasi yang menghapus tabel, kolom, atau data.

Untuk menjalankan pemeriksaan pertama, gunakan **Terminal** cPanel:

```bash
/bin/bash /home/fouf9972/public_html/rh_ems/deploy.sh
```

Lalu periksa `/home/fouf9972/git-deploy.log`. Endpoint
`https://roxwoodhospitalime.my.id/ajax/deployment_status.php` menampilkan
branch dan SHA checkout aktif. Bandingkan `commit` dengan commit terbaru di
GitHub. Deployment via cron ini menjalankan migrasi baru sebelum memperbarui
kode.
