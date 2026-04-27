-- EMS2 - Add Missing Columns to position_promotion_requirements
-- Date: 2026-04-27
-- Notes: Add columns required for enhanced promotion requirements

ALTER TABLE position_promotion_requirements
ADD COLUMN min_operations_minor INT(11) DEFAULT NULL AFTER min_operations,
ADD COLUMN min_operations_major INT(11) DEFAULT NULL AFTER min_operations_minor,
ADD COLUMN dpjp_minor TINYINT(1) UNSIGNED NULL DEFAULT 0 AFTER min_operations_major,
ADD COLUMN dpjp_major TINYINT(1) UNSIGNED NULL DEFAULT 0 AFTER dpjp_minor,
ADD COLUMN required_documents VARCHAR(255) DEFAULT NULL AFTER dpjp_major,
ADD COLUMN operation_type VARCHAR(32) DEFAULT NULL AFTER required_documents,
ADD COLUMN operation_role VARCHAR(32) DEFAULT NULL AFTER operation_type;
