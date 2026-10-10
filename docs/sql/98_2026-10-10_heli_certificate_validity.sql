-- Sertifikat heli berlaku berdasarkan rentang tanggal yang tampil di Setting Akun.
SET @has_heli_valid_from = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_rh' AND COLUMN_NAME = 'sertifikat_heli_valid_from'
);
SET @sql = IF(@has_heli_valid_from = 0,
    'ALTER TABLE user_rh ADD COLUMN sertifikat_heli_valid_from DATE NULL AFTER tanggal_dikeluarkan_sertifikat_heli',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_heli_valid_until = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_rh' AND COLUMN_NAME = 'sertifikat_heli_valid_until'
);
SET @sql = IF(@has_heli_valid_until = 0,
    'ALTER TABLE user_rh ADD COLUMN sertifikat_heli_valid_until DATE NULL AFTER sertifikat_heli_valid_from',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Masa berlaku awal yang diminta: 10 sampai 31 Oktober 2026 untuk sertifikat yang sudah terunggah.
UPDATE user_rh
SET sertifikat_heli_valid_from = '2026-10-10',
    sertifikat_heli_valid_until = '2026-10-31'
WHERE TRIM(COALESCE(sertifikat_heli, '')) <> ''
  AND (sertifikat_heli_valid_from IS NULL OR sertifikat_heli_valid_until IS NULL);

-- Teks apresiasi ulang tahun masa kerja dibuat sekali dan dipakai oleh semua pengguna.
CREATE TABLE IF NOT EXISTS work_anniversary_greetings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    anniversary_year SMALLINT NOT NULL,
    greeting TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_work_anniversary_user_year (user_id, anniversary_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
