-- Remove legacy prompt instructions that encouraged unsupported clinical invention.
-- Runtime guardrails remain active for already-customized templates.

UPDATE system_ai_prompt_templates
SET user_prompt_template = 'ANAMNESIS:\n{{anamnesis}}\n\nTUGAS: Susun laporan medis berdasarkan fakta eksplisit pada anamnesis dan data yang diberikan. Bila data tidak tersedia, tulis Data belum tersedia dan tandai perlu verifikasi; jangan mengarang nilai, temuan, diagnosis, tindakan, atau hasil.'
WHERE feature_key = 'ai_diagnosis_assistant';

UPDATE system_ai_prompt_templates
SET user_prompt_template = 'JENIS OPERASI: {{jenis_operasi}}\nJENIS ANESTESI: {{jenis_anestesi}}\nTINGKAT KOMPLEKSITAS: {{kompleksitas}}\nJUMLAH LANGKAH: {{jumlah_langkah}}\nKASUS MEDIS / TINDAKAN YANG DIPERLUKAN:\n{{kasus_tindakan}}\n\nTUGAS: Susun rencana operasi berbasis input dan dokumen SOP yang diberikan. Pertahankan jenis anestesi aktual. Jika data klinis atau hasil tindakan belum tersedia, tulis Data belum tersedia; jangan mengarang temuan, obat, dosis, hasil operasi, atau kondisi pasien.'
WHERE feature_key = 'ai_surgery_planner';
