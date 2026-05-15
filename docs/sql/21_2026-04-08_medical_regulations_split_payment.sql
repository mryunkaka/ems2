START TRANSACTION;

SET @sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'medical_regulations'
              AND COLUMN_NAME = 'cash_amount'
        ),
        'SELECT 1',
        'ALTER TABLE `medical_regulations` ADD COLUMN `cash_amount` INT NOT NULL DEFAULT 0 AFTER `price_max`'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'medical_regulations'
              AND COLUMN_NAME = 'billing_amount'
        ),
        'SELECT 1',
        'ALTER TABLE `medical_regulations` ADD COLUMN `billing_amount` INT NOT NULL DEFAULT 0 AFTER `cash_amount`'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `medical_regulations`
SET
    `cash_amount` = CASE
        WHEN UPPER(COALESCE(`payment_type`, 'CASH')) = 'CASH' THEN COALESCE(`price_min`, 0)
        ELSE 0
    END,
    `billing_amount` = CASE
        WHEN UPPER(COALESCE(`payment_type`, 'CASH')) IN ('BILLING', 'INVOICE') THEN COALESCE(`price_min`, 0)
        ELSE 0
    END
WHERE
    COALESCE(`price_min`, 0) > 0
    AND COALESCE(`cash_amount`, 0) = 0
    AND COALESCE(`billing_amount`, 0) = 0;

COMMIT;
