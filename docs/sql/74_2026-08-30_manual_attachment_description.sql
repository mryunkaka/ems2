-- Untuk lampiran yang murni foto (Surat Keluar, Surat Masuk/surat_instansi
-- publik, Surat Rahasia, Agenda Kunjungan, Koordinasi Internal — semua
-- accept="image/*" saja, tidak bisa diekstrak otomatis), uploader bisa isi
-- deskripsi/ringkasan manual supaya tetap searchable & bisa jadi basis
-- pengetahuan chat bot AI nanti. Nilai enum baru 'manual' menandai baris
-- yang isinya dari input manusia, bukan hasil ekstraksi otomatis.
-- Juga dibuat defensif lewat ems_attachment_ensure_extraction_columns()
-- di config/attachment_extraction.php.

ALTER TABLE `secretary_file_record_attachments`
    MODIFY COLUMN `extraction_status` ENUM('pending','done','unsupported','failed','manual') NOT NULL DEFAULT 'pending';

ALTER TABLE `secretary_visit_agenda_attachments`
    MODIFY COLUMN `extraction_status` ENUM('pending','done','unsupported','failed','manual') NOT NULL DEFAULT 'pending';

ALTER TABLE `secretary_internal_coordination_attachments`
    MODIFY COLUMN `extraction_status` ENUM('pending','done','unsupported','failed','manual') NOT NULL DEFAULT 'pending';

ALTER TABLE `secretary_confidential_letter_attachments`
    MODIFY COLUMN `extraction_status` ENUM('pending','done','unsupported','failed','manual') NOT NULL DEFAULT 'pending';

ALTER TABLE `meeting_minutes_attachments`
    MODIFY COLUMN `extraction_status` ENUM('pending','done','unsupported','failed','manual') NOT NULL DEFAULT 'pending';

ALTER TABLE `disciplinary_case_attachments`
    MODIFY COLUMN `extraction_status` ENUM('pending','done','unsupported','failed','manual') NOT NULL DEFAULT 'pending';

ALTER TABLE `disciplinary_warning_letter_attachments`
    MODIFY COLUMN `extraction_status` ENUM('pending','done','unsupported','failed','manual') NOT NULL DEFAULT 'pending';

ALTER TABLE `outgoing_letter_attachments`
    ADD COLUMN `extracted_text` MEDIUMTEXT DEFAULT NULL AFTER `file_name`,
    ADD COLUMN `extraction_status` ENUM('pending','done','unsupported','failed','manual') NOT NULL DEFAULT 'pending' AFTER `extracted_text`;

ALTER TABLE `incoming_letter_attachments`
    ADD COLUMN `extracted_text` MEDIUMTEXT DEFAULT NULL AFTER `file_name`,
    ADD COLUMN `extraction_status` ENUM('pending','done','unsupported','failed','manual') NOT NULL DEFAULT 'pending' AFTER `extracted_text`;
