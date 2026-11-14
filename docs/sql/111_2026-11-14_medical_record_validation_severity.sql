ALTER TABLE `medical_record_validation_findings`
  ADD COLUMN `severity` ENUM('administrative','review','risk') NOT NULL DEFAULT 'administrative' AFTER `source`;
