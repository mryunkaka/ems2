START TRANSACTION;

SET @sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'medical_regulations'
              AND COLUMN_NAME = 'cash_max_amount'
        ),
        'SELECT 1',
        'ALTER TABLE `medical_regulations` ADD COLUMN `cash_max_amount` INT NOT NULL DEFAULT 0 AFTER `cash_amount`'
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
              AND COLUMN_NAME = 'billing_max_amount'
        ),
        'SELECT 1',
        'ALTER TABLE `medical_regulations` ADD COLUMN `billing_max_amount` INT NOT NULL DEFAULT 0 AFTER `billing_amount`'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `medical_regulations`
SET
    `cash_max_amount` = CASE
        WHEN UPPER(COALESCE(`payment_type`, 'CASH')) = 'CASH' THEN COALESCE(`price_max`, 0)
        ELSE 0
    END,
    `billing_max_amount` = CASE
        WHEN UPPER(COALESCE(`payment_type`, 'CASH')) IN ('BILLING', 'INVOICE') THEN COALESCE(`price_max`, 0)
        ELSE 0
    END
WHERE
    COALESCE(`price_type`, 'FIXED') = 'RANGE'
    AND COALESCE(`price_max`, 0) > 0
    AND COALESCE(`cash_max_amount`, 0) = 0
    AND COALESCE(`billing_max_amount`, 0) = 0;

COMMIT;
