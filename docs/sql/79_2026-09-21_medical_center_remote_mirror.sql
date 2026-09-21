-- Local Roxwood mirror for Medical Center GET-only records.
-- Medical Center remains source of input/edit/delete.
-- Idempotent column/index additions.

SET @db_name = DATABASE();

SET @sql = IF(
    EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'medical_records' AND COLUMN_NAME = 'source_provider'),
    'SELECT 1',
    'ALTER TABLE medical_records ADD COLUMN source_provider VARCHAR(50) NULL AFTER id, ADD COLUMN source_hospital VARCHAR(50) NULL AFTER source_provider, ADD COLUMN remote_record_id VARCHAR(100) NULL AFTER source_hospital, ADD COLUMN remote_record_url VARCHAR(255) NULL AFTER remote_record_id, ADD COLUMN remote_event_at DATETIME NULL AFTER remote_record_url, ADD COLUMN remote_created_at DATETIME NULL AFTER remote_event_at, ADD COLUMN remote_updated_at DATETIME NULL AFTER remote_created_at, ADD COLUMN remote_sync_state ENUM(''synced'', ''stale'', ''needs_review'') NULL AFTER remote_updated_at, ADD COLUMN remote_last_pulled_at DATETIME NULL AFTER remote_sync_state, ADD COLUMN remote_last_error TEXT NULL AFTER remote_last_pulled_at, ADD COLUMN remote_payload_json LONGTEXT NULL AFTER remote_last_error, ADD COLUMN remote_medical_details_json LONGTEXT NULL AFTER remote_payload_json, ADD COLUMN remote_team_json LONGTEXT NULL AFTER remote_medical_details_json, ADD COLUMN remote_photos_json LONGTEXT NULL AFTER remote_team_json'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'medical_records' AND COLUMN_NAME = 'patient_blood_type'),
    'SELECT 1',
    'ALTER TABLE medical_records ADD COLUMN patient_blood_type VARCHAR(20) NULL AFTER patient_gender, ADD COLUMN remote_record_number VARCHAR(100) NULL AFTER patient_blood_type'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'medical_records' AND COLUMN_NAME = 'remote_record_number'),
    'SELECT 1',
    'ALTER TABLE medical_records ADD COLUMN remote_record_number VARCHAR(100) NULL AFTER patient_blood_type'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'medical_records' AND COLUMN_NAME = 'remote_dpjp_json'),
    'SELECT 1',
    'ALTER TABLE medical_records ADD COLUMN remote_dpjp_json LONGTEXT NULL AFTER remote_team_json, ADD COLUMN remote_assistants_json LONGTEXT NULL AFTER remote_dpjp_json'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'medical_records' AND COLUMN_NAME = 'remote_location'),
    'SELECT 1',
    'ALTER TABLE medical_records ADD COLUMN remote_location VARCHAR(255) NULL AFTER patient_status, ADD COLUMN remote_diagnosis TEXT NULL AFTER remote_location, ADD COLUMN remote_anamnesis LONGTEXT NULL AFTER remote_diagnosis, ADD COLUMN remote_past_medical_history TEXT NULL AFTER remote_anamnesis, ADD COLUMN remote_family_history TEXT NULL AFTER remote_past_medical_history, ADD COLUMN remote_allergy_history TEXT NULL AFTER remote_family_history, ADD COLUMN remote_medication_history TEXT NULL AFTER remote_allergy_history, ADD COLUMN remote_general_condition VARCHAR(255) NULL AFTER remote_medication_history, ADD COLUMN remote_gcs VARCHAR(100) NULL AFTER remote_general_condition, ADD COLUMN remote_blood_pressure VARCHAR(100) NULL AFTER remote_gcs, ADD COLUMN remote_pulse VARCHAR(100) NULL AFTER remote_blood_pressure, ADD COLUMN remote_respiratory_rate VARCHAR(100) NULL AFTER remote_pulse, ADD COLUMN remote_temperature VARCHAR(100) NULL AFTER remote_respiratory_rate, ADD COLUMN remote_oxygen_saturation VARCHAR(100) NULL AFTER remote_temperature'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'medical_records' AND COLUMN_NAME = 'remote_operation_name'),
    'SELECT 1',
    'ALTER TABLE medical_records ADD COLUMN remote_operation_name VARCHAR(500) NULL AFTER remote_oxygen_saturation, ADD COLUMN remote_operation_start_at VARCHAR(100) NULL AFTER remote_operation_name, ADD COLUMN remote_operation_end_at VARCHAR(100) NULL AFTER remote_operation_start_at, ADD COLUMN remote_operation_steps LONGTEXT NULL AFTER remote_operation_end_at, ADD COLUMN remote_operation_result LONGTEXT NULL AFTER remote_operation_steps, ADD COLUMN remote_anesthesia_type VARCHAR(255) NULL AFTER remote_operation_result, ADD COLUMN remote_anesthesia_officer VARCHAR(255) NULL AFTER remote_anesthesia_type, ADD COLUMN remote_preop_medications LONGTEXT NULL AFTER remote_anesthesia_officer, ADD COLUMN remote_postop_medications LONGTEXT NULL AFTER remote_preop_medications, ADD COLUMN remote_laboratory_result LONGTEXT NULL AFTER remote_postop_medications, ADD COLUMN remote_radiology_result LONGTEXT NULL AFTER remote_laboratory_result, ADD COLUMN remote_postop_advice LONGTEXT NULL AFTER remote_radiology_result, ADD COLUMN remote_aldrete LONGTEXT NULL AFTER remote_postop_advice'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE medical_records
    MODIFY patient_dob DATE NULL,
    MODIFY patient_gender ENUM('Laki-laki', 'Perempuan') NULL,
    MODIFY ktp_file_path VARCHAR(255) NULL,
    MODIFY doctor_id INT NULL,
    MODIFY created_by INT NULL;

SET @sql = IF(
    EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'medical_records' AND INDEX_NAME = 'uq_medical_records_remote_identity'),
    'SELECT 1',
    'ALTER TABLE medical_records ADD UNIQUE KEY uq_medical_records_remote_identity (source_provider, source_hospital, remote_record_id)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(
    EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'medical_records' AND INDEX_NAME = 'idx_medical_records_remote_event'),
    'SELECT 1',
    'ALTER TABLE medical_records ADD KEY idx_medical_records_remote_event (remote_event_at), ADD KEY idx_medical_records_remote_sync (remote_sync_state)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
