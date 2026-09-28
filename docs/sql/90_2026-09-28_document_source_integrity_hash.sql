-- Track the physical document version used for extracted_text so Roxy can
-- refresh stale search evidence before answering policy/SOP questions.
SET @has_source_hash = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'document_files'
      AND COLUMN_NAME = 'source_file_sha256'
);
SET @sql = IF(
    @has_source_hash = 0,
    'ALTER TABLE document_files ADD COLUMN source_file_sha256 CHAR(64) NULL AFTER file_size_bytes',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
