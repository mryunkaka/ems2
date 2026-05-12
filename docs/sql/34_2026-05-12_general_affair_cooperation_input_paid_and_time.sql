ALTER TABLE `secretary_file_records`
  MODIFY `status` ENUM('draft','review','active','paid','archived') NOT NULL DEFAULT 'draft',
  ADD COLUMN `document_time` TIME NULL AFTER `document_date`,
  ADD COLUMN `paid_by` INT NULL AFTER `updated_by`,
  ADD COLUMN `paid_at` DATETIME NULL AFTER `updated_at`,
  ADD KEY `idx_secretary_file_records_paid_by` (`paid_by`),
  ADD KEY `idx_secretary_file_records_paid_at` (`paid_at`),
  ADD CONSTRAINT `fk_secretary_file_records_paid_by`
    FOREIGN KEY (`paid_by`) REFERENCES `user_rh` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL;

UPDATE `secretary_file_records`
SET `document_time` = '00:00:00'
WHERE `document_time` IS NULL;
