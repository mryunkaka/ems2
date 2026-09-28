-- Gelar spesialis ditetapkan pemilik profil (dapat berisi beberapa gelar).
SET @has_specialist_degrees = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'user_rh'
      AND COLUMN_NAME = 'specialist_degrees'
);
SET @sql = IF(
    @has_specialist_degrees = 0,
    'ALTER TABLE user_rh ADD COLUMN specialist_degrees VARCHAR(255) NULL AFTER position',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
