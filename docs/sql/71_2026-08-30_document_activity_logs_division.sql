-- Document Library — tambah kolom division di document_activity_logs supaya
-- riwayat aktivitas bisa difilter per division walau baris dokumen/folder
-- aslinya sudah terhapus. Juga dibuat defensif di
-- ems_document_ensure_tables() (config/document_library.php).

ALTER TABLE `document_activity_logs`
    ADD COLUMN `division` varchar(60) DEFAULT NULL AFTER `folder_id`;

ALTER TABLE `document_activity_logs`
    ADD KEY `idx_document_activity_logs_division` (`unit_code`, `division`);
