INSERT INTO disciplinary_point_reduction_requests
    (medical_record_id, subject_user_id, related_case_id, reduction_type, reduction_points,
     activity_date, notes, status, submitted_by, created_at)
SELECT medical_record_id, subject_user_id, related_case_id, reduction_type, reduction_points,
       activity_date, notes, 'pending', created_by, created_at
FROM disciplinary_point_reductions
WHERE medical_record_id IS NOT NULL
  AND created_by = subject_user_id
ON DUPLICATE KEY UPDATE
    related_case_id = VALUES(related_case_id),
    reduction_points = VALUES(reduction_points),
    activity_date = VALUES(activity_date),
    notes = VALUES(notes),
    status = 'pending',
    submitted_by = VALUES(submitted_by),
    reviewed_by = NULL,
    reviewed_at = NULL,
    review_notes = NULL;

DELETE FROM disciplinary_point_reductions
WHERE medical_record_id IS NOT NULL
  AND created_by = subject_user_id;
