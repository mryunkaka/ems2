-- EMS2 - Canonicalize user_rh.position values
-- Date: 2026-03-07
-- Run once (optional). App-side also normalizes for safety.

UPDATE user_rh
SET position = 'trainee'
WHERE LOWER(TRIM(position)) IN ('trainee');

UPDATE user_rh
SET position = 'paramedic'
WHERE LOWER(TRIM(position)) IN ('paramedic');

UPDATE user_rh
SET position = 'co_asst'
WHERE LOWER(TRIM(position)) IN ('(co.ast)','(co. ast)','co.ast','co. ast','co asst','co. asst','co-ass','co_asst','coasst');

UPDATE user_rh
SET position = 'general_practitioner'
WHERE LOWER(TRIM(position)) IN ('dokter umum','dr umum','general practitioner','gp');

UPDATE user_rh
SET position = 'specialist'
WHERE LOWER(TRIM(position)) IN ('dokter spesialis','dr spesialis','specialist','specialist doctor');

