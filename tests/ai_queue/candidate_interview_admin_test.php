<?php
declare(strict_types=1);

require_once __DIR__ . '/../../actions/candidate_interview_admin.php';

aiQueueTest('candidate_interview_admin.limits_access_to_programmer_or_head_hr', static function (): void {
    aiQueueAssertSame(true, ems_candidate_can_manage_interviews(['full_name' => 'Programmer Roxwood', 'role' => 'Staff', 'division' => 'Medis']));
    aiQueueAssertSame(true, ems_candidate_can_manage_interviews(['full_name' => 'HR Lead', 'role' => 'Head Manager', 'division' => 'Human Resource']));
    aiQueueAssertSame(false, ems_candidate_can_manage_interviews(['full_name' => 'HR Lead', 'role' => 'Lead Manager', 'division' => 'Human Resource']));
    aiQueueAssertSame(false, ems_candidate_can_manage_interviews(['full_name' => 'Programmer', 'role' => 'Staff', 'division' => 'Human Resource']));
    aiQueueAssertSame(false, ems_candidate_can_manage_interviews(['full_name' => 'HR Lead', 'role' => 'Head Manager', 'division' => 'Human Capital']));
});

aiQueueTest('candidate_interview_admin.resets_single_interview_for_a_fresh_interview', static function (): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE medical_applicants (id INTEGER PRIMARY KEY, status TEXT)');
    $pdo->exec("INSERT INTO medical_applicants VALUES (77, 'final_review')");
    $pdo->exec('CREATE TABLE interview_criteria (id INTEGER PRIMARY KEY, weight REAL)');
    $pdo->exec('CREATE TABLE applicant_interview_scores (id INTEGER PRIMARY KEY, applicant_id INTEGER, hr_id INTEGER, criteria_id INTEGER, score INTEGER)');
    $pdo->exec('CREATE TABLE applicant_interview_question_responses (applicant_id INTEGER, hr_id INTEGER, question_key TEXT)');
    $pdo->exec('CREATE TABLE applicant_interview_question_packs (applicant_id INTEGER, hr_id INTEGER)');
    $pdo->exec('CREATE TABLE applicant_interview_results (id INTEGER PRIMARY KEY AUTOINCREMENT, applicant_id INTEGER UNIQUE, total_hr INTEGER, average_score REAL, final_grade TEXT, ml_flags TEXT, ml_confidence REAL, is_locked INTEGER, locked_at TEXT)');
    $pdo->exec('INSERT INTO interview_criteria VALUES (1, 1), (2, 1)');
    $pdo->exec('INSERT INTO applicant_interview_scores VALUES (1, 77, 9, 1, 4), (2, 77, 9, 2, 4)');
    $pdo->exec("INSERT INTO applicant_interview_question_responses VALUES (77, 9, 'q1'), (77, 9, 'q2')");
    $pdo->exec('INSERT INTO applicant_interview_question_packs VALUES (77, 9)');
    $pdo->exec("INSERT INTO applicant_interview_results (applicant_id,total_hr,average_score,final_grade,is_locked) VALUES (77,1,80,'baik',1)");
    $result = ems_candidate_refresh_interview($pdo, 77);
    aiQueueAssertSame(true, $result['reset']);
    aiQueueAssertSame(false, $result['deleted']);
    aiQueueAssertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM applicant_interview_scores')->fetchColumn());
    aiQueueAssertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM applicant_interview_question_responses')->fetchColumn());
    aiQueueAssertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM applicant_interview_question_packs')->fetchColumn());
    aiQueueAssertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM applicant_interview_results WHERE applicant_id = 77')->fetchColumn());
    aiQueueAssertSame('interview', $pdo->query('SELECT status FROM medical_applicants WHERE id = 77')->fetchColumn());
});

aiQueueTest('candidate_interview_admin.deletes_selected_interviewer_and_recalculates_remaining_sessions', static function (): void {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE medical_applicants (id INTEGER PRIMARY KEY, status TEXT)');
    $pdo->exec("INSERT INTO medical_applicants VALUES (77, 'interview')");
    $pdo->exec('CREATE TABLE interview_criteria (id INTEGER PRIMARY KEY, weight REAL)');
    $pdo->exec('CREATE TABLE applicant_interview_scores (id INTEGER PRIMARY KEY, applicant_id INTEGER, hr_id INTEGER, criteria_id INTEGER, score INTEGER)');
    $pdo->exec('CREATE TABLE applicant_interview_question_responses (applicant_id INTEGER, hr_id INTEGER, question_key TEXT)');
    $pdo->exec('CREATE TABLE applicant_interview_question_packs (applicant_id INTEGER, hr_id INTEGER)');
    $pdo->exec('CREATE TABLE applicant_interview_results (id INTEGER PRIMARY KEY AUTOINCREMENT, applicant_id INTEGER UNIQUE, total_hr INTEGER, average_score REAL, final_grade TEXT, ml_flags TEXT, ml_confidence REAL, is_locked INTEGER, locked_at TEXT)');
    $pdo->exec('INSERT INTO interview_criteria VALUES (1, 1)');
    $pdo->exec('INSERT INTO applicant_interview_scores VALUES (1, 77, 9, 1, 2), (2, 77, 10, 1, 5)');
    $pdo->exec("INSERT INTO applicant_interview_question_responses VALUES (77, 9, 'q1'), (77, 10, 'q1')");
    $pdo->exec('INSERT INTO applicant_interview_question_packs VALUES (77, 9), (77, 10)');
    $result = ems_candidate_refresh_interview($pdo, 77, 9);
    aiQueueAssertSame(1, $result['total_hr']);
    aiQueueAssertSame(100.0, (float)$result['average_score']);
    aiQueueAssertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM applicant_interview_scores')->fetchColumn());
    aiQueueAssertSame(10, (int)$pdo->query('SELECT hr_id FROM applicant_interview_scores')->fetchColumn());
    aiQueueAssertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM applicant_interview_question_responses')->fetchColumn());
    aiQueueAssertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM applicant_interview_question_packs')->fetchColumn());
});
