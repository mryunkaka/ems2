-- Remove the six retired Roxwood Hospital AI case-workflow modules and their
-- generated artifacts. Personal provider settings and general Roxy remain.

DELETE FROM `system_ai_prompt_templates`
WHERE `feature_key` IN (
    'ai_diagnosis_assistant',
    'ai_diagnosis_assistant_stage_1',
    'ai_diagnosis_assistant_stage_2',
    'ai_diagnosis_assistant_stage_3',
    'ai_diagnosis_assistant_emergency_repair',
    'ai_diagnosis_assistant_lab_sample_repair',
    'ai_diagnosis_assistant_emergency_duplicate_repair',
    'ai_diagnosis_assistant_emergency_card_repair',
    'ai_diagnosis_assistant_emergency_actor_repair',
    'ai_diagnosis_assistant_emergency_ttv_repair',
    'ai_diagnosis_assistant_radiology_repair',
    'ai_diagnosis_assistant_action_repair',
    'ai_surgery_planner',
    'ai_radiology_report',
    'ai_laboratory',
    'rekam_medis_ai',
    'rekam_medis_ai_repair',
    'ai_psychiatry_start',
    'ai_psychiatry_next',
    'ai_psychiatry_final'
);

DELETE FROM `system_ai_request_logs`
WHERE `feature_key` LIKE 'ai_diagnosis_assistant%'
   OR `feature_key` IN ('ai_surgery_planner','ai_radiology_center','ai_radiology_report','ai_laboratory','rekam_medis_ai','rekam_medis_ai_repair','ai_psychiatry_start','ai_psychiatry_next','ai_psychiatry_final');

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `ai_surgery_generation_stages`;
DROP TABLE IF EXISTS `ai_surgery_generation_provider_slots`;
DROP TABLE IF EXISTS `ai_surgery_generation_jobs`;
DROP TABLE IF EXISTS `ai_diagnosis_reassessments`;
DROP TABLE IF EXISTS `ai_laboratory_results`;
DROP TABLE IF EXISTS `ai_radiology_images`;
DROP TABLE IF EXISTS `ai_psychiatry_assessments`;
DROP TABLE IF EXISTS `ai_surgery_plans`;
DROP TABLE IF EXISTS `ai_diagnosis_reports`;
SET FOREIGN_KEY_CHECKS = 1;
