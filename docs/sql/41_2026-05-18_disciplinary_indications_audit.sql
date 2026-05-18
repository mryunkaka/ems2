ALTER TABLE disciplinary_indications
    ADD COLUMN created_by INT NULL AFTER is_active,
    ADD COLUMN updated_by INT NULL AFTER created_by;

ALTER TABLE disciplinary_indications
    ADD KEY idx_disciplinary_indications_created_by (created_by),
    ADD KEY idx_disciplinary_indications_updated_by (updated_by);

ALTER TABLE disciplinary_indications
    ADD CONSTRAINT fk_disciplinary_indications_created_by
        FOREIGN KEY (created_by) REFERENCES user_rh (id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_disciplinary_indications_updated_by
        FOREIGN KEY (updated_by) REFERENCES user_rh (id)
        ON DELETE SET NULL;
