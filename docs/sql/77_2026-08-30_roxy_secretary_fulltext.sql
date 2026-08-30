-- Retrieval Roxy (docs/AI_ASSISTANT_MODULE.md §6.3/§7.1) butuh FULLTEXT di
-- extracted_text 6 tabel lampiran Secretary/Surat yang di-whitelist —
-- kolomnya sudah ada dari migrasi 73/74, tapi belum ada index FULLTEXT
-- (dicek langsung lewat SHOW INDEX, bukan diasumsikan). Tanpa ini, retrieval
-- Roxy dari sumber-sumber ini harus full-scan LIKE terus-menerus, tidak
-- konsisten dengan pola FULLTEXT-first yang sudah dipakai di document_files
-- dan bot_knowledge_base.
--
-- secretary_confidential_letter_attachments dan
-- disciplinary_case_attachments/disciplinary_warning_letter_attachments
-- SENGAJA TIDAK disertakan di sini — bukan sumber Roxy sama sekali
-- (lihat whitelist final §6.3), tidak perlu FULLTEXT untuk kebutuhan ini
-- (pencarian internal aplikasi di halaman masing-masing sudah pakai LIKE
-- dan itu cukup untuk skala datanya).

ALTER TABLE `secretary_file_record_attachments` ADD FULLTEXT KEY `ftx_secretary_file_record_attachments_text` (`extracted_text`);
ALTER TABLE `meeting_minutes_attachments` ADD FULLTEXT KEY `ftx_meeting_minutes_attachments_text` (`extracted_text`);
ALTER TABLE `incoming_letter_attachments` ADD FULLTEXT KEY `ftx_incoming_letter_attachments_text` (`extracted_text`);
ALTER TABLE `outgoing_letter_attachments` ADD FULLTEXT KEY `ftx_outgoing_letter_attachments_text` (`extracted_text`);
ALTER TABLE `secretary_visit_agenda_attachments` ADD FULLTEXT KEY `ftx_secretary_visit_agenda_attachments_text` (`extracted_text`);
ALTER TABLE `secretary_internal_coordination_attachments` ADD FULLTEXT KEY `ftx_secretary_internal_coordination_attachments_text` (`extracted_text`);
