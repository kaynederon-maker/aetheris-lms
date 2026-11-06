<?php
/**
 * FILE DOWNLOADS: index.php?action=download&id=5
 * Course materials: anyone in the course. Submissions: the student who handed it in,
 * the course lecturer, or an admin. Everyone else gets "not found".
 */

function action_download(): void
{
    $user = action_user();
    $id = (int) ($_GET['id'] ?? 0);
    $file = one('SELECT * FROM files WHERE id = ?', [$id]);
    $allowed = false;
    if ($file) {
        $material = one('SELECT course_id FROM materials WHERE file_id = ?', [$id]);
        $submission = one('SELECT s.student_id, a.course_id FROM submissions s JOIN assignments a ON a.id = s.assignment_id WHERE s.file_id = ?', [$id]);
        if ($material) {
            $allowed = course_role($user, (int) $material['course_id']) !== null;
        } elseif ($submission) {
            $allowed = (int) $submission['student_id'] === (int) $user['id'] || can_manage(course_role($user, (int) $submission['course_id']));
        }
    }
    if (!$allowed) {
        audit('file_access_denied', "file $id");
        fail('File not found.', 404);
    }
    send_file($file, ($_GET['inline'] ?? '') === '1');
}
