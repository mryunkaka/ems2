-- EMS2 - Pengajuan Kenaikan Jabatan
-- Date: 2026-03-07
-- Notes:
-- - Simpan jabatan menggunakan value canonical: trainee, paramedic, co_asst, general_practitioner, specialist
-- - Join date memakai kolom user_rh.tanggal_masuk

CREATE TABLE IF NOT EXISTS position_promotion_requirements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  from_position VARCHAR(64) NOT NULL,
  to_position   VARCHAR(64) NOT NULL,
  min_days_since_join INT NULL,
  min_operations      INT NULL,
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_transition (from_position, to_position)
);

CREATE TABLE IF NOT EXISTS position_promotion_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  from_position VARCHAR(64) NOT NULL,
  to_position   VARCHAR(64) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending', -- pending|approved|rejected|canceled
  requirement_notes_snapshot TEXT NULL,
  min_days_since_join_snapshot INT NULL,
  min_operations_snapshot INT NULL,
  join_date_snapshot DATE NULL,
  batch_snapshot INT NULL,
  duty_note_snapshot TEXT NULL,
  case_title VARCHAR(255) NULL,
  case_subject TEXT NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewer_note TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_user_status (user_id, status),
  KEY idx_status_submitted (status, submitted_at)
);

CREATE TABLE IF NOT EXISTS position_promotion_request_operations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id BIGINT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 1,
  patient_name VARCHAR(160) NOT NULL,
  procedure_name VARCHAR(160) NOT NULL,
  dpjp VARCHAR(160) NOT NULL,
  operation_role VARCHAR(32) NULL,  -- assistant|dpjp
  operation_level VARCHAR(16) NULL, -- minor|major
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_request (request_id),
  CONSTRAINT fk_promo_ops_req
    FOREIGN KEY (request_id) REFERENCES position_promotion_requests(id)
    ON DELETE CASCADE
);

-- Seed default requirements (manager bisa ubah via UI)
INSERT INTO position_promotion_requirements
  (from_position, to_position, min_days_since_join, min_operations, notes)
VALUES
  ('trainee', 'paramedic', 7, NULL, 'Syarat: sudah join minimal 7 hari. Catatan: aktif 10 jam duty (absensi web berbeda, hanya keterangan).'),
  ('paramedic', 'co_asst', NULL, 3, 'Syarat: telah melakukan 3x operasi sebagai asisten operasi Minor atau Mayor.'),
  ('co_asst', 'general_practitioner', NULL, 5, 'Syarat: telah melakukan 5x operasi sebagai DPJP operasi Minor atau asisten operasi Mayor + wajib Laporan Kasus.'),
  ('general_practitioner', 'specialist', NULL, NULL, 'Menyusul.')
ON DUPLICATE KEY UPDATE
  min_days_since_join = VALUES(min_days_since_join),
  min_operations = VALUES(min_operations),
  notes = VALUES(notes),
  is_active = 1;
