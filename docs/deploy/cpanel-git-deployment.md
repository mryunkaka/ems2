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
mengambilnya dan memperbarui checkout produksi. Jika tidak ada perubahan,
script selesai tanpa mengubah situs. Script mencatat hasil di
`/home/fouf9972/git-deploy.log`. Ini memerlukan koneksi keluar hosting ke
GitHub dan remote `origin` yang dapat diakses oleh akun cPanel.

Untuk menjalankan pemeriksaan pertama, gunakan **Terminal** cPanel:

```bash
/bin/bash /home/fouf9972/public_html/rh_ems/deploy.sh
```

Lalu periksa `/home/fouf9972/git-deploy.log`. Endpoint
`https://roxwoodhospitalime.my.id/ajax/deployment_status.php` menampilkan
branch dan SHA checkout aktif. Bandingkan `commit` dengan commit terbaru di
GitHub. Deploy kode tidak menerapkan migrasi SQL; jalankan migrasi baru secara
terpisah sesuai checklist rilis.
