-- Let the model choose a case-appropriate procedure length instead of forcing
-- legacy 10/20/30-step presets. The runtime also replaces old placeholders.
UPDATE system_ai_prompt_templates
SET user_prompt_template = 'JENIS OPERASI: {{jenis_operasi}}\nJENIS ANESTESI: {{jenis_anestesi}}\nJUMLAH TAHAP: ditentukan model sesuai kebutuhan kasus, tanpa target angka\nKASUS MEDIS / TINDAKAN DARI USER:\n{{kasus_tindakan}}\n\nTUGAS MODEL AI: Susun satu rencana operasi roleplay lengkap dan spesifik terhadap kasus, termasuk persiapan, tahapan yang benar-benar diperlukan, peran, alat, risiko relevan, monitoring, dan rujukan SOP. Tentukan sendiri jumlah tahap secara proporsional; jangan mengisi dengan pengulangan atau mengejar jumlah tertentu. Kembalikan seluruh rencana dalam satu JSON sesuai schema.'
WHERE feature_key = 'ai_surgery_planner';
