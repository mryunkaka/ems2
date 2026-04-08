<?php

function farmasi_quiz_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
}

function farmasi_quiz_week_bounds(?DateTimeImmutable $now = null): array
{
    $now = $now ?: farmasi_quiz_now();
    $weekStart = $now->modify('monday this week')->setTime(0, 0, 0);
    $weekEnd = $weekStart->modify('+6 days')->setTime(23, 59, 59);

    return [
        'now' => $now,
        'week_start' => $weekStart,
        'week_end' => $weekEnd,
    ];
}

function farmasi_quiz_season_label(DateTimeImmutable $weekStart): string
{
    $weekEnd = $weekStart->modify('+6 days');
    return sprintf(
        'Season %s (%s - %s)',
        $weekStart->format('o-\WW'),
        $weekStart->format('d M Y'),
        $weekEnd->format('d M Y')
    );
}

function farmasi_quiz_fetch_scalar(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function farmasi_quiz_shuffle_question_options(array $question): array
{
    $letters = ['a', 'b', 'c', 'd', 'e'];
    $correctLetter = strtolower((string)($question['correct_option'] ?? 'a'));
    $optionPool = [];

    foreach ($letters as $letter) {
        $optionPool[] = [
            'text' => (string)($question['option_' . $letter] ?? ''),
            'is_correct' => ($letter === $correctLetter),
        ];
    }

    shuffle($optionPool);

    foreach ($letters as $index => $letter) {
        $question['option_' . $letter] = (string)$optionPool[$index]['text'];
        if (!empty($optionPool[$index]['is_correct'])) {
            $question['correct_option'] = $letter;
        }
    }

    return $question;
}

function farmasi_quiz_require_schema(PDO $pdo): void
{
    $requiredTables = [
        'farmasi_quiz_questions',
        'farmasi_quiz_sessions',
        'farmasi_quiz_session_questions',
        'farmasi_quiz_weekly_scores',
        'farmasi_quiz_weekly_history',
    ];

    foreach ($requiredTables as $table) {
        if (!ems_table_exists($pdo, $table)) {
            throw new RuntimeException(
                'Tabel quiz farmasi belum tersedia. Jalankan SQL `docs/sql/23_2026-04-08_farmasi_quiz.sql` terlebih dahulu.'
            );
        }
    }
}

function farmasi_quiz_finalize_previous_weeks(PDO $pdo): void
{
    farmasi_quiz_require_schema($pdo);

    $bounds = farmasi_quiz_week_bounds();
    $currentWeekStart = $bounds['week_start']->format('Y-m-d');

    $stmtWeeks = $pdo->prepare("
        SELECT DISTINCT week_start
        FROM farmasi_quiz_weekly_scores
        WHERE week_start < ?
          AND points > 0
          AND week_start IS NOT NULL
        ORDER BY week_start ASC
    ");
    $stmtWeeks->execute([$currentWeekStart]);
    $weeks = $stmtWeeks->fetchAll(PDO::FETCH_COLUMN) ?: [];

    if ($weeks === []) {
        return;
    }

    $selectWinnerSql = "
        SELECT
            user_id,
            display_name,
            points,
            correct_answers,
            wrong_answers,
            completed_sessions,
            passed_sessions
        FROM farmasi_quiz_weekly_scores
        WHERE week_start = ?
        ORDER BY points DESC, correct_answers DESC, wrong_answers ASC, updated_at ASC, user_id ASC
        LIMIT 1
    ";
    $stmtWinner = $pdo->prepare($selectWinnerSql);

    $stmtExists = $pdo->prepare("
        SELECT id
        FROM farmasi_quiz_weekly_history
        WHERE week_start = ?
        LIMIT 1
    ");

    $stmtInsert = $pdo->prepare("
        INSERT INTO farmasi_quiz_weekly_history
            (week_start, week_end, season_label, winner_user_id, winner_name, points, correct_answers, wrong_answers, completed_sessions, passed_sessions, finalized_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    foreach ($weeks as $weekStartRaw) {
        $stmtExists->execute([$weekStartRaw]);
        if ($stmtExists->fetchColumn()) {
            continue;
        }

        $weekStart = new DateTimeImmutable((string)$weekStartRaw, new DateTimeZone('Asia/Jakarta'));
        $weekEnd = $weekStart->modify('+6 days');

        $stmtWinner->execute([$weekStartRaw]);
        $winner = $stmtWinner->fetch(PDO::FETCH_ASSOC);
        if (!$winner) {
            continue;
        }

        $stmtInsert->execute([
            $weekStart->format('Y-m-d'),
            $weekEnd->format('Y-m-d'),
            farmasi_quiz_season_label($weekStart),
            (int)($winner['user_id'] ?? 0),
            (string)($winner['display_name'] ?? '-'),
            (int)($winner['points'] ?? 0),
            (int)($winner['correct_answers'] ?? 0),
            (int)($winner['wrong_answers'] ?? 0),
            (int)($winner['completed_sessions'] ?? 0),
            (int)($winner['passed_sessions'] ?? 0),
        ]);
    }
}

function farmasi_quiz_normalize_question_row(array $row): array
{
    return [
        'session_question_id' => (int)($row['session_question_id'] ?? 0),
        'question_id' => (int)($row['question_id'] ?? 0),
        'order' => (int)($row['question_order'] ?? 0),
        'prompt' => (string)($row['prompt'] ?? ''),
        'category' => (string)($row['category'] ?? ''),
        'option_a' => (string)($row['option_a'] ?? ''),
        'option_b' => (string)($row['option_b'] ?? ''),
        'option_c' => (string)($row['option_c'] ?? ''),
        'option_d' => (string)($row['option_d'] ?? ''),
        'option_e' => (string)($row['option_e'] ?? ''),
        'correct_option' => strtolower((string)($row['correct_option'] ?? '')),
        'selected_option' => $row['selected_option'] !== null ? strtolower((string)$row['selected_option']) : null,
        'is_correct' => $row['is_correct'] !== null ? (int)$row['is_correct'] === 1 : null,
        'answered_at' => $row['answered_at'] ?? null,
        'explanation' => (string)($row['explanation'] ?? ''),
    ];
}

function farmasi_quiz_pick_question_ids(PDO $pdo, int $userId, string $weekStart, int $limit = 10): array
{
    $stmtAll = $pdo->query("
        SELECT id
        FROM farmasi_quiz_questions
        WHERE is_active = 1
        ORDER BY id ASC
    ");
    $allQuestionIds = array_map('intval', $stmtAll->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if (count($allQuestionIds) < $limit) {
        throw new RuntimeException('Bank soal quiz farmasi belum mencapai 10 soal aktif.');
    }

    $stmtActiveOther = $pdo->prepare("
        SELECT DISTINCT sq.question_id
        FROM farmasi_quiz_session_questions sq
        INNER JOIN farmasi_quiz_sessions s ON s.id = sq.session_id
        WHERE s.user_id <> ?
          AND s.week_start = ?
          AND s.completed_at IS NULL
          AND s.expires_at > NOW()
    ");
    $stmtActiveOther->execute([$userId, $weekStart]);
    $activeOtherIds = array_map('intval', $stmtActiveOther->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $stmtUserWeek = $pdo->prepare("
        SELECT DISTINCT sq.question_id
        FROM farmasi_quiz_session_questions sq
        INNER JOIN farmasi_quiz_sessions s ON s.id = sq.session_id
        WHERE s.user_id = ?
          AND s.week_start = ?
    ");
    $stmtUserWeek->execute([$userId, $weekStart]);
    $userWeekIds = array_map('intval', $stmtUserWeek->fetchAll(PDO::FETCH_COLUMN) ?: []);

    $candidateGroups = [
        array_values(array_diff($allQuestionIds, array_unique(array_merge($activeOtherIds, $userWeekIds)))),
        array_values(array_diff($allQuestionIds, $activeOtherIds)),
        array_values(array_diff($allQuestionIds, $userWeekIds)),
        $allQuestionIds,
    ];

    foreach ($candidateGroups as $candidateIds) {
        if (count($candidateIds) < $limit) {
            continue;
        }

        shuffle($candidateIds);
        return array_slice($candidateIds, 0, $limit);
    }

    shuffle($allQuestionIds);
    return array_slice($allQuestionIds, 0, $limit);
}

function farmasi_quiz_create_session(PDO $pdo, int $userId, string $displayName): array
{
    farmasi_quiz_require_schema($pdo);
    farmasi_quiz_finalize_previous_weeks($pdo);

    $bounds = farmasi_quiz_week_bounds();
    $weekStart = $bounds['week_start']->format('Y-m-d');
    $sessionKey = bin2hex(random_bytes(18));
    $questionIds = farmasi_quiz_pick_question_ids($pdo, $userId, $weekStart, 10);

    $pdo->beginTransaction();
    try {
        $stmtSession = $pdo->prepare("
            INSERT INTO farmasi_quiz_sessions
                (session_key, user_id, display_name, week_start, generated_at, expires_at, total_questions)
            VALUES
                (?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 120 MINUTE), 10)
        ");
        $stmtSession->execute([$sessionKey, $userId, $displayName, $weekStart]);
        $sessionId = (int)$pdo->lastInsertId();

        $questionPlaceholders = implode(',', array_fill(0, count($questionIds), '?'));
        $stmtQuestionRows = $pdo->prepare("
            SELECT id, prompt, option_a, option_b, option_c, option_d, option_e, correct_option, explanation, category
            FROM farmasi_quiz_questions
            WHERE id IN ($questionPlaceholders)
        ");
        $stmtQuestionRows->execute($questionIds);
        $questionMap = [];
        foreach ($stmtQuestionRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $questionMap[(int)$row['id']] = $row;
        }

        $stmtInsertQuestion = $pdo->prepare("
            INSERT INTO farmasi_quiz_session_questions
                (session_id, question_id, question_order, prompt, option_a, option_b, option_c, option_d, option_e, correct_option, explanation, category)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach (array_values($questionIds) as $index => $questionId) {
            if (!isset($questionMap[$questionId])) {
                throw new RuntimeException('Soal quiz tidak ditemukan saat membuat sesi.');
            }
            $question = farmasi_quiz_shuffle_question_options($questionMap[$questionId]);
            $stmtInsertQuestion->execute([
                $sessionId,
                $questionId,
                $index + 1,
                (string)$question['prompt'],
                (string)$question['option_a'],
                (string)$question['option_b'],
                (string)$question['option_c'],
                (string)$question['option_d'],
                (string)$question['option_e'],
                strtolower((string)$question['correct_option']),
                (string)$question['explanation'],
                (string)$question['category'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return farmasi_quiz_get_session_payload($pdo, $userId, true);
}

function farmasi_quiz_get_session_record(PDO $pdo, int $userId): ?array
{
    $bounds = farmasi_quiz_week_bounds();
    $weekStart = $bounds['week_start']->format('Y-m-d');

    $stmt = $pdo->prepare("
        SELECT *
        FROM farmasi_quiz_sessions
        WHERE user_id = ?
          AND week_start = ?
          AND completed_at IS NULL
          AND expires_at > NOW()
        ORDER BY generated_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$userId, $weekStart]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    return $session ?: null;
}

function farmasi_quiz_get_unexpired_session_window(PDO $pdo, int $userId): ?array
{
    $bounds = farmasi_quiz_week_bounds();
    $weekStart = $bounds['week_start']->format('Y-m-d');

    $stmt = $pdo->prepare("
        SELECT *
        FROM farmasi_quiz_sessions
        WHERE user_id = ?
          AND week_start = ?
          AND expires_at > NOW()
        ORDER BY generated_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$userId, $weekStart]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function farmasi_quiz_get_session_payload(PDO $pdo, int $userId, bool $allowCreate = true): array
{
    farmasi_quiz_require_schema($pdo);
    farmasi_quiz_finalize_previous_weeks($pdo);

    $sessionWindow = farmasi_quiz_get_unexpired_session_window($pdo, $userId);
    $session = ($sessionWindow && empty($sessionWindow['completed_at'])) ? $sessionWindow : null;

    if (!$sessionWindow && $allowCreate) {
        $displayName = trim((string)($_SESSION['user_rh']['name'] ?? $_SESSION['user_rh']['full_name'] ?? 'Medis'));
        return farmasi_quiz_create_session($pdo, $userId, $displayName !== '' ? $displayName : 'Medis');
    }

    $bounds = farmasi_quiz_week_bounds();
    $weekStart = $bounds['week_start']->format('Y-m-d');

    $ranking = farmasi_quiz_fetch_ranking($pdo, $weekStart, 10);
    $history = farmasi_quiz_fetch_history($pdo, 8);
    $personal = farmasi_quiz_fetch_personal_score($pdo, $userId, $weekStart);

    if (!$session) {
        $lastSummary = null;
        if ($sessionWindow) {
            $lastSummary = [
                'generated_at' => (string)($sessionWindow['generated_at'] ?? ''),
                'expires_at' => (string)($sessionWindow['expires_at'] ?? ''),
                'completed_at' => $sessionWindow['completed_at'] ?: null,
                'score_correct' => (int)($sessionWindow['score_correct'] ?? 0),
                'score_wrong' => (int)($sessionWindow['score_wrong'] ?? 0),
                'points' => (int)($sessionWindow['points'] ?? 0),
                'pass_status' => (string)($sessionWindow['pass_status'] ?? 'pending'),
            ];
        }

        return [
            'has_active_session' => false,
            'session' => null,
            'cooldown' => [
                'active' => $sessionWindow !== null,
                'next_available_at' => $sessionWindow['expires_at'] ?? null,
                'last_summary' => $lastSummary,
            ],
            'ranking' => $ranking,
            'history' => $history,
            'personal' => $personal,
            'week' => [
                'start' => $bounds['week_start']->format(DateTimeInterface::ATOM),
                'end' => $bounds['week_end']->format(DateTimeInterface::ATOM),
                'label' => farmasi_quiz_season_label($bounds['week_start']),
            ],
        ];
    }

    $stmtQuestions = $pdo->prepare("
        SELECT
            id AS session_question_id,
            question_id,
            question_order,
            prompt,
            category,
            option_a,
            option_b,
            option_c,
            option_d,
            option_e,
            correct_option,
            selected_option,
            is_correct,
            answered_at,
            explanation
        FROM farmasi_quiz_session_questions
        WHERE session_id = ?
        ORDER BY question_order ASC, id ASC
    ");
    $stmtQuestions->execute([(int)$session['id']]);
    $questions = array_map('farmasi_quiz_normalize_question_row', $stmtQuestions->fetchAll(PDO::FETCH_ASSOC) ?: []);

    $answeredCount = 0;
    $correctCount = 0;
    foreach ($questions as $question) {
        if ($question['selected_option'] !== null) {
            $answeredCount++;
        }
        if ($question['is_correct'] === true) {
            $correctCount++;
        }
    }

    $activeIndex = 0;
    foreach ($questions as $index => $question) {
        if ($question['selected_option'] === null) {
            $activeIndex = $index;
            break;
        }
        $activeIndex = min($index + 1, max(count($questions) - 1, 0));
    }

    return [
        'has_active_session' => true,
        'session' => [
            'id' => (int)$session['id'],
            'key' => (string)$session['session_key'],
            'generated_at' => (string)$session['generated_at'],
            'expires_at' => (string)$session['expires_at'],
            'completed_at' => $session['completed_at'] ?: null,
            'total_questions' => (int)($session['total_questions'] ?? 10),
            'answered_count' => $answeredCount,
            'correct_count' => $correctCount,
            'wrong_count' => max(0, $answeredCount - $correctCount),
            'pass_minimum' => 7,
            'active_index' => $activeIndex,
            'questions' => $questions,
        ],
        'cooldown' => [
            'active' => true,
            'next_available_at' => $session['expires_at'] ?? null,
            'last_summary' => null,
        ],
        'ranking' => $ranking,
        'history' => $history,
        'personal' => $personal,
        'week' => [
            'start' => $bounds['week_start']->format(DateTimeInterface::ATOM),
            'end' => $bounds['week_end']->format(DateTimeInterface::ATOM),
            'label' => farmasi_quiz_season_label($bounds['week_start']),
        ],
    ];
}

function farmasi_quiz_fetch_personal_score(PDO $pdo, int $userId, string $weekStart): array
{
    $stmt = $pdo->prepare("
        SELECT points, correct_answers, wrong_answers, completed_sessions, passed_sessions
        FROM farmasi_quiz_weekly_scores
        WHERE user_id = ?
          AND week_start = ?
        LIMIT 1
    ");
    $stmt->execute([$userId, $weekStart]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'points' => (int)($row['points'] ?? 0),
        'correct_answers' => (int)($row['correct_answers'] ?? 0),
        'wrong_answers' => (int)($row['wrong_answers'] ?? 0),
        'completed_sessions' => (int)($row['completed_sessions'] ?? 0),
        'passed_sessions' => (int)($row['passed_sessions'] ?? 0),
    ];
}

function farmasi_quiz_fetch_ranking(PDO $pdo, string $weekStart, int $limit = 10): array
{
    $stmt = $pdo->prepare("
        SELECT
            user_id,
            display_name,
            points,
            correct_answers,
            wrong_answers,
            completed_sessions,
            passed_sessions
        FROM farmasi_quiz_weekly_scores
        WHERE week_start = ?
        ORDER BY points DESC, correct_answers DESC, wrong_answers ASC, updated_at ASC, user_id ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, $weekStart);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rank = 0;
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rank++;
        $items[] = [
            'rank' => $rank,
            'user_id' => (int)($row['user_id'] ?? 0),
            'display_name' => (string)($row['display_name'] ?? '-'),
            'points' => (int)($row['points'] ?? 0),
            'correct_answers' => (int)($row['correct_answers'] ?? 0),
            'wrong_answers' => (int)($row['wrong_answers'] ?? 0),
            'completed_sessions' => (int)($row['completed_sessions'] ?? 0),
            'passed_sessions' => (int)($row['passed_sessions'] ?? 0),
        ];
    }

    return $items;
}

function farmasi_quiz_fetch_history(PDO $pdo, int $limit = 8): array
{
    $stmt = $pdo->prepare("
        SELECT
            season_label,
            week_start,
            week_end,
            winner_user_id,
            winner_name,
            points,
            correct_answers,
            wrong_answers,
            finalized_at
        FROM farmasi_quiz_weekly_history
        ORDER BY week_start DESC, id DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();

    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $items[] = [
            'season_label' => (string)($row['season_label'] ?? ''),
            'week_start' => (string)($row['week_start'] ?? ''),
            'week_end' => (string)($row['week_end'] ?? ''),
            'winner_user_id' => (int)($row['winner_user_id'] ?? 0),
            'winner_name' => (string)($row['winner_name'] ?? '-'),
            'points' => (int)($row['points'] ?? 0),
            'correct_answers' => (int)($row['correct_answers'] ?? 0),
            'wrong_answers' => (int)($row['wrong_answers'] ?? 0),
            'finalized_at' => (string)($row['finalized_at'] ?? ''),
        ];
    }

    return $items;
}

function farmasi_quiz_finalize_session(PDO $pdo, array $session): array
{
    $sessionId = (int)($session['id'] ?? 0);
    $userId = (int)($session['user_id'] ?? 0);
    $displayName = trim((string)($session['display_name'] ?? 'Medis'));
    $weekStart = (string)($session['week_start'] ?? '');

    $stmtAgg = $pdo->prepare("
        SELECT
            COUNT(*) AS total_questions,
            SUM(CASE WHEN selected_option IS NOT NULL THEN 1 ELSE 0 END) AS answered_count,
            SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) AS correct_count,
            SUM(CASE WHEN is_correct = 0 THEN 1 ELSE 0 END) AS wrong_count
        FROM farmasi_quiz_session_questions
        WHERE session_id = ?
    ");
    $stmtAgg->execute([$sessionId]);
    $agg = $stmtAgg->fetch(PDO::FETCH_ASSOC) ?: [];

    $totalQuestions = (int)($agg['total_questions'] ?? 0);
    $answeredCount = (int)($agg['answered_count'] ?? 0);
    $correctCount = (int)($agg['correct_count'] ?? 0);
    $wrongCount = (int)($agg['wrong_count'] ?? 0);

    if ($totalQuestions <= 0 || $answeredCount < $totalQuestions) {
        throw new RuntimeException('Session quiz belum selesai dijawab.');
    }

    $passStatus = $correctCount >= 7 ? 'passed' : 'failed';

    $stmtSessionDone = $pdo->prepare("
        UPDATE farmasi_quiz_sessions
        SET completed_at = NOW(),
            score_correct = ?,
            score_wrong = ?,
            points = ?,
            pass_status = ?
        WHERE id = ?
          AND completed_at IS NULL
    ");
    $stmtSessionDone->execute([$correctCount, $wrongCount, $correctCount, $passStatus, $sessionId]);

    if ($stmtSessionDone->rowCount() > 0) {
        $stmtScore = $pdo->prepare("
            INSERT INTO farmasi_quiz_weekly_scores
                (week_start, user_id, display_name, points, correct_answers, wrong_answers, completed_sessions, passed_sessions)
            VALUES
                (?, ?, ?, ?, ?, ?, 1, ?)
            ON DUPLICATE KEY UPDATE
                display_name = VALUES(display_name),
                points = points + VALUES(points),
                correct_answers = correct_answers + VALUES(correct_answers),
                wrong_answers = wrong_answers + VALUES(wrong_answers),
                completed_sessions = completed_sessions + 1,
                passed_sessions = passed_sessions + VALUES(passed_sessions),
                updated_at = NOW()
        ");
        $stmtScore->execute([
            $weekStart,
            $userId,
            $displayName !== '' ? $displayName : 'Medis',
            $correctCount,
            $correctCount,
            $wrongCount,
            $passStatus === 'passed' ? 1 : 0,
        ]);
    }

    return [
        'total_questions' => $totalQuestions,
        'answered_count' => $answeredCount,
        'correct_count' => $correctCount,
        'wrong_count' => $wrongCount,
        'pass_status' => $passStatus,
        'did_finalize' => $stmtSessionDone->rowCount() > 0,
    ];
}

function farmasi_quiz_submit_answer(PDO $pdo, int $userId, int $sessionQuestionId, string $selectedOption): array
{
    farmasi_quiz_require_schema($pdo);
    farmasi_quiz_finalize_previous_weeks($pdo);

    $selectedOption = strtolower(trim($selectedOption));
    if (!in_array($selectedOption, ['a', 'b', 'c', 'd', 'e'], true)) {
        throw new InvalidArgumentException('Jawaban quiz tidak valid.');
    }

    $session = farmasi_quiz_get_session_record($pdo, $userId);
    if (!$session) {
        throw new RuntimeException('Sesi quiz tidak aktif. Silakan muat ulang quiz.');
    }

    $pdo->beginTransaction();
    try {
        $stmtQuestion = $pdo->prepare("
            SELECT *
            FROM farmasi_quiz_session_questions
            WHERE id = ?
              AND session_id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $stmtQuestion->execute([$sessionQuestionId, (int)$session['id']]);
        $question = $stmtQuestion->fetch(PDO::FETCH_ASSOC);
        if (!$question) {
            throw new RuntimeException('Soal quiz tidak ditemukan pada sesi aktif.');
        }

        $alreadyAnswered = $question['selected_option'] !== null;
        $correctOption = strtolower((string)($question['correct_option'] ?? ''));
        $isCorrect = $selectedOption === $correctOption ? 1 : 0;

        if (!$alreadyAnswered) {
            $stmtUpdate = $pdo->prepare("
                UPDATE farmasi_quiz_session_questions
                SET selected_option = ?,
                    is_correct = ?,
                    answered_at = NOW()
                WHERE id = ?
            ");
            $stmtUpdate->execute([$selectedOption, $isCorrect, $sessionQuestionId]);
        } else {
            $selectedOption = strtolower((string)$question['selected_option']);
            $isCorrect = (int)($question['is_correct'] ?? 0);
        }

        $result = [
            'selected_option' => $selectedOption,
            'correct_option' => $correctOption,
            'is_correct' => $isCorrect === 1,
            'explanation' => (string)($question['explanation'] ?? ''),
            'completed' => false,
            'summary' => null,
        ];

        $stmtPending = $pdo->prepare("
            SELECT COUNT(*)
            FROM farmasi_quiz_session_questions
            WHERE session_id = ?
              AND selected_option IS NULL
        ");
        $stmtPending->execute([(int)$session['id']]);
        $pendingCount = (int)$stmtPending->fetchColumn();

        if ($pendingCount === 0) {
            $summary = farmasi_quiz_finalize_session($pdo, $session);
            $result['completed'] = true;
            $result['summary'] = $summary;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $result['state'] = farmasi_quiz_get_session_payload($pdo, $userId, false);
    return $result;
}
