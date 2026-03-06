# Layouts (UI Only)

Folder ini disiapkan untuk wrapper layout yang bisa dipakai ulang pada view dashboard tanpa mengubah logic backend.

Saat ini dashboard masih memakai shell global yang sudah ada:
- `partials/header.php`
- `partials/sidebar.php`
- `partials/footer.php`

Rekomendasi penggunaan ke depan:
- Buat file layout wrapper (misalnya `dashboard-page.php`) yang hanya merapikan markup wrapper (`content`, `page`, grid) dan tidak menyentuh proses PHP/DB.

