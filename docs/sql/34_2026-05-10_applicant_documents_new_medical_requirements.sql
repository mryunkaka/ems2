START TRANSACTION;

SET @schema_name = DATABASE();

SET @document_type_definition = (
    SELECT COLUMN_TYPE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'applicant_documents'
      AND COLUMN_NAME = 'document_type'
    LIMIT 1
);

SET @needs_document_type_update = (
    @document_type_definition IS NOT NULL
    AND (
        @document_type_definition NOT LIKE '%''kta''%'
        OR @document_type_definition NOT LIKE '%''surat_keterangan_sehat''%'
        OR @document_type_definition NOT LIKE '%''surat_keterangan_psikolog''%'
    )
);

SET @sql_update_document_type = IF(
    @needs_document_type_update,
    "ALTER TABLE `applicant_documents`
        MODIFY COLUMN `document_type` ENUM(
            'ktp_ic',
            'skb',
            'sim',
            'kta',
            'surat_keterangan_sehat',
            'surat_keterangan_psikolog'
        ) NOT NULL",
    "SELECT 1"
);

PREPARE stmt FROM @sql_update_document_type;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
