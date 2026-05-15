START TRANSACTION;

SET @sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'medical_records'
              AND COLUMN_NAME = 'jenis_operasi'
        ),
        'SELECT 1',
        'ALTER TABLE `medical_records` ADD COLUMN `jenis_operasi` VARCHAR(255) NULL AFTER `operasi_type`'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `medical_records`
SET `jenis_operasi` = CASE
    WHEN `operasi_type` = 'major' THEN 'Operasi Mayor'
    ELSE 'Operasi Minor'
END
WHERE COALESCE(TRIM(`jenis_operasi`), '') = '';

COMMIT;
