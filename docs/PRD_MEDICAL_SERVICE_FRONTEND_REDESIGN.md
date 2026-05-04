# PRD Medical Service Frontend Redesign

## 1. Informasi Dokumen

- Nama project baru: `medical_service`
- Tipe dokumen: Product Requirements Document
- Fokus fase ini: redesign frontend dan restrukturisasi alur UI
- Status: draft aktif
- Tanggal: 2026-05-02
- Basis sistem lama: `D:\Project\Web\ems2`

## 2. Ringkasan Eksekutif

Project `medical_service` adalah inisiatif migrasi aman dari sistem EMS lama ke frontend baru yang lebih ringan, stabil, konsisten, dan mudah dipelihara tanpa mengubah struktur database inti, query inti, maupun proses simpan data yang sudah berjalan saat ini.

Fase pertama **bukan** rewrite total backend dan **bukan** redesign database. Fase pertama hanya berfokus pada:

- membangun frontend baru yang modern dan rapi
- menstandarkan komponen UI dan pola form
- merapikan struktur page, state, table, modal, upload, dan validasi
- mempertahankan alur submit ke database yang kompatibel dengan sistem lama
- menyiapkan fondasi agar migrasi backend bisa dilakukan setelah frontend stabil

Tujuan utamanya adalah menghasilkan aplikasi yang:

- lebih cepat dipakai untuk input data
- tidak terasa berat saat save
- lebih konsisten secara visual dan perilaku
- lebih aman untuk dikembangkan bertahap
- tetap bisa di-deploy ke shared hosting tanpa SSH, tanpa terminal, dan tanpa Node.js di server

## 3. Latar Belakang

Sistem EMS lama saat ini sudah berkembang menjadi aplikasi monolith kompleks dengan banyak modul, antara lain:

- rekam medis
- layanan EMS
- farmasi
- HR
- reimbursement
- surat dan sekretariat
- forensic
- specialist medical authority
- payroll, approval, dan data administrasi lain

Karakter sistem lama:

- banyak halaman form panjang
- banyak endpoint `fetch/AJAX`
- banyak tabel besar
- sudah ada beberapa pola `localStorage`
- ada upload dokumen dan kompresi gambar
- UI sudah mulai bergerak ke design system, tetapi belum konsisten

Masalah utama yang ingin diselesaikan pada fase ini:

- tampilan dan komponen belum konsisten
- struktur frontend masih bercampur antara pola lama dan pola baru
- banyak halaman sulit dipelihara
- pola interaksi antar halaman tidak seragam
- komponen input, modal, tabel, upload, dan feedback user masih berantakan
- proses redesign sulit dilakukan langsung di project lama tanpa arah arsitektur baru

## 4. Visi Produk

Membangun frontend `medical_service` sebagai admin web medis modern yang:

- mempertahankan logika bisnis dan database lama
- memiliki UI admin panel yang konsisten dan profesional
- mendukung form besar, data padat, dan upload dokumen
- nyaman dipakai untuk operasional harian
- mudah dikustomisasi dan ditambah modul baru
- aman untuk migrasi bertahap dari EMS lama

## 5. Sasaran Utama

### 5.1 Sasaran bisnis

- Menurunkan biaya perubahan UI dan frontend di masa depan
- Mempercepat pengembangan modul baru tanpa mengulang pola lama
- Mengurangi risiko error akibat frontend yang tidak konsisten
- Menjaga agar migrasi tidak mengganggu operasional harian

### 5.2 Sasaran teknis

- Memisahkan frontend baru dari legacy lama secara bertahap
- Menggunakan stack frontend yang ringan, populer, dan stabil
- Menjaga kompatibilitas dengan database dan proses save yang sudah ada
- Menghasilkan asset build statis yang bisa di-upload langsung ke shared hosting
- Menghindari kebutuhan build di server

### 5.3 Sasaran UX

- Semua halaman memakai layout, spacing, input, button, modal, table, dan feedback yang seragam
- Semua form penting memiliki draft persistence
- Save terasa cepat, jelas statusnya, dan tidak membingungkan user
- Halaman padat data tetap mudah dibaca dan dicari

## 6. Non-Goals

Fase ini tidak mencakup:

- perubahan besar struktur SQL
- migrasi database ke skema baru
- rewrite total seluruh backend business logic
- perubahan fundamental proses insert/update yang sudah berjalan baik
- migrasi infrastruktur server ke VPS
- PWA offline penuh
- mobile app native

## 7. Batasan dan Constraint

Project harus mengikuti constraint nyata dari lingkungan deploy:

- hosting target adalah shared hosting
- tidak ada SSH
- tidak ada terminal
- tidak ada Node.js di server
- proses deploy utama adalah git push atau upload file hasil build
- PHP tersedia, shell PHP terbatas tersedia
- perubahan setelah deploy harus langsung aktif tanpa step build di server

Implikasi arsitektur:

- frontend harus dibuild di lokal atau CI sebelum di-push
- hasil build harus berupa asset statis siap pakai
- backend Laravel harus tetap bisa berjalan di shared hosting
- jangan membuat arsitektur yang mensyaratkan worker frontend/server runtime Node

## 8. Keputusan Stack

### 8.1 Opsi yang dipilih

Opsi yang dipilih adalah **Opsi A**: frontend modern yang ringan dan stabil, dengan backend Laravel tetap kompatibel terhadap pola simpan data lama.

Stack yang dipakai:

- Backend web/app: Laravel 13
- Bahasa backend: PHP 8.4
- Database: tetap memakai database lama yang sama
- Frontend app: React 19
- Bahasa frontend: TypeScript
- Bundler: Vite
- Styling: Tailwind CSS 4
- Form handling: React Hook Form
- Validasi schema frontend: Zod
- Server state dan cache: TanStack Query
- State draft lokal: Zustand dengan persist
- Table engine: TanStack Table
- HTTP client: native `fetch` atau `ky`

### 8.2 Template admin panel

Frontend baru akan menggunakan **template admin panel gratis yang lengkap, stabil, dan mudah dikustomisasi** sebagai fondasi tampilan.

Template yang direkomendasikan:

- **TailAdmin Free React**

Alasan:

- gratis dan open source
- berbasis React + TypeScript + Tailwind
- cocok untuk dashboard/admin internal
- komponen admin cukup lengkap
- mudah dipecah dan disesuaikan menjadi design system internal
- lebih sejalan dengan arah stack ringan dibanding template Bootstrap-heavy

Catatan penting:

- template hanya sebagai fondasi visual dan layout
- seluruh komponen inti project tetap harus dibungkus ulang menjadi komponen internal
- project tidak boleh tergantung secara arsitektural pada struktur mentah template

## 9. Prinsip Arsitektur

### 9.1 Prinsip utama

- Frontend baru, database lama
- UI baru, alur bisnis lama
- Perubahan bertahap, bukan big-bang rewrite
- Form dan table harus reusable
- Semua modul memakai pola yang sama
- Asset harus siap deploy ke shared hosting

### 9.2 Strategi integrasi

Pada fase awal, frontend baru harus tetap bisa mengirim dan menerima data dengan pola yang kompatibel dengan backend lama, sehingga:

- nama field penting dipertahankan bila memungkinkan
- proses save mengikuti struktur insert/update lama
- query dan tabel database tidak dirombak hanya demi frontend
- API atau controller adapter bisa dibuat untuk menjembatani frontend baru ke proses lama

### 9.3 Strategi komunikasi frontend-backend

Pendekatan yang dipakai adalah **API-first untuk frontend baru**, walaupun backend dan database masih mengikuti sistem lama.

Artinya:

- frontend tidak lagi bergantung pada inline script per halaman
- tiap modul baru memiliki endpoint data yang lebih rapi
- submit, search, filter, autocomplete, detail fetch, dan upload dilakukan async
- UI tidak harus reload satu halaman penuh untuk operasi kecil

## 10. Scope Fase Pertama

Fase pertama hanya mencakup redesign frontend dan penataan fondasi aplikasi.

### 10.1 Yang harus dibuat

- shell aplikasi admin baru
- routing frontend yang rapi
- design tokens dan theme dasar
- komponen reusable
- pola form reusable
- pola table reusable
- pola modal/drawer reusable
- uploader reusable
- draft persistence reusable
- adapter request agar tetap kompatibel dengan backend/database lama
- halaman-halaman prioritas tinggi hasil migrasi frontend

### 10.2 Yang tetap dipertahankan

- database existing
- tabel existing
- query dan struktur data utama yang sudah stabil
- sebagian besar business rules lama
- sebagian besar proses save lama

## 11. Pengguna dan Persona

Pengguna sistem meliputi:

- staff medis
- farmasi
- admin operasional
- HR
- finance
- sekretariat
- supervisor/manager
- management tertentu

Kebutuhan mereka:

- input cepat
- minim kebingungan antar halaman
- validasi jelas
- data tidak hilang saat error atau refresh
- upload mudah
- table dan pencarian responsif

## 12. Kebutuhan Fungsional

### 12.1 App shell

Sistem harus memiliki shell admin baru yang konsisten:

- sidebar
- topbar
- breadcrumb
- page header
- content area
- feedback area
- modal layer

### 12.2 Design system

Sistem harus memiliki komponen internal yang konsisten untuk:

- button
- input text
- textarea
- select
- radio
- checkbox
- switch
- date picker
- autocomplete
- table
- pagination
- badge
- alert
- toast
- modal
- drawer
- tabs
- card
- uploader
- preview image/file
- skeleton/loading
- empty state
- error state

### 12.3 Form engine

Semua form besar harus mengikuti pola yang seragam:

- schema validation
- inline error
- summary error bila perlu
- submit state
- loading state
- dirty state
- autosave draft
- clear draft
- restore draft

### 12.4 Draft persistence

Semua form input penting harus memiliki draft persistence.

Aturan:

- text, number, select, radio, checkbox, textarea, date disimpan otomatis
- draft dipulihkan otomatis saat halaman dibuka kembali
- draft tetap ada jika submit gagal atau browser tertutup
- draft hanya hilang jika user klik tombol `Clear Draft` atau jika submit final sukses dan aturan modul mengizinkan draft dihapus
- ada namespace per modul dan per record
- ada versioning schema draft untuk mencegah error setelah perubahan field

Catatan teknis:

- `localStorage` dipakai untuk field umum
- untuk file/gambar draft, gunakan IndexedDB atau localForage
- jangan menyimpan blob gambar besar di `localStorage`

### 12.5 Save data

Proses save harus terasa cepat dan aman.

Perilaku yang diwajibkan:

- klik save memberi feedback langsung
- tombol submit tidak spam
- error server ditampilkan jelas
- data tidak hilang jika save gagal
- request kecil tidak me-reload satu halaman penuh
- request berat dipisahkan dari rendering UI bila memungkinkan

### 12.6 Table dan data listing

Halaman list harus memiliki pola standar:

- search
- filter
- sort
- pagination
- row action
- bulk action jika dibutuhkan
- loading state
- empty state
- error state

### 12.7 Upload gambar dan dokumen

Sistem harus menyediakan uploader yang seragam.

Kemampuan minimum:

- preview file
- validasi tipe file
- validasi ukuran file
- progress state
- retry bila gagal
- kompresi gambar di client sebelum upload
- fallback validasi dan kompresi di server bila diperlukan

### 12.8 Kompresi gambar

Aturan kompresi:

- target akhir gambar operasional: sekitar 200 KB sampai 500 KB
- kualitas visual tetap cukup jelas untuk dibaca dan di-zoom normal
- dimensi panjang tetap dijaga agar tidak terlalu kecil
- gunakan format yang realistis seperti JPEG atau WebP sesuai kebutuhan

Bila diperlukan secara operasional atau legal, sistem boleh memakai dua versi:

- versi kerja/preview terkompresi
- versi asli/arsip jika benar-benar diperlukan oleh modul tertentu

## 13. Kebutuhan Non-Fungsional

### 13.1 Performa

- Halaman harus terasa ringan di perangkat kantor standar
- Save form umum harus memberikan feedback instan
- UI tidak boleh freeze saat input atau autosave
- Table besar harus dioptimalkan

### 13.2 Konsistensi

- Satu jenis komponen harus memiliki satu perilaku utama
- Label, bantuan, error, dan action harus memakai format seragam
- Semua modul baru mengikuti aturan UI yang sama

### 13.3 Maintainability

- komponen reusable dipisahkan dengan jelas
- folder feature dan shared dipisah
- helper request, schema, form state, dan uploader tidak boleh tersebar acak

### 13.4 Deployability

- build frontend harus menghasilkan file statis
- hasil build bisa langsung disimpan ke repo atau artefak deploy
- deploy ke shared hosting tidak memerlukan Node runtime
- perubahan setelah git push harus bisa aktif hanya dengan file PHP dan asset hasil build

### 13.5 Compatibility

- aplikasi tetap bekerja dengan database lama
- transisi modul per modul harus memungkinkan
- tidak boleh memaksa refactor total backend sebelum frontend siap

## 14. Arsitektur Deploy

### 14.1 Prinsip deploy

Karena server tidak memiliki Node.js dan SSH, maka alur deploy harus:

1. build frontend di lokal atau CI
2. commit hasil build yang diperlukan
3. git push ke hosting/deploy target
4. shared hosting langsung melayani asset hasil build

### 14.2 Implikasi teknis

- jangan mengandalkan `npm install` di server
- jangan mengandalkan `npm run build` di server
- jangan mengandalkan queue worker frontend
- jika Laravel butuh cache tertentu, gunakan mekanisme yang tetap cocok dengan shared hosting

### 14.3 Prinsip aman

- asset build harus deterministic
- path asset harus stabil
- fallback harus jelas jika file build belum ter-update

## 15. Struktur Produk yang Diinginkan

Secara konseptual aplikasi baru dibagi menjadi:

- `app shell`
- `shared ui`
- `shared form`
- `shared table`
- `shared uploader`
- `shared state`
- `shared api client`
- `feature modules`

Feature modules tetap mengikuti domain bisnis, misalnya:

- rekam medis
- ems services
- farmasi
- secretary
- specialist
- reimbursement
- HR

## 16. Modul Prioritas Migrasi Frontend

Prioritas awal dipilih berdasarkan beratnya form, banyaknya interaksi user, dan dampak UX.

Urutan prioritas yang direkomendasikan:

1. auth dan app shell
2. dashboard summary dasar
3. modul form-heavy prioritas tinggi
4. modul list dan table prioritas tinggi
5. modul upload-heavy
6. modul approval dan utility

Contoh kandidat awal:

- rekam medis
- EMS services
- rekap farmasi
- setting akun
- secretary modules

## 17. Asumsi Integrasi Backend

Agar aman terhadap database lama, backend awal untuk `medical_service` dapat memakai salah satu dari pola berikut:

- adapter controller Laravel yang memanggil query/proses lama
- service layer Laravel yang membungkus logika lama secara bertahap
- endpoint JSON baru yang menghadap ke tabel lama

Keputusan penting:

- struktur tabel existing tidak diubah hanya untuk mengejar desain frontend
- perubahan backend hanya dilakukan bila dibutuhkan untuk adapter, keamanan, atau konsistensi respons

## 18. Requirement Respons API

Respons endpoint baru harus konsisten:

- status sukses/gagal seragam
- error message ramah user
- detail validation error per field
- payload list dan detail memiliki format standar
- metadata pagination terstruktur

## 19. UX Rules

### 19.1 Rule umum

- tombol aksi primer selalu jelas
- tombol destructive harus terpisah jelas
- field wajib diberi penanda yang konsisten
- error tidak boleh hanya muncul di alert global
- setiap save harus menunjukkan status: idle, saving, success, failed

### 19.2 Rule form besar

- sectioning harus jelas
- field dikelompokkan per konteks
- input tidak boleh terlalu padat tanpa hirarki
- autosave tidak boleh mengganggu typing
- restore draft berjalan otomatis

### 19.3 Rule list/table

- filter penting tampil jelas
- action per row mudah ditemukan
- detail panjang dibuka via page detail atau drawer, bukan tabel yang dipaksa terlalu penuh

## 20. Security dan Data Handling

Karena project memproses data medis dan data administratif penting, frontend harus memperhatikan:

- draft browser tidak disimpan tanpa batas waktu
- key storage harus terstruktur
- data sensitif di browser diminimalkan sebisa mungkin
- clear draft harus benar-benar membersihkan data lokal
- upload harus divalidasi dua sisi: client dan server

Catatan:

- kebutuhan enkripsi draft browser dapat dipertimbangkan untuk fase lanjutan
- local persistence harus dibatasi sesuai kebutuhan operasional dan risiko

## 21. Risiko Utama

- frontend baru terlalu jauh dari pola backend lama
- komponen template dipakai mentah tanpa distandardisasi
- autosave menjadi berat bila implementasi state salah
- developer kembali menulis page-specific component tanpa reusable layer
- upload gambar terlalu agresif sehingga kualitas jelek
- build/deploy gagal karena ketergantungan pada proses server-side yang tidak tersedia

## 22. Strategi Mitigasi

- mulai dari adapter layer, bukan rewrite database
- semua komponen penting dibuat ulang di atas fondasi template
- semua form besar memakai engine yang sama
- semua modul baru wajib melewati pattern yang sama
- asset build diperlakukan sebagai bagian dari artefak deploy
- perubahan dilakukan modul demi modul

## 23. Kriteria Sukses

Fase frontend redesign dianggap berhasil bila:

- shell aplikasi baru stabil
- design system dasar selesai dan dipakai lintas modul
- minimal modul prioritas awal berhasil pindah ke frontend baru
- proses save tetap kompatibel dengan database lama
- tidak ada kebutuhan perubahan besar SQL untuk menjalankan frontend baru
- deploy ke shared hosting tetap berjalan tanpa SSH dan tanpa Node.js di server
- user merasakan input lebih rapi, save lebih jelas, dan UI lebih konsisten

## 24. Definition of Done Fase PRD

PRD ini dianggap lengkap untuk menjadi dasar kerja AI dan tim bila sudah menjelaskan:

- tujuan migrasi
- batasan proyek
- stack yang dipilih
- alasan pemilihan
- scope fase pertama
- requirement fungsi dan non-fungsi
- constraint deploy shared hosting
- prinsip integrasi dengan database lama
- prioritas modul migrasi

## 25. Langkah Lanjutan Setelah PRD

Dokumen ini akan diikuti oleh dokumen terpisah:

- TODO implementasi rinci
- struktur folder project `medical_service`
- langkah migrasi paling aman
- mapping modul lama ke modul baru
- aturan komponen dan naming convention
- kontrak API awal

## 26. Referensi Eksternal

- Laravel 13 release notes: https://laravel.com/docs/13.x/releases
- Laravel starter kits: https://laravel.com/docs/13.x/starter-kits
- TanStack Query docs: https://tanstack.com/query/latest/docs/framework/react/overview
- TanStack Table docs: https://tanstack.com/table/latest/docs/overview
- Zustand persist docs: https://zustand.docs.pmnd.rs/reference/integrations/persisting-store-data
- React Hook Form docs: https://react-hook-form.com/
- Zod docs: https://zod.dev/
- TailAdmin React template: https://github.com/TailAdmin/free-react-tailwind-admin-dashboard

