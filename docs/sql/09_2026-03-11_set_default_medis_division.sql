-- Set default division to Medis for all user_rh except protected management list
-- Also normalize role and division for the listed management users

START TRANSACTION;

UPDATE `user_rh`
SET `division` = 'Medis'
WHERE `full_name` NOT IN (
  'Haruu Ravenscroft',
  'Nicole Clarke Helsing',
  'Panilli Heinrich Cartier',
  'Acid Crus',
  'Snypewell',
  'EURECA EVANTHIA',
  'Orca Ishihara',
  'Kenzo Cleavers',
  'Kuroto Roland',
  'Ryctas Clarke',
  'UDIN LUSIRMAN',
  'Michael Moore',
  'Chocho Xavier',
  'Shinjiro Takahashi',
  'Skurt G',
  'Ken Tungsten',
  'Namuh Kanigara'
);

UPDATE `user_rh`
SET `role` = 'Director',
    `division` = 'Executive'
WHERE `full_name` = 'Haruu Ravenscroft';

UPDATE `user_rh`
SET `role` = 'Vice Director',
    `division` = 'Executive'
WHERE `full_name` = 'Nicole Clarke Helsing';

UPDATE `user_rh`
SET `role` = 'Head Manager',
    `division` = 'Secretary'
WHERE `full_name` = 'Panilli Heinrich Cartier';

UPDATE `user_rh`
SET `role` = 'Head Manager',
    `division` = 'Human Capital'
WHERE `full_name` = 'Acid Crus';

UPDATE `user_rh`
SET `role` = 'Lead Manager',
    `division` = 'Disciplinary Committee'
WHERE `full_name` = 'Snypewell';

UPDATE `user_rh`
SET `role` = 'Assisten Manager',
    `division` = 'Disciplinary Committee'
WHERE `full_name` = 'EURECA EVANTHIA';

UPDATE `user_rh`
SET `role` = 'Assisten Manager',
    `division` = 'Disciplinary Committee'
WHERE `full_name` = 'Orca Ishihara';

UPDATE `user_rh`
SET `role` = 'Lead Manager',
    `division` = 'Human Resource'
WHERE `full_name` = 'Kenzo Cleavers';

UPDATE `user_rh`
SET `role` = 'Assisten Manager',
    `division` = 'Human Resource'
WHERE `full_name` = 'Kuroto Roland';

UPDATE `user_rh`
SET `role` = 'Head Manager',
    `division` = 'General Affair'
WHERE `full_name` = 'Ryctas Clarke';

UPDATE `user_rh`
SET `role` = 'Assisten Manager',
    `division` = 'General Affair'
WHERE `full_name` = 'UDIN LUSIRMAN';

UPDATE `user_rh`
SET `role` = 'Assisten Manager',
    `division` = 'General Affair'
WHERE `full_name` = 'Michael Moore';

UPDATE `user_rh`
SET `role` = 'Head Manager',
    `division` = 'Specialist Medical Authority'
WHERE `full_name` = 'Chocho Xavier';

UPDATE `user_rh`
SET `role` = 'Assisten Manager',
    `division` = 'Specialist Medical Authority'
WHERE `full_name` = 'Shinjiro Takahashi';

UPDATE `user_rh`
SET `role` = 'Assisten Manager',
    `division` = 'Specialist Medical Authority'
WHERE `full_name` = 'Skurt G';

UPDATE `user_rh`
SET `role` = 'Assisten Manager',
    `division` = 'Specialist Medical Authority'
WHERE `full_name` = 'Ken Tungsten';

UPDATE `user_rh`
SET `role` = 'Head Manager',
    `division` = 'Forensic'
WHERE `full_name` = 'Namuh Kanigara';

COMMIT;
