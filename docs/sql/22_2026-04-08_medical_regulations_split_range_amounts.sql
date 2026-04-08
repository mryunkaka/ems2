START TRANSACTION;

ALTER TABLE `medical_regulations`
    ADD COLUMN IF NOT EXISTS `cash_max_amount` INT NOT NULL DEFAULT 0 AFTER `cash_amount`,
    ADD COLUMN IF NOT EXISTS `billing_max_amount` INT NOT NULL DEFAULT 0 AFTER `billing_amount`;

COMMIT;
