# UI Design System EMS

## Filosofi
Sistem UI baru memakai arah klinis modern: terang, rapi, responsif, dan mudah dipelihara. Bahasa visual mempertahankan identitas biru-hijau dari EMS lama, tetapi seluruh styling dipusatkan ke Tailwind dan komponen reusable.

## Token
- Warna utama: `primary #0ea5e9`, `primary-dark #0284c7`, `secondary #0369a1`
- Warna status: `success #10b981`, `warning #f59e0b`, `danger #ef4444`
- Surface: `surface #ffffff`, `background #f4f9fc`, `text #0f172a`, `muted #64748b`, `border #cbd5e1`
- Spacing dominan: `6, 8, 10, 12, 14, 16, 18, 20, 24`
- Radius dominan: `6, 8, 10, 12, 14, 16, 999`
- Typography utama: `11, 12, 13, 14, 15, 16, 18, 20, 26`

## Aturan Layout
- Shell global terdiri dari topbar tetap, sidebar tetap, dan area konten berbasis `page`.
- Semua halaman dashboard masuk ke kontainer lebar maksimum `7xl`.
- Komponen data memakai card putih dengan border halus dan bayangan ringan.
- Responsivitas mobile diprioritaskan dengan grid adaptif dan wrapper tabel overflow.

## Arsitektur Komponen
- Layout: shell global di `partials/` dengan asset lokal dari `assets/design/`
- Utility UI PHP: helper icon di `assets/design/ui/icon.php`
- Style source: `assets/design/tailwind/app.css`
- Token source: `assets/design/tokens/theme.json`
- Komponen reusable dipetakan lewat class kompatibilitas seperti `card`, `btn-*`, `table-custom`, `modal-*`, `alert`

## Naming Convention
- Class reusable baru memakai nama generik berbasis peran, bukan halaman
- Variasi visual memakai suffix semantik seperti `-primary`, `-success`, `-danger`
- Semua icon harus lewat Heroicons helper
- Semua teks UI yang disentuh dinormalisasi ke Bahasa Indonesia
