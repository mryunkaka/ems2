-- EMS2 - Surat Masuk, Surat Keluar, dan Notulen Pertemuan
-- Date: 2026-03-07

CREATE TABLE IF NOT EXISTS incoming_letters (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  letter_code VARCHAR(32) NOT NULL,
  institution_name VARCHAR(160) NOT NULL,
  sender_name VARCHAR(160) NOT NULL,
  sender_phone VARCHAR(64) NOT NULL,
  meeting_topic VARCHAR(255) NOT NULL,
  appointment_date DATE NOT NULL,
  appointment_time TIME NOT NULL,
  target_user_id BIGINT UNSIGNED NOT NULL,
  target_name_snapshot VARCHAR(160) NOT NULL,
  target_role_snapshot VARCHAR(64) NOT NULL,
  notes TEXT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'unread', -- unread|read|closed
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_by BIGINT UNSIGNED NULL,
  read_at DATETIME NULL,
  created_ip VARCHAR(64) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_incoming_letter_code (letter_code),
  KEY idx_incoming_status_submitted (status, submitted_at),
  KEY idx_incoming_target_status (target_user_id, status),
  KEY idx_incoming_read_by (read_by)
);

CREATE TABLE IF NOT EXISTS outgoing_letters (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  outgoing_code VARCHAR(32) NOT NULL,
  incoming_letter_id BIGINT UNSIGNED NULL,
  institution_name VARCHAR(160) NOT NULL,
  recipient_name VARCHAR(160) NULL,
  recipient_contact VARCHAR(64) NULL,
  subject VARCHAR(255) NOT NULL,
  letter_body TEXT NOT NULL,
  appointment_date DATE NULL,
  appointment_time TIME NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_outgoing_code (outgoing_code),
  KEY idx_outgoing_created_at (created_at),
  KEY idx_outgoing_incoming_letter (incoming_letter_id),
  KEY idx_outgoing_created_by (created_by)
);

CREATE TABLE IF NOT EXISTS meeting_minutes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  incoming_letter_id BIGINT UNSIGNED NULL,
  outgoing_letter_id BIGINT UNSIGNED NULL,
  meeting_title VARCHAR(255) NOT NULL,
  meeting_date DATE NOT NULL,
  meeting_time TIME NOT NULL,
  participants TEXT NOT NULL,
  summary TEXT NOT NULL,
  decisions TEXT NULL,
  follow_up TEXT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_minutes_meeting_date (meeting_date, meeting_time),
  KEY idx_minutes_incoming_letter (incoming_letter_id),
  KEY idx_minutes_outgoing_letter (outgoing_letter_id),
  KEY idx_minutes_created_by (created_by)
);
