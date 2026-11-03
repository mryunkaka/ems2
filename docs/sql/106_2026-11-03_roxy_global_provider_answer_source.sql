-- Deep-research replies are persisted with answer_source=global_provider.
-- Existing installations still have the original enum from migration 76.
ALTER TABLE `bot_messages`
    MODIFY COLUMN `answer_source` ENUM('knowledge_base','learned_correction','free_model','gemini_personal','global_provider') DEFAULT NULL;
