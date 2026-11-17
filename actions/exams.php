<?php
/*
 * Exam actions
 * ============
 * Lecturers write and grade exams; students start, autosave and submit.
 * Students never receive the correct answers (see pages/exam.php).
 */

function action_create_exam(): void
{
    $user = action_user('LECTURER', 'ADMIN');
    $courseId = (int) input('course_id');
    require_course($user, $courseId, true);
    $title = text_input('title', 'Title', 3, 160);
    $description = text_input('description', 'Instructions', 0, 4000);
    $opensAt = datetime_input('opens_at', 'Opening time');
    $closesAt = datetime_input('closes_at', 'Closing time');
    if (ts($closesAt) <= ts($opensAt)) {
        fail('The closing time must be after the opening time.', 422);
    }
    $duration = int_input('duration_min', 'Duration', 1, 300);

    $questions = [];
    foreach (array_values((array) (input('questions') ?? [])) as $n => $q) {
        $number = $n + 1;
        $type = ($q['type'] ?? '') === 'ESSAY' ? 'ESSAY' : 'MCQ';
        $prompt = clean_text((string) ($q['prompt'] ?? ''));
        $points = (int) ($q['points'] ?? 0);
        if (mb_strlen($prompt) < 3 || $points < 1 || $points > 100) {
            fail("Question $number needs a prompt and 1 to 100 points.", 422);
        }
        $options = [];
        $correct = null;
        if ($type === 'MCQ') {
            $options = array_values(array_filter(array_map(fn ($o) => mb_substr(clean_text((string) $o), 0, 300), (array) ($q['options'] ?? [])), 'strlen'));
            $correct = (int) ($q['correct'] ?? -1);
            if (count($options) < 2 || $correct < 0 || $correct >= count($options)) {
                fail("Question $number needs at least two options and a correct answer.", 422);
            }
        }
        $questions[] = ['type' => $type, 'prompt' => $prompt, 'points' => $points, 'options' => json_encode($options), 'correct_index' => $correct, 'position' => $number];
    }
    if (!$questions) {
        fail('Add at least one question.', 422);
    }

    $examId = insert('exams', ['course_id' => $courseId, 'title' => $title, 'description' => $description, 'opens_at' => $opensAt, 'closes_at' => $closesAt, 'duration_min' => $duration, 'created_at' => now()]);
    foreach ($questions as $q) {
        insert('questions', $q + ['exam_id' => $examId]);
    }
    audit('exam_created', $title);
    done('Exam published.', url('course', ['id' => $courseId, 'tab' => 'exams']), ['id' => $examId]);
}

function exam_and_attempt(array $user): array
{
    $exam = one('SELECT * FROM exams WHERE id = ?', [(int) input('exam_id')]) ?? fail('Exam not found.', 404);
    require_course($user, (int) $exam['course_id']);
    $attempt = one('SELECT * FROM exam_attempts WHERE exam_id = ? AND student_id = ?', [$exam['id'], $user['id']]);
    return [$exam, $attempt];
}

/** Starting creates the attempt with its own shuffled question order and a fixed deadline. */
function action_start_exam(): void
{
    $user = action_user('STUDENT');
    [$exam, $attempt] = exam_and_attempt($user);
    $back = url('exam', ['id' => $exam['id']]);
    if ($attempt) {
        done('Welcome back. Your answers are saved.', $back);
    }
    if (time() < ts($exam['opens_at'])) {
        fail('This exam has not opened yet.', 403);
    }
    if (time() >= ts($exam['closes_at'])) {
        fail('This exam has closed.', 403);
    }
    $ids = array_map('intval', array_column(all('SELECT id FROM questions WHERE exam_id = ?', [$exam['id']]), 'id'));
    insert('exam_attempts', [
        'exam_id' => $exam['id'], 'student_id' => $user['id'], 'question_order' => json_encode(shuffle_secure($ids)),
        'started_at' => now(), 'deadline_at' => gmdate('Y-m-d H:i:s', attempt_deadline(time(), (int) $exam['duration_min'], ts($exam['closes_at']))),
    ]);
    audit('exam_started', $exam['title']);
    done('The timer has started. Good luck!', $back);
}

/** Autosave from app.js. Also counts tab switches. Refused once time is up. */
function action_save_exam(): void
{
    $user = action_user('STUDENT');
    limit_or_fail('autosave:' . $user['id'], 150, 60, 'Saving too often. Your last answers are kept.');
    [, $attempt] = exam_and_attempt($user);
    if (!$attempt) {
        fail('Start the exam first.', 404);
    }
    if ($attempt['submitted_at']) {
        fail('This exam has already been submitted.', 409);
    }
    if (attempt_expired($attempt)) {
        finalize_attempt($attempt, true);
        fail('Time is up. Your exam was submitted automatically.', 409);
    }
    $allowed = array_map('intval', json_decode($attempt['question_order'], true));
    $answers = json_decode($attempt['answers'], true) ?: [];
    foreach ((array) (input('answers') ?? []) as $questionId => $answer) {
        if (!in_array((int) $questionId, $allowed, true)) {
            continue; // only questions from this student's own exam
        }
        $answers[(int) $questionId] = is_int($answer) ? max(0, min(10, $answer)) : mb_substr(clean_text((string) $answer), 0, 20000);
    }
    query('UPDATE exam_attempts SET answers = ?, tab_switches = tab_switches + ? WHERE id = ?', [json_encode($answers), input('tab_switch') ? 1 : 0, $attempt['id']]);
    json_out(['ok' => true, 'saved_at' => gmdate('c')]);
}

function action_submit_exam(): void
{
    $user = action_user('STUDENT');
    [$exam, $attempt] = exam_and_attempt($user);
    if (!$attempt) {
        fail('Start the exam first.', 404);
    }
    $done = finalize_attempt($attempt, time() >= ts($attempt['deadline_at']));
    audit('exam_submitted', $exam['title'] . ' (tab switches: ' . $done['tab_switches'] . ')');
    done('Exam submitted.', url('exam', ['id' => $exam['id']]));
}

function action_grade_exam(): void
{
    $user = action_user('LECTURER', 'ADMIN');
    $attempt = one('SELECT a.*, e.course_id FROM exam_attempts a JOIN exams e ON e.id = a.exam_id WHERE a.id = ?', [(int) input('attempt_id')])
        ?? fail('Attempt not found.', 404);
    require_course($user, (int) $attempt['course_id'], true);
    if (!$attempt['submitted_at']) {
        fail('The student has not submitted yet.', 409);
    }
    $questions = all('SELECT id, type, correct_index, points FROM questions WHERE exam_id = ?', [$attempt['exam_id']]);
    $essayMax = array_sum(array_map(fn ($q) => $q['type'] === 'ESSAY' ? (int) $q['points'] : 0, $questions));
    $essay = int_input('essay_points', 'Essay points', 0, $essayMax);
    $score = score_attempt($questions, json_decode($attempt['answers'], true) ?: []);
    $final = final_score($score['mcq'], $essay, $score['total'], $score['has_essay']);
    update('exam_attempts', (int) $attempt['id'], ['essay_points' => $essay, 'mcq_points' => $score['mcq'], 'score' => $final, 'feedback' => text_input('feedback', 'Feedback', 0, 5000) ?: null]);
    audit('exam_graded', "attempt {$attempt['id']}: $final");
    done("Exam graded: $final / 100.");
}
