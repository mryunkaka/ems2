-- Opsi A dari docs/AI_ASSISTANT_MODULE.md §6.3 — tambah extracted_text +
-- extraction_status ke tabel lampiran yang genuinely bisa berisi PDF/DOC/
-- DOCX (bukan cuma foto), supaya isinya ikut FULLTEXT-searchable & bisa
-- dibaca chat bot AI nanti. Juga dibuat defensif lewat
-- ems_attachment_ensure_extraction_columns() di config/attachment_extraction.php.
--
-- 4 tabel utama (benar-benar menerima dokumen berteks):
--   secretary_file_record_attachments, meeting_minutes_attachments,
--   disciplinary_case_attachments, disciplinary_warning_letter_attachments
-- 3 tabel Secretary lain ikut ditambah kolomnya juga demi keseragaman
-- (dipakai fungsi upload generic yang sama), tapi secara data akan selalu
-- 'unsupported' karena field-nya cuma terima jpg/png:
--   secretary_visit_agenda_attachments, secretary_internal_coordination_attachments,
--   secretary_confidential_letter_attachments

ALTER TABLE `secretary_file_record_attachments`
    ADD COLUMN `extracted_text` MEDIUMTEXT DEFAULT NULL AFTER `file_name`,
    ADD COLUMN `extraction_status` ENUM('pending','done','unsupported','failed') NOT NULL DEFAULT 'pending' AFTER `extracted_text`;

ALTER TABLE `secretary_visit_agenda_attachments`
    ADD COLUMN `extracted_text` MEDIUMTEXT DEFAULT NULL AFTER `file_name`,
    ADD COLUMN `extraction_status` ENUM('pending','done','unsupported','failed') NOT NULL DEFAULT 'pending' AFTER `extracted_text`;

ALTER TABLE `secretary_internal_coordination_attachments`
    ADD COLUMN `extracted_text` MEDIUMTEXT DEFAULT NULL AFTER `file_name`,
    ADD COLUMN `extraction_status` ENUM('pending','done','unsupported','failed') NOT NULL DEFAULT 'pending' AFTER `extracted_text`;

ALTER TABLE `secretary_confidential_letter_attachments`
    ADD COLUMN `extracted_text` MEDIUMTEXT DEFAULT NULL AFTER `file_name`,
    ADD COLUMN `extraction_status` ENUM('pending','done','unsupported','failed') NOT NULL DEFAULT 'pending' AFTER `extracted_text`;

ALTER TABLE `meeting_minutes_attachments`
    ADD COLUMN `extracted_text` MEDIUMTEXT DEFAULT NULL AFTER `file_name`,
    ADD COLUMN `extraction_status` ENUM('pending','done','unsupported','failed') NOT NULL DEFAULT 'pending' AFTER `extracted_text`;

ALTER TABLE `disciplinary_case_attachments`
    ADD COLUMN `extracted_text` MEDIUMTEXT DEFAULT NULL AFTER `file_path`,
    ADD COLUMN `extraction_status` ENUM('pending','done','unsupported','failed') NOT NULL DEFAULT 'pending' AFTER `extracted_text`;

ALTER TABLE `disciplinary_warning_letter_attachments`
    ADD COLUMN `extracted_text` MEDIUMTEXT DEFAULT NULL AFTER `file_path`,
    ADD COLUMN `extraction_status` ENUM('pending','done','unsupported','failed') NOT NULL DEFAULT 'pending' AFTER `extracted_text`;
