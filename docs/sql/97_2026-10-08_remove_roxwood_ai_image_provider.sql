-- Cloudflare image generation existed only for the retired Radiology Center.
DELETE FROM `system_ai_request_logs` WHERE `provider` = 'cloudflare';
DROP TABLE IF EXISTS `system_cloudflare_settings`;
