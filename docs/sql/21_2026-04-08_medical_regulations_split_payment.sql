START TRANSACTION;

ALTER TABLE `medical_regulations`
    ADD COLUMN IF NOT EXISTS `cash_amount` INT NOT NULL DEFAULT 0 AFTER `price_max`,
    ADD COLUMN IF NOT EXISTS `billing_amount` INT NOT NULL DEFAULT 0 AFTER `cash_amount`;

COMMIT;
