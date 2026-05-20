# Credential Rotation

Lakukan rotasi berikut sebelum deployment final:

1. Ganti user/password database produksi.
2. Update `.env` server:
   - `DB_HOST`
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASS`
   - `DB_TIMEZONE`
3. Verifikasi tidak ada lagi secret lama di:
   - shell history
   - panel hosting
   - backup SQL lama
   - zip artifact lama
4. Restart PHP-FPM / Apache setelah update `.env`.
5. Uji:
   - login
   - dashboard utama
   - upload/preview file
   - cron yang menyentuh DB

Permission minimum yang direkomendasikan:

- File source: read-only untuk user web, kecuali file upload target.
- Folder writable saja:
  - `storage/`
  - `logs/`
  - `backup/private_artifacts/` bila memang dipakai lokal, bukan publik.
- Jangan beri execute permission pada folder upload.
