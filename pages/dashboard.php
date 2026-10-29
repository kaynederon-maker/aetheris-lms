<?php
/** PAGE: the first screen after signing in. Students, lecturers and admins each see their own version. */

$user = require_login();
$hour = (int) local_date(null)->format('G');
$greeting = ($hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening')) . ', ' . explode(' ', $user['name'])[0];
$today = local_date(null)->format('l j F');
$uid = (int) $user['id'];

function course_cards(array $courses): string
{
    $html = '<div class="grid-3">';
    foreach ($courses as $i => $c) {
        $html .= '<a class="card course-card rise" style="--i:' . $i . '" href="' . url('course', ['id' => $c['id']]) . '"><div class="cover">' . banner($c) . '</div>'
            . '<div class="body"><p class="faint small strong">' . e($c['code']) . '</p><p class="strong mt-1">' . e($c['title']) . '</p>'
            . '<p class="muted small mt-1">' . e($c['lecturer_name'] ?? 'Lecturer not assigned yet') . '</p></div></a>';
    }
    return $html . '</div>';
}

$courseQuery = 'SELECT c.*, u.name AS lecturer_name FROM courses c LEFT JOIN users u ON u.id = c.lecturer_id';

page_start('Today', $user, 'dashboard');

if ($user['role'] === 'STUDENT'):
    $courses = all("$courseQuery JOIN enrollments en ON en.course_id = c.id AND en.user_id = ? ORDER BY c.code", [$uid]);
    $due = all("SELECT a.*, c.code FROM assignments a JOIN courses c ON c.id = a.course_id JOIN enrollments en ON en.course_id = c.id AND en.user_id = ?
                WHERE a.due_at > ? AND NOT EXISTS (SELECT 1 FROM submissions s WHERE s.assignment_id = a.id AND s.student_id = ?) ORDER BY a.due_at LIMIT 6", [$uid, now(), $uid]);
    $exams = all("SELECT e.*, c.code FROM exams e JOIN courses c ON c.id = e.course_id JOIN enrollments en ON en.course_id = c.id AND en.user_id = ?
                  WHERE e.opens_at <= ? AND e.closes_at > ? AND NOT EXISTS (SELECT 1 FROM exam_attempts x WHERE x.exam_id = e.id AND x.student_id = ? AND x.submitted_at IS NOT NULL)
                  ORDER BY e.closes_at", [$uid, now(), now(), $uid]);
    $requests = all("SELECT c.*, u.name AS recipient_name FROM consultations c LEFT JOIN users u ON u.id = c.recipient_id WHERE c.student_id = ? ORDER BY c.updated_at DESC LIMIT 4", [$uid]);
    $news = all("SELECT a.*, c.code FROM announcements a JOIN courses c ON c.id = a.course_id JOIN enrollments en ON en.course_id = c.id AND en.user_id = ? ORDER BY a.created_at DESC LIMIT 3", [$uid]);
?>
    <?= page_header($greeting, $today) ?>
    <div class="layout-main-side">
        <div class="stack-lg">
            <section>
                <?= section_head('Active coursework & deadlines', '<a href="' . url('assignments') . '">All assignments</a>') ?>
                <?php if (!$due && !$exams): ?>
                    <?= empty_state('clipboard', 'Nothing due right now', 'New assignments and open exams will show up here.') ?>
                <?php else: ?>
                    <div class="card list">
                        <?php foreach ($exams as $x): ?>
                            <a class="row" href="<?= url('exam', ['id' => $x['id']]) ?>"><?= badge('Exam', 'accent') ?>
                                <span class="row-main"><span class="strong"><?= e($x['title']) ?></span><span class="faint small"><?= e($x['code']) ?> · <?= (int) $x['duration_min'] ?> minutes once started</span></span>
                                <span class="right"><span class="faint small" style="display:block">Closes in</span><?= countdown($x['closes_at']) ?></span></a>
                        <?php endforeach; ?>
                        <?php foreach ($due as $a): ?>
                            <a class="row" href="<?= url('assignment', ['id' => $a['id']]) ?>">
                                <span class="row-main"><span class="strong"><?= e($a['title']) ?></span><span class="faint small"><?= e($a['code']) ?> · due <span class="mono"><?= e(fmt_datetime($a['due_at'])) ?></span></span></span>
                                <?= countdown($a['due_at']) ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
            <section>
                <?= section_head('Your classes', '<a href="' . url('courses') . '">Join a class</a>') ?>
                <?= $courses ? course_cards($courses) : empty_state('book', 'You have not joined a class yet', 'Ask your lecturer for a join code and enter it on the Courses page.') ?>
            </section>
        </div>
        <div class="stack-lg">
            <section>
                <?= section_head('Consultation status', '<a href="' . url('consultations') . '">Open</a>') ?>
                <?php if ($requests): ?>
                    <div class="card list">
                        <?php foreach ($requests as $r): ?>
                            <a class="row" href="<?= url('consultation', ['id' => $r['id']]) ?>"><span class="row-main"><span class="strong truncate"><?= e($r['subject']) ?></span>
                                <span class="faint small"><?= e($r['recipient_name'] ?? 'Support desk') ?> · <?= e(relative_time($r['updated_at'])) ?></span></span><?= status_badge($r['status']) ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <?= empty_state('inbox', 'No requests yet', 'Book time with a lecturer or the support desk from Consultations.') ?>
                <?php endif; ?>
            </section>
            <section>
                <?= section_head('Announcements') ?>
                <?php foreach ($news as $n): ?>
                    <div class="card card-pad mt-3"><p class="faint small"><?= e($n['code']) ?> · <?= e(relative_time($n['created_at'])) ?></p>
                        <p class="strong mt-1"><?= e($n['title']) ?></p><div class="prose small muted mt-1"><?= markdown($n['body']) ?></div></div>
                <?php endforeach; ?>
                <?= $news ? '' : empty_state('megaphone', 'No announcements') ?>
            </section>
        </div>
    </div>

<?php elseif ($user['role'] === 'LECTURER'):
    $courses = all("$courseQuery WHERE c.lecturer_id = ? ORDER BY c.code", [$uid]);
    $students = value('SELECT COUNT(*) FROM enrollments en JOIN courses c ON c.id = en.course_id WHERE c.lecturer_id = ?', [$uid]);
    $toGrade = all('SELECT s.*, u.name AS student_name, a.title, a.id AS assignment_id, c.code FROM submissions s JOIN assignments a ON a.id = s.assignment_id
                    JOIN courses c ON c.id = a.course_id JOIN users u ON u.id = s.student_id WHERE c.lecturer_id = ? AND s.score IS NULL ORDER BY s.submitted_at LIMIT 6', [$uid]);
    $inbox = all("SELECT c.*, u.name AS student_name FROM consultations c JOIN users u ON u.id = c.student_id JOIN courses co ON co.id = c.course_id
                  WHERE c.recipient_id = ? AND co.lecturer_id = ? AND c.status NOT IN ('COMPLETED', 'DECLINED') ORDER BY c.created_at LIMIT 5", [$uid, $uid]);
?>
    <?= page_header($greeting, $today) ?>
    <div class="stats"><?= stat_card('book', 'Courses', count($courses)) . stat_card('users', 'Students', $students) . stat_card('clipboard', 'Waiting for a grade', count($toGrade)) . stat_card('inbox', 'Open consultations', count($inbox)) ?></div>
    <div class="grid-2 mt-10">
        <section>
            <?= section_head('Needs grading', '<a href="' . url('assignments') . '">Grading</a>') ?>
            <?php if ($toGrade): ?>
                <div class="card list"><?php foreach ($toGrade as $s): ?>
                    <a class="row" href="<?= url('assignment', ['id' => $s['assignment_id']]) ?>"><span class="row-main"><span class="strong"><?= e($s['student_name']) ?></span><span class="faint small"><?= e($s['code'] . ' · ' . $s['title']) ?></span></span>
                        <span class="mono small faint"><?= e(fmt_datetime($s['submitted_at'])) ?></span></a>
                <?php endforeach; ?></div>
            <?php else: ?><?= empty_state('clipboard', 'All caught up', 'New submissions will show up here.') ?><?php endif; ?>
        </section>
        <section>
            <?= section_head('Consultation inbox', '<a href="' . url('consultations') . '">Open inbox</a>') ?>
            <?php if ($inbox): ?>
                <div class="card list"><?php foreach ($inbox as $c): ?>
                    <a class="row" href="<?= url('consultation', ['id' => $c['id']]) ?>"><span class="row-main"><span class="strong truncate"><?= e($c['subject']) ?></span><span class="faint small"><?= e($c['student_name']) ?> · <?= e(relative_time($c['created_at'])) ?></span></span><?= status_badge($c['status']) ?></a>
                <?php endforeach; ?></div>
            <?php else: ?><?= empty_state('inbox', 'No open requests') ?><?php endif; ?>
        </section>
    </div>
    <section class="mt-10"><?= section_head('Your courses') ?><?= course_cards($courses) ?></section>

<?php else:
    $expected = (int) value('SELECT COUNT(*) FROM assignments a JOIN enrollments en ON en.course_id = a.course_id WHERE a.due_at < ?', [now()]);
    $handedIn = (int) value('SELECT COUNT(*) FROM submissions s JOIN assignments a ON a.id = s.assignment_id WHERE a.due_at < ?', [now()]);
    $logs = all('SELECT l.*, u.name AS actor FROM audit_log l LEFT JOIN users u ON u.id = l.actor_id ORDER BY l.id DESC LIMIT 8');
?>
    <?= page_header('Overview', $today) ?>
    <div class="stats">
        <?= stat_card('book', 'Classes', value('SELECT COUNT(*) FROM courses')) ?>
        <?= stat_card('user-cog', 'Lecturers', value("SELECT COUNT(*) FROM users WHERE role = 'LECTURER'")) ?>
        <?= stat_card('cap', 'Students', value("SELECT COUNT(*) FROM users WHERE role = 'STUDENT'")) ?>
        <?= stat_card('clipboard', 'Submission rate', ($expected ? round(100 * $handedIn / $expected) : 100) . '%', 'Assignments past their deadline') ?>
        <?= stat_card('inbox', 'Open consultations', value("SELECT COUNT(*) FROM consultations WHERE status NOT IN ('COMPLETED', 'DECLINED')")) ?>
    </div>
    <section class="mt-10">
        <?= section_head('Latest security events', '<a href="' . url('admin-log') . '">Full log</a>') ?>
        <div class="card list"><?php foreach ($logs as $l): ?>
            <div class="row"><span style="color:var(<?= preg_match('/fail|denied|rejected|limited|blocked|deactivated/', $l['action']) ? '--danger' : '--ink-3' ?>)"><?= icon('shield', 17) ?></span>
                <span class="row-main"><span class="strong"><?= e($l['action']) ?></span><span class="faint small"><?= e(($l['actor'] ?? 'Not signed in') . ($l['target'] ? ' · ' . $l['target'] : '')) ?></span></span>
                <span class="mono small faint"><?= e(fmt_datetime($l['created_at'])) ?></span></div>
        <?php endforeach; ?></div>
    </section>
<?php endif;
page_end();
