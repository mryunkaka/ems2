-- EMS2 - Persyaratan Jabatan Complete Update
-- Date: 2026-04-27
-- Notes: Update seed data based on Kebijakan Kewenangan Medis Roxwood Hospital
-- Order: Add columns first, then update data

-- Step 1: Add dpjp_minor and dpjp_major columns (if not exists)
-- Note: If columns already exist, this will error - skip ALTER TABLE if needed
-- ALTER TABLE position_promotion_requirements
-- ADD COLUMN dpjp_minor TINYINT(1) UNSIGNED NULL DEFAULT 0 AFTER min_operations_minor,
-- ADD COLUMN dpjp_major TINYINT(1) UNSIGNED NULL DEFAULT 0 AFTER min_operations_major;

-- Step 2: Update seed data based on Kebijakan Kewenangan Medis
-- Paramedic → Co-ass: Minimal 3 asistensi operasi (any type), wajib Sertifikat Medical Class
UPDATE position_promotion_requirements
SET
    required_documents = 'sertifikat_medical_class',
    operation_type = 'any',
    operation_role = 'assistant',
    min_operations = 3,
    min_operations_minor = NULL,
    min_operations_major = NULL
WHERE from_position = 'paramedic' AND to_position = 'co_asst';

-- Step 3: Co-ass → General Practitioner: 3 DPJP minor + 2 asisten mayor, wajib sertifikasi operasi
UPDATE position_promotion_requirements
SET
    required_documents = 'sertifikat_operasi_minor,sertifikat_operasi_plastik_basic,sertifikat_medical_class',
    operation_type = 'any',
    operation_role = 'any',
    min_operations = NULL,
    min_operations_minor = 3,
    min_operations_major = 2
WHERE from_position = 'co_asst' AND to_position = 'general_practitioner';

-- Step 4: Update DPJP requirements
-- Paramedic → Co-ass: 3 operations as assistant (not DPJP)
UPDATE position_promotion_requirements
SET
    dpjp_minor = 0,
    dpjp_major = 0
WHERE from_position = 'paramedic' AND to_position = 'co_asst';

-- Step 5: Co-ass → General Practitioner: 3 DPJP minor + 2 assistant mayor
UPDATE position_promotion_requirements
SET
    dpjp_minor = 1,
    dpjp_major = 0
WHERE from_position = 'co_asst' AND to_position = 'general_practitioner';
