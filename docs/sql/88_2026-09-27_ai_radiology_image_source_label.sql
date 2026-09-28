-- Tandai dengan jelas apakah output radiologi merupakan skema terarah atau ilustrasi AI.
SET @has_image_source_label = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ai_radiology_images'
      AND COLUMN_NAME = 'image_source_label'
);
SET @sql = IF(
    @has_image_source_label = 0,
    'ALTER TABLE ai_radiology_images ADD COLUMN image_source_label VARCHAR(120) NULL AFTER image_path',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE ai_radiology_images
SET image_source_label = 'Ilustrasi AI generatif lama (simulasi; bukan radiograf tervalidasi)'
WHERE image_source_label IS NULL
  AND image_path IS NOT NULL;
