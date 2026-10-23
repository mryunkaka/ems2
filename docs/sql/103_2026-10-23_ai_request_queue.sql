-- Antrean persisten untuk menahan pemanggilan model AI agar tidak berjalan serentak tanpa batas.
SET @has_ai_max_concurrent_requests = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_ai_settings' AND COLUMN_NAME = 'ai_max_concurrent_requests'
);
SET @sql = IF(@has_ai_max_concurrent_requests = 0,
    'ALTER TABLE system_ai_settings ADD COLUMN ai_max_concurrent_requests TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER daily_request_limit',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_ai_max_queue_size = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_ai_settings' AND COLUMN_NAME = 'ai_max_queue_size'
);
SET @sql = IF(@has_ai_max_queue_size = 0,
    'ALTER TABLE system_ai_settings ADD COLUMN ai_max_queue_size SMALLINT UNSIGNED NOT NULL DEFAULT 50 AFTER ai_max_concurrent_requests',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `system_ai_request_queue` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `job_type` VARCHAR(80) NOT NULL,
    `requester_id` INT NOT NULL,
    `feature_label` VARCHAR(150) NOT NULL,
    `references_json` MEDIUMTEXT NOT NULL,
    `result_json` LONGTEXT NULL,
    `status` ENUM('queued', 'running', 'completed', 'failed') NOT NULL DEFAULT 'queued',
    `dedupe_key` CHAR(64) NOT NULL,
    `active_dedupe_key` CHAR(64) NULL,
    `error_message` TEXT NULL,
    `worker_slot` TINYINT UNSIGNED NULL,
    `queued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `started_at` DATETIME NULL,
    `completed_at` DATETIME NULL,
    `lease_expires_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ai_queue_active_dedupe` (`active_dedupe_key`),
    KEY `idx_ai_queue_fifo` (`status`, `queued_at`, `id`),
    KEY `idx_ai_queue_requester_status` (`requester_id`, `status`),
    KEY `idx_ai_queue_lease` (`status`, `lease_expires_at`),
    KEY `idx_ai_queue_retention` (`status`, `completed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Baris pengaturan AI dikunci saat klaim sehingga beberapa cron tidak dapat
-- melewati pemeriksaan batas konkurensi secara bersamaan.
