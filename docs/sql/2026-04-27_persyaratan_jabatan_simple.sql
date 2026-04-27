-- EMS2 - Persyaratan Jabatan Simple Update
-- Date: 2026-04-27
-- Notes: Update data only (no ALTER TABLE)

-- Paramedic → Co-ass: Minimal 3 asistensi operasi (any type), wajib Sertifikat Medical Class
UPDATE position_promotion_requirements
SET
    required_documents = 'sertifikat_medical_class',
    operation_type = 'any',
    operation_role = 'assistant',
    min_operations = 3,
    min_operations_minor = NULL,
    min_operations_major = NULL,
    dpjp_minor = 0,
    dpjp_major = 0
WHERE from_position = 'paramedic' AND to_position = 'co_asst';

-- Co-ass → General Practitioner: 3 DPJP minor + 2 asisten mayor, wajib sertifikasi operasi
UPDATE position_promotion_requirements
SET
    required_documents = 'sertifikat_operasi_minor,sertifikat_operasi_plastik_basic,sertifikat_medical_class',
    operation_type = 'any',
    operation_role = 'any',
    min_operations = NULL,
    min_operations_minor = 3,
    min_operations_major = 2,
    dpjp_minor = 1,
    dpjp_major = 0
WHERE from_position = 'co_asst' AND to_position = 'general_practitioner';
