-- EMS2
-- Tambahan scope divisi dan metadata revisi untuk surat/notulen
-- Date: 2026-03-17

SET @db_name := DATABASE();

-- incoming_letters.division_scope
SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'incoming_letters'
              AND COLUMN_NAME = 'division_scope'
        ),
        'SELECT 1',
        "ALTER TABLE incoming_letters ADD COLUMN division_scope VARCHAR(64) NOT NULL DEFAULT 'All Divisi' AFTER appointment_time"
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'incoming_letters'
              AND INDEX_NAME = 'idx_incoming_division_scope'
        ),
        'SELECT 1',
        'ALTER TABLE incoming_letters ADD KEY idx_incoming_division_scope (division_scope)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- outgoing_letters.division_scope
SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'outgoing_letters'
              AND COLUMN_NAME = 'division_scope'
        ),
        'SELECT 1',
        "ALTER TABLE outgoing_letters ADD COLUMN division_scope VARCHAR(64) NOT NULL DEFAULT 'All Divisi' AFTER appointment_time"
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- outgoing_letters.revision_count
SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'outgoing_letters'
              AND COLUMN_NAME = 'revision_count'
        ),
        'SELECT 1',
        'ALTER TABLE outgoing_letters ADD COLUMN revision_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER division_scope'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- outgoing_letters.revision_label
SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'outgoing_letters'
              AND COLUMN_NAME = 'revision_label'
        ),
        'SELECT 1',
        'ALTER TABLE outgoing_letters ADD COLUMN revision_label VARCHAR(32) NULL AFTER revision_count'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- outgoing_letters.updated_by
SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'outgoing_letters'
              AND COLUMN_NAME = 'updated_by'
        ),
        'SELECT 1',
        'ALTER TABLE outgoing_letters ADD COLUMN updated_by BIGINT UNSIGNED NULL AFTER created_by'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- outgoing_letters.updated_at
SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'outgoing_letters'
              AND COLUMN_NAME = 'updated_at'
        ),
        'SELECT 1',
        'ALTER TABLE outgoing_letters ADD COLUMN updated_at DATETIME NULL AFTER created_at'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'outgoing_letters'
              AND INDEX_NAME = 'idx_outgoing_division_scope'
        ),
        'SELECT 1',
        'ALTER TABLE outgoing_letters ADD KEY idx_outgoing_division_scope (division_scope)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'outgoing_letters'
              AND INDEX_NAME = 'idx_outgoing_revision_count'
        ),
        'SELECT 1',
        'ALTER TABLE outgoing_letters ADD KEY idx_outgoing_revision_count (revision_count)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'outgoing_letters'
              AND INDEX_NAME = 'idx_outgoing_updated_by'
        ),
        'SELECT 1',
        'ALTER TABLE outgoing_letters ADD KEY idx_outgoing_updated_by (updated_by)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- meeting_minutes.division_scope
SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'meeting_minutes'
              AND COLUMN_NAME = 'division_scope'
        ),
        'SELECT 1',
        "ALTER TABLE meeting_minutes ADD COLUMN division_scope VARCHAR(64) NOT NULL DEFAULT 'All Divisi' AFTER meeting_time"
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- meeting_minutes.revision_count
SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'meeting_minutes'
              AND COLUMN_NAME = 'revision_count'
        ),
        'SELECT 1',
        'ALTER TABLE meeting_minutes ADD COLUMN revision_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER division_scope'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- meeting_minutes.revision_label
SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'meeting_minutes'
              AND COLUMN_NAME = 'revision_label'
        ),
        'SELECT 1',
        'ALTER TABLE meeting_minutes ADD COLUMN revision_label VARCHAR(32) NULL AFTER revision_count'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- meeting_minutes.updated_by
SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'meeting_minutes'
              AND COLUMN_NAME = 'updated_by'
        ),
        'SELECT 1',
        'ALTER TABLE meeting_minutes ADD COLUMN updated_by BIGINT UNSIGNED NULL AFTER created_by'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- meeting_minutes.updated_at
SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'meeting_minutes'
              AND COLUMN_NAME = 'updated_at'
        ),
        'SELECT 1',
        'ALTER TABLE meeting_minutes ADD COLUMN updated_at DATETIME NULL AFTER created_at'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'meeting_minutes'
              AND INDEX_NAME = 'idx_minutes_division_scope'
        ),
        'SELECT 1',
        'ALTER TABLE meeting_minutes ADD KEY idx_minutes_division_scope (division_scope)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'meeting_minutes'
              AND INDEX_NAME = 'idx_minutes_revision_count'
        ),
        'SELECT 1',
        'ALTER TABLE meeting_minutes ADD KEY idx_minutes_revision_count (revision_count)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'meeting_minutes'
              AND INDEX_NAME = 'idx_minutes_updated_by'
        ),
        'SELECT 1',
        'ALTER TABLE meeting_minutes ADD KEY idx_minutes_updated_by (updated_by)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE incoming_letters l
LEFT JOIN user_rh u ON u.id = l.target_user_id
SET l.division_scope = COALESCE(NULLIF(TRIM(u.division), ''), 'All Divisi')
WHERE l.division_scope = 'All Divisi'
  AND u.id IS NOT NULL;

UPDATE outgoing_letters
SET revision_label = NULL
WHERE revision_count = 0
  AND revision_label IS NOT NULL;

UPDATE outgoing_letters
SET revision_label = CONCAT('revisi-', LPAD(revision_count, 2, '0'))
WHERE revision_count > 0
  AND (revision_label IS NULL OR revision_label = '');

UPDATE meeting_minutes
SET revision_label = NULL
WHERE revision_count = 0
  AND revision_label IS NOT NULL;

UPDATE meeting_minutes
SET revision_label = CONCAT('revisi-', LPAD(revision_count, 2, '0'))
WHERE revision_count > 0
  AND (revision_label IS NULL OR revision_label = '');
