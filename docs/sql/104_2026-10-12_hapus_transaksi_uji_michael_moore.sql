-- Hapus hanya transaksi uji yang dibuat oleh migrasi 103.
DELETE FROM sales
WHERE consumer_name COLLATE utf8mb4_unicode_ci = 'UJI COBA SQL - BUKAN TRANSAKSI NYATA' COLLATE utf8mb4_unicode_ci
  AND medic_name COLLATE utf8mb4_unicode_ci = 'Michael Moore, S. Ked' COLLATE utf8mb4_unicode_ci
  AND package_name COLLATE utf8mb4_unicode_ci LIKE 'UJI COBA - %' COLLATE utf8mb4_unicode_ci;
