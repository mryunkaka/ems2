-- EMS2
-- Seeder: Data Real Pendaftaran Sertifikat Heli (2 Periode)
-- Date: 2026-06-16
--
-- Tujuan:
-- Mengisi data sesuai dump asli dari database production.
-- - Pendaftaran 1: 20 April 2026 (10 pendaftar, sudah selesai)
-- - Pendaftaran 2: 15 Juni 2026 (18 pendaftar, sedang berlangsung)
--
-- CATATAN PENTING:
-- Sebelum menjalankan seeder ini, pastikan:
-- 1. Migration 48_2026-06-16_sertifikat_heli_period_scoped_registration.sql sudah dijalankan
-- 2. Data user_rh sudah ada di database

START TRANSACTION;

-- =========================================================
-- HAPUS DATA LAMA (untuk testing bersih)
-- =========================================================
DELETE FROM sertifikat_heli_registrations;
DELETE FROM sertifikat_heli_settings;

-- Reset AUTO_INCREMENT
ALTER TABLE sertifikat_heli_settings AUTO_INCREMENT = 1;
ALTER TABLE sertifikat_heli_registrations AUTO_INCREMENT = 1;

-- =========================================================
-- PERIODE 1: PENDAFTARAN 1 - April 2026
-- Pendaftaran tanggal 20 April 2026
-- Status: SUDAH SELESAI (closed)
-- =========================================================

INSERT INTO sertifikat_heli_settings (id, start_datetime, end_datetime, max_slots, min_jabatan)
VALUES (1, '2026-04-15 08:00:00', '2026-04-25 23:59:59', 15, NULL);

-- =========================================================
-- PERIODE 2: PENDAFTARAN 2 - Juni 2026
-- Pendaftaran dimulai 15 Juni 2026
-- Status: SEDANG BERLANGSUNG (open)
-- =========================================================

INSERT INTO sertifikat_heli_settings (id, start_datetime, end_datetime, max_slots, min_jabatan)
VALUES (2, '2026-06-15 20:00:00', '2026-06-21 23:59:00', 100, 'paramedic');

-- =========================================================
-- DATA PENDAFTAR - PERIODE 1 (PENDAFTARAN 1) - settings_id = 1
-- Tanggal pendaftaran: 20 April 2026
-- =========================================================

INSERT INTO sertifikat_heli_registrations
    (user_id, settings_id, user_name, user_jabatan, user_division, registered_at, status)
VALUES
    (145, 1, 'Akira Cartier Shironomi',     'paramedic',            'Medis',            '2026-04-20 16:11:09', 'registered'),
    (63,  1, 'Ammabel L Kalux',             'general_practitioner', 'Forensic',         '2026-04-20 16:15:01', 'registered'),
    (142, 1, 'Ollie Hexagonal',             'paramedic',            'Medis',            '2026-04-20 16:15:02', 'registered'),
    (108, 1, 'Aurelia Sakura',              'co_asst',              'Medis',            '2026-04-20 16:15:41', 'registered'),
    (107, 1, 'Kagenou Shadow Cartier',      'paramedic',            'Medis',            '2026-04-20 16:16:01', 'registered'),
    (69,  1, 'Joseph McClain',              'co_asst',              'Medis',            '2026-04-20 16:17:37', 'registered'),
    (9,   1, 'Jinu Joeseung Saja',          'co_asst',              'Human Resource',   '2026-04-20 16:22:10', 'registered'),
    (72,  1, 'Hadzee D Halilintar',         'co_asst',              'Medis',            '2026-04-20 16:28:20', 'registered'),
    (165, 1, 'Izzy A Basima',               'paramedic',            'Medis',            '2026-04-20 16:29:19', 'registered'),
    (151, 1, 'Nancy Von Volstaire',          'paramedic',            'Medis',            '2026-04-20 16:29:36', 'registered');

-- =========================================================
-- DATA PENDAFTAR - PERIODE 2 (PENDAFTARAN 2) - settings_id = 2
-- Tanggal pendaftaran: 15 Juni 2026
-- =========================================================

INSERT INTO sertifikat_heli_registrations
    (user_id, settings_id, user_name, user_jabatan, user_division, registered_at, status)
VALUES
    (149, 2, 'Zayn Elian Richter',          'co_asst',              'Medis',                      '2026-06-15 13:06:33', 'registered'),
    (113, 2, 'AaronMoore',                  'paramedic',            'Medis',                      '2026-06-15 13:06:33', 'registered'),
    (88,  2, 'Lisa Valencia Madeline',      'general_practitioner', 'Specialist Medical Authority','2026-06-15 13:06:35', 'registered'),
    (143, 2, 'Lery Althaf Nakashima',       'co_asst',              'Medis',                      '2026-06-15 13:06:43', 'registered'),
    (155, 2, 'Chihaya E Xaverion',          'co_asst',              'Medis',                      '2026-06-15 13:06:48', 'registered'),
    (80,  2, 'Ishihara Daniel Moore',       'general_practitioner', 'Secretary',                  '2026-06-15 13:07:13', 'registered'),
    (153, 2, 'Panjul',                      'co_asst',              'Medis',                      '2026-06-15 13:07:31', 'registered'),
    (114, 2, 'Mail Caldwell',               'paramedic',            'Medis',                      '2026-06-15 13:07:48', 'registered'),
    (137, 2, 'Aivellya Valencia Nakashima', 'co_asst',              'Medis',                      '2026-06-15 13:09:21', 'registered'),
    (181, 2, 'Bebe Junichi',                'paramedic',            'Medis',                      '2026-06-15 13:10:41', 'registered'),
    (148, 2, 'Vulcann Volkov Xaverion',     'co_asst',              'Medis',                      '2026-06-15 13:11:10', 'registered'),
    (118, 2, 'Toru Nakashima',              'co_asst',              'Human Resource',             '2026-06-15 13:11:32', 'registered'),
    (206, 2, 'Ahmad Toji',                  'paramedic',            'Medis',                      '2026-06-15 13:16:57', 'registered'),
    (120, 2, 'Oscar Cartier',               'co_asst',              'Disciplinary Committee',     '2026-06-15 13:22:32', 'registered'),
    (194, 2, 'Blu Rami',                    'paramedic',            'Medis',                      '2026-06-15 14:48:32', 'registered'),
    (106, 2, 'Ryukenn De Volkov',           'paramedic',            'Medis',                      '2026-06-15 14:52:22', 'registered'),
    (224, 2, 'ZACH COTTONS',                'paramedic',            'Medis',                      '2026-06-15 21:48:00', 'registered'),
    (166, 2, 'Daeng Ricky Mercury',         'paramedic',            'Medis',                      '2026-06-15 22:59:47', 'registered');

COMMIT;
