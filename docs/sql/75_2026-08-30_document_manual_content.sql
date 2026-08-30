-- Document Library ("Dokumen") — sebagian PDF hasil scan/berisi gambar
-- (bukan teks asli) gagal diekstrak otomatis oleh smalot/pdfparser
-- (extraction_status='failed', extracted_text kosong sehingga dokumen
-- tersebut tidak muncul sama sekali di pencarian FULLTEXT). Nilai enum
-- baru 'manual' menandai baris yang isinya diketik manual oleh admin
-- lewat form upload/edit di document_manage.php, supaya dokumen jenis
-- ini tetap bisa dicari. Juga dibuat defensif lewat
-- ems_document_ensure_tables() di config/document_library.php.

ALTER TABLE `document_files`
    MODIFY COLUMN `extraction_status` ENUM('pending','done','unsupported','failed','manual') NOT NULL DEFAULT 'pending';
