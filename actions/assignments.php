<?php
/**
 * ASSIGNMENT ACTIONS: lecturers create and grade, students hand in files.
 */

function action_create_assignment(): void
{
    $user = action_user('LECTURER', 'ADMIN');
    $courseId = (int) input('course_id');
    require_course($user, $courseId, true);
    $title = text_input('title', 'Title', 3, 160);
    $description = text_input('description', 'Instructions', 1, 10000, false);
    $dueAt = datetime_input('due_at', 'Deadline');
    if (ts($dueAt) <= time()) {
        fail('The deadline must be in the future.', 422);
    }
    $types = array_values(array_intersect(array_keys(FILE_KINDS), (array) (input('types') ?? [])));
    if (!$types) {
        fail('Pick at least one accepted file type.', 422);
    }
    try {
        $rubric = parse_rubric((string) (input('rubric') ?? ''));
    } catch (InvalidArgumentException $err) {
        fail($err->getMessage(), 422);
    }
    $id = insert('assignments', [
        'course_id' => $courseId, 'title' => $title, 'description' => $description, 'due_at' => $dueAt,
        'allowed_types' => implode(',', $types), 'rubric' => json_encode($rubric), 'created_at' => now(),
    ]);
    audit('assignment_created', $title);
    done('Assignment created.', null, ['id' => $id]);
}

/** The deadline is checked here, against the server clock. The locked upload box in the page is only a convenience. */
function action_submit_assignment(): void
{
    $user = action_user('STUDENT');
    $assignment = one('SELECT * FROM assignments WHERE id = ?', [(int) input('assignment_id')]) ?? fail('Assignment not found.', 404);
    require_course($user, (int) $assignment['course_id']);
    if (time() > ts($assignment['due_at'])) {
        audit('late_submission_rejected', $assignment['title']);
        fail('The deadline has passed. Submissions for this assignment are closed.', 403);
    }
    limit_or_fail('upload:' . $user['id'], 20, 600, 'Too many uploads. Wait a few minutes and try again.');

    $fileId = save_upload('file', explode(',', $assignment['allowed_types']), (int) $user['id']) ?? fail('Choose a file to upload.', 422);
    $previous = one('SELECT * FROM submissions WHERE assignment_id = ? AND student_id = ?', [$assignment['id'], $user['id']]);
    try {
        if ($previous) {
            // A new upload replaces the old one and clears any grade.
            update('submissions', (int) $previous['id'], ['file_id' => $fileId, 'submitted_at' => now(), 'score' => null, 'rubric_scores' => null, 'feedback' => null, 'graded_at' => null]);
            delete_file((int) $previous['file_id']);
        } else {
            insert('submissions', ['assignment_id' => $assignment['id'], 'student_id' => $user['id'], 'file_id' => $fileId, 'submitted_at' => now()]);
        }
    } catch (PDOException $err) {
        delete_file($fileId); // e.g. two uploads at the same moment: keep the first, drop this file
        fail('Another upload for this assignment is in progress. Refresh and try again.', 409);
    }
    audit($previous ? 'submission_replaced' : 'submission_created', $assignment['title']);
    done('Submitted. Your file has been received.');
}

function action_grade_submission(): void
{
    $user = action_user('LECTURER', 'ADMIN');
    $submission = one('SELECT s.*, a.course_id, a.rubric FROM submissions s JOIN assignments a ON a.id = s.assignment_id WHERE s.id = ?', [(int) input('submission_id')])
        ?? fail('Submission not found.', 404);
    require_course($user, (int) $submission['course_id'], true);
    $rubric = json_decode($submission['rubric'], true) ?: [];
    $rubricScores = null;
    if ($rubric) {
        $parts = array_values((array) (input('rubric_scores') ?? []));
        if (count($parts) !== count($rubric)) {
            fail('Score every rubric criterion.', 422);
        }
        foreach ($rubric as $i => $row) {
            if (!is_numeric($parts[$i]) || (int) $parts[$i] < 0 || (int) $parts[$i] > $row['max']) {
                fail("{$row['criterion']} is scored from 0 to {$row['max']}.", 422);
            }
            $parts[$i] = (int) $parts[$i];
        }
        $rubricScores = json_encode($parts);
        $score = array_sum($parts);
    } else {
        $score = int_input('score', 'Score', 0, 100);
    }
    update('submissions', (int) $submission['id'], [
        'score' => $score, 'rubric_scores' => $rubricScores,
        'feedback' => text_input('feedback', 'Feedback', 0, 5000) ?: null, 'graded_at' => now(),
    ]);
    audit('submission_graded', "submission {$submission['id']}: $score");
    done("Grade saved: $score / 100.", null, ['score' => $score]);
}
