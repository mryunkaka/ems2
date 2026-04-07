-- EMS2
-- Format baru kode surat masuk, surat keluar, dan notulen
-- Date: 2026-03-25
--
-- Format final:
-- (nomor urut)/(jenis surat)-(singkatan instansi)/RH/(bulan romawi)/(tahun)
-- Contoh:
-- 001/SM-GOV/RH/III/2026
-- 001/SK-DOJ/RH/III/2026
-- 001/NOT-SR/RH/III/2026

START TRANSACTION;

SET @db_name := DATABASE();

-- meeting_minutes.minutes_code
SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'meeting_minutes'
              AND COLUMN_NAME = 'minutes_code'
        ),
        'SELECT 1',
        "ALTER TABLE meeting_minutes ADD COLUMN minutes_code VARCHAR(32) NULL AFTER outgoing_letter_id"
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'meeting_minutes'
              AND INDEX_NAME = 'uniq_minutes_code'
        ),
        'SELECT 1',
        'ALTER TABLE meeting_minutes ADD UNIQUE KEY uniq_minutes_code (minutes_code)'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill data existing per snapshot database saat file ini dibuat.
UPDATE incoming_letters
SET letter_code = '001/SM-DOJ/RH/III/2026'
WHERE id = 1;

UPDATE incoming_letters
SET letter_code = '002/SM-GOV/RH/III/2026'
WHERE id = 2;

UPDATE incoming_letters
SET letter_code = '003/SM-TES/RH/III/2026'
WHERE id = 3;

UPDATE outgoing_letters
SET outgoing_code = '001/SK-RSAC/RH/III/2026'
WHERE id = 2;

UPDATE meeting_minutes
SET minutes_code = '001/NOT-DOJ/RH/III/2026'
WHERE id = 1;

UPDATE meeting_minutes
SET minutes_code = '002/NOT-SR/RH/III/2026'
WHERE id = 2;

UPDATE meeting_minutes
SET minutes_code = '003/NOT-SR/RH/III/2026'
WHERE id = 3;

UPDATE meeting_minutes
SET minutes_code = '004/NOT-SR/RH/III/2026'
WHERE id = 4;

UPDATE meeting_minutes
SET minutes_code = '005/NOT-SR/RH/III/2026'
WHERE id = 5;

SET @sql := (
    SELECT IF(
        EXISTS(
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db_name
              AND TABLE_NAME = 'meeting_minutes'
              AND COLUMN_NAME = 'minutes_code'
              AND IS_NULLABLE = 'YES'
        ),
        'ALTER TABLE meeting_minutes MODIFY COLUMN minutes_code VARCHAR(32) NOT NULL',
        'SELECT 1'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
