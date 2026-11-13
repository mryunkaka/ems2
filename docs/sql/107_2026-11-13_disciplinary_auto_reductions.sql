ALTER TABLE disciplinary_point_reductions
    ADD COLUMN medical_record_id INT NULL AFTER id,
    ADD UNIQUE KEY uniq_dpr_reductions_medical_source (medical_record_id, subject_user_id, reduction_type),
    ADD CONSTRAINT fk_dpr_reductions_medical_record
        FOREIGN KEY (medical_record_id) REFERENCES medical_records (id) ON DELETE SET NULL;

INSERT IGNORE INTO disciplinary_point_reductions
    (medical_record_id, subject_user_id, related_case_id, reduction_type, reduction_points,
     activity_date, notes, created_by)
SELECT r.medical_record_id, r.subject_user_id, r.related_case_id, r.reduction_type,
       r.reduction_points, r.activity_date, r.notes, r.submitted_by
FROM disciplinary_point_reduction_requests r
WHERE r.medical_record_id IS NOT NULL
  AND r.status = 'pending';

UPDATE disciplinary_point_reduction_requests
SET status = 'approved', reviewed_at = NOW(),
    review_notes = 'Dicatat otomatis dari rekam medis.'
WHERE medical_record_id IS NOT NULL AND status = 'pending';
