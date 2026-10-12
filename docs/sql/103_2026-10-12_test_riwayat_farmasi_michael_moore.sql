-- DATA UJI: menambahkan satu transaksi farmasi hari ini untuk Michael Moore, S. Ked.
-- Hanya jalankan jika memang ingin baris uji tampil pada Riwayat Transaksi.
-- Baris ini memakai paket valid pertama dari unit user, dan aman dijalankan ulang
-- pada hari yang sama (tx_hash dibuat deterministik per user per tanggal).

SET @medic_name = 'Michael Moore, S. Ked';
SET @test_tx_hash = SHA2(CONCAT('EMS2-SQL-TEST-MICHAEL-MOORE-', CURRENT_DATE()), 256);

-- Pemeriksaan awal: hasil harus menunjukkan tepat satu user dan satu paket.
SELECT id, full_name, position, unit_code
FROM user_rh
WHERE TRIM(full_name) = @medic_name;

SELECT id, name, unit_code, price, bandage_qty, ifaks_qty, painkiller_qty
FROM packages
WHERE COALESCE(NULLIF(unit_code, ''), 'roxwood') = (
    SELECT COALESCE(NULLIF(unit_code, ''), 'roxwood')
    FROM user_rh
    WHERE TRIM(full_name) = @medic_name
    LIMIT 1
)
  AND (COALESCE(bandage_qty, 0) + COALESCE(ifaks_qty, 0) + COALESCE(painkiller_qty, 0)) > 0
ORDER BY id ASC
LIMIT 1;

-- Insert satu transaksi uji yang akan masuk ke tabel Riwayat Transaksi.
INSERT INTO sales (
    consumer_name,
    medic_name,
    medic_user_id,
    medic_jabatan,
    unit_code,
    package_id,
    package_name,
    price,
    qty_bandage,
    qty_ifaks,
    qty_painkiller,
    identity_id,
    created_at,
    tx_hash
)
SELECT
    'UJI COBA SQL - BUKAN TRANSAKSI NYATA',
    medic.full_name,
    medic.id,
    COALESCE(NULLIF(medic.position, ''), 'Uji Coba'),
    COALESCE(NULLIF(medic.unit_code, ''), 'roxwood'),
    package.id,
    CONCAT('UJI COBA - ', package.name),
    package.price,
    package.bandage_qty,
    package.ifaks_qty,
    package.painkiller_qty,
    NULL,
    NOW(),
    @test_tx_hash
FROM user_rh AS medic
JOIN packages AS package
  ON COALESCE(NULLIF(package.unit_code, ''), 'roxwood') = COALESCE(NULLIF(medic.unit_code, ''), 'roxwood')
WHERE TRIM(medic.full_name) = @medic_name
  AND (
      SELECT COUNT(*)
      FROM user_rh
      WHERE TRIM(full_name) = @medic_name
  ) = 1
  AND (COALESCE(package.bandage_qty, 0) + COALESCE(package.ifaks_qty, 0) + COALESCE(package.painkiller_qty, 0)) > 0
  AND NOT EXISTS (
      SELECT 1
      FROM sales
      WHERE tx_hash = @test_tx_hash
  )
ORDER BY package.id ASC
LIMIT 1;

SELECT ROW_COUNT() AS inserted_rows;

-- Verifikasi baris uji setelah INSERT.
SELECT id, consumer_name, medic_name, package_name, price, unit_code, created_at
FROM sales
WHERE tx_hash = @test_tx_hash;
