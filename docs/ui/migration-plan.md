# UI Migration Plan EMS

## Audit
- CSS lama terpecah di `app.css`, `layout.css`, `components.css`, `responsive.css`, `login.css`
- Duplikasi terbesar berada pada card, button, modal, tabel, alert, dan wrapper pencarian
- Banyak inline style di halaman dashboard dan icon emoji langsung di markup
- Partial utama yang menjadi shell dashboard: `partials/header.php`, `partials/sidebar.php`, `partials/footer.php`

## Inventory Halaman
- Overview/statistik: `dashboard/index.php`, `ranking.php`, `absensi_ems.php`
- CRUD tabel dan modal: `manage_users.php`, `konsumen.php`, `reimbursement.php`, `restaurant_consumption.php`, `validasi.php`, `regulasi.php`
- Form-heavy: `rekap_farmasi.php`, `rekap_farmasi_v2.php`, `ems_services.php`, `setting_akun.php`, `restaurant_settings.php`, `setting_spreadsheet.php`
- Recruitment/candidate: `candidates.php`, `candidate_detail.php`, `candidate_interview_multi.php`, `candidate_decision.php`
- Outlier/standalone: `events.php`, `identity_test.php`

## Component Mapping
- `card`, `login-card`, `modal-card`, `identity-card` -> `Card`
- `card-header`, `modal-header`, `identity-card-header` -> `CardHeader`
- `btn-*`, `btn-submit`, `btn-resign` -> `Button`
- `table-custom` -> `DataTable`
- `alert-*`, `notif`, `toast` -> `Toast/Alert`
- `modal-overlay`, `inbox-modal-overlay`, `image-lightbox` -> `Modal`
- toolbar pencarian, filter, export -> `TableToolbar`

## Strategi
- Putuskan shell global dari CSS lama lebih dulu
- Bangun style kompatibilitas Tailwind untuk class yang sudah tersebar luas
- Lokalkan library frontend yang sebelumnya via CDN
- Migrasikan halaman prioritas tinggi dan halaman standalone ke shell baru
- Hapus emoji UI yang terlihat dan ganti dengan helper Heroicons
