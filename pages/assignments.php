<?php
/** PAGE: all assignments across the user's classes. Students see what is due; lecturers see what needs grading. */

$user = require_role('STUDENT', 'LECTURER');
$uid = (int) $user['id'];

function assignment_rows(array $rows, callable $right): string
{
    $html = '<div class="card list">';
    foreach ($rows as $a) {
        $html .= '<a class="row" href="' . url('assignment', ['id' => $a['id']]) . '"><span class="row-main"><span class="strong">' . e($a['title']) . '</span>'
            . '<span class="faint small">' . e($a['code']) . ' · due <span class="mono">' . e(fmt_datetime($a['due_at'])) . '</span></span></span>' . $right($a) . '</a>';
    }
    return $html . '</div>';
}

page_start('Assignments', $user, 'assignments');

if ($user['role'] === 'LECTURER'):
    $rows = all('SELECT a.*, c.code, (SELECT COUNT(*) FROM enrollments en WHERE en.course_id = c.id) AS enrolled,
        (SELECT COUNT(*) FROM submissions s WHERE s.assignment_id = a.id) AS handed_in,
        (SELECT COUNT(*) FROM submissions s WHERE s.assignment_id = a.id AND s.score IS NULL) AS to_grade
        FROM assignments a JOIN courses c ON c.id = a.course_id WHERE c.lecturer_id = ? ORDER BY a.due_at DESC', [$uid]);
    echo page_header('Grading', 'Who has handed in what, across your courses. Open an assignment to download the work and grade it.');
    echo $rows ? assignment_rows($rows, fn ($a) => '<span class="cluster small tnum"><span class="muted">' . $a['handed_in'] . '/' . $a['enrolled'] . ' handed in</span>'
        . ($a['to_grade'] ? badge($a['to_grade'] . ' to grade', 'warn') : badge('All graded', 'success')) . '</span>')
        : empty_state('clipboard', 'No assignments yet', 'Create one from the Assignments tab of a course.');
else:
    $rows = all('SELECT a.*, c.code, s.score, s.id AS submission_id FROM assignments a JOIN courses c ON c.id = a.course_id
        JOIN enrollments en ON en.course_id = c.id AND en.user_id = ? LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = ? ORDER BY a.due_at', [$uid, $uid]);
    $todo = array_filter($rows, fn ($a) => !$a['submission_id'] && ts($a['due_at']) > time());
    $handedIn = array_filter($rows, fn ($a) => $a['submission_id']);
    $missed = array_filter($rows, fn ($a) => !$a['submission_id'] && ts($a['due_at']) <= time());
    echo page_header('Assignments', 'Everything due across your classes. Uploads close automatically at each deadline.');
    echo '<div class="stack-lg"><section>' . section_head('To do · ' . count($todo))
        . ($todo ? assignment_rows($todo, fn ($a) => countdown($a['due_at'])) : empty_state('clipboard', 'Nothing to do right now')) . '</section>';
    if ($handedIn) {
        echo '<section>' . section_head('Handed in · ' . count($handedIn))
            . assignment_rows($handedIn, fn ($a) => $a['score'] !== null ? badge($a['score'] . ' / 100', 'success') : badge('Waiting for a grade', 'accent')) . '</section>';
    }
    if ($missed) {
        echo '<section>' . section_head('Missed · ' . count($missed)) . assignment_rows($missed, fn ($a) => badge('Not handed in', 'danger')) . '</section>';
    }
    echo '</div>';
endif;
page_end();
