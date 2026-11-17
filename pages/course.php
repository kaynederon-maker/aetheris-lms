<?php
// page: one course, with tabs.
//   overview    materials and announcements
//   assignments list (+ create form for the lecturer)
//   exams       list (+ link to write a new exam)
//   forum       questions and answers
//   chat        the class group chat (live, see app.js)
//   roster      students and their progress (lecturer and admin only)

$user = require_login();
$courseId = (int) ($_GET['id'] ?? 0);
$role = require_course($user, $courseId);
$manage = can_manage($role);
$course = one('SELECT c.*, u.name AS lecturer_name, u.avatar AS lecturer_avatar FROM courses c LEFT JOIN users u ON u.id = c.lecturer_id WHERE c.id = ?', [$courseId]);
$tabs = ['overview' => 'Overview', 'assignments' => 'Assignments', 'exams' => 'Exams', 'forum' => 'Forum', 'chat' => 'Group chat'] + ($manage ? ['roster' => 'Roster'] : []);
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'overview';
$uid = (int) $user['id'];

page_start($course['code'] . ' ' . $tabs[$tab], $user, $user['role'] === 'ADMIN' ? 'admin' : 'courses');
?>
<header class="card course-head">
    <div class="cover"><?= banner($course) ?></div>
    <div class="body">
        <div>
            <p class="faint small strong"><?= e($course['code']) ?></p>
            <h1><?= e($course['title']) ?></h1>
            <p class="muted mt-1"><?= e($course['description']) ?></p>
        </div>
        <div class="cluster">
            <?php if ($course['lecturer_name']): ?><span class="who small"><?= avatar($course['lecturer_name'], $course['lecturer_avatar'], 30) ?><span class="strong"><?= e($course['lecturer_name']) ?></span></span><?php endif; ?>
            <?php if ($manage): ?><span class="panel small">Join code <span class="mono strong"><?= e(format_join_code($course['join_code'])) ?></span></span><?php endif; ?>
        </div>
    </div>
</header>
<nav class="tabs" aria-label="Course sections"><div class="segmented">
    <?php foreach ($tabs as $key => $label): ?>
        <a href="<?= url('course', ['id' => $courseId, 'tab' => $key]) ?>" <?= $key === $tab ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</div></nav>

<?php if ($tab === 'overview'):
    $materials = all('SELECT m.*, f.original_name, f.size FROM materials m LEFT JOIN files f ON f.id = m.file_id WHERE m.course_id = ? ORDER BY m.position', [$courseId]);
    $news = all('SELECT * FROM announcements WHERE course_id = ? ORDER BY created_at DESC LIMIT 5', [$courseId]);
?>
<div class="layout-main-side">
    <section>
        <?= section_head('Learning materials', $manage ? dialog_button('add-material', 'Add material', 'btn btn-primary btn-sm', 'upload') : '') ?>
        <?php if (!$materials): ?>
            <?= empty_state('folder', 'No materials yet', $manage ? 'Add notes or upload slides for your students.' : 'Your lecturer has not published anything yet.') ?>
        <?php else: ?>
            <ol class="card list">
                <?php foreach ($materials as $i => $m): ?>
                    <li class="row rise" style="--i:<?= $i ?>">
                        <a class="cluster grow" style="flex-wrap:nowrap;min-width:0" href="<?= url('material', ['id' => $m['id']]) ?>">
                            <span class="empty-icon" style="width:40px;height:40px;margin:0"><?= icon($m['file_id'] ? 'clip' : 'file', 17) ?></span>
                            <span class="row-main"><span class="strong truncate"><?= e($m['title']) ?></span>
                                <span class="faint small"><?= $m['file_id'] ? e(strtoupper(pathinfo($m['original_name'], PATHINFO_EXTENSION)) . ' · ' . bytes_label((int) $m['size'])) : 'Notes' ?></span></span>
                        </a>
                        <?php if ($manage): ?>
                            <span class="cluster" style="gap:0">
                                <?= button_form('move_material', ['material_id' => $m['id'], 'direction' => 'up'], icon('up', 16), 'btn btn-ghost icon-btn', '', 'Move up') ?>
                                <?= button_form('move_material', ['material_id' => $m['id'], 'direction' => 'down'], icon('down', 16), 'btn btn-ghost icon-btn', '', 'Move down') ?>
                                <?= button_form('delete_material', ['material_id' => $m['id']], icon('trash', 16), 'btn btn-ghost icon-btn', 'Delete?', 'Delete material') ?>
                            </span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
    <section>
        <?= section_head('Announcements', $manage ? dialog_button('post-news', 'Post', 'btn btn-secondary btn-sm', 'megaphone') : '') ?>
        <?php foreach ($news as $n): ?>
            <div class="card card-pad mt-3"><p class="strong"><?= e($n['title']) ?></p><p class="faint small"><?= e(relative_time($n['created_at'])) ?></p><div class="prose mt-3"><?= markdown($n['body']) ?></div></div>
        <?php endforeach; ?>
        <?= $news ? '' : empty_state('megaphone', 'No announcements') ?>
    </section>
</div>
<?php if ($manage): ?>
    <?= dialog_start('add-material', 'Add learning material') ?>
    <?= form_start('add_material', ['files' => true]) ?>
        <input type="hidden" name="course_id" value="<?= $courseId ?>">
        <label class="field"><span class="label">Title</span><input class="input" name="title" required minlength="2" placeholder="Week 04: Hash tables"></label>
        <label class="field"><span class="label">Notes (Markdown, optional)</span><textarea class="input" name="body" placeholder="## Key ideas"></textarea></label>
        <label class="field"><span class="label">File (optional)</span><input class="input" type="file" name="file" accept=".pdf,.pptx,.docx,.zip,.md,.txt,.py,.java,.c,.cpp,.js,.ts"><span class="hint">PDF, slides, Word, ZIP or source files up to 10 MB. The file content is checked against its extension.</span></label>
        <button class="btn btn-primary" type="submit">Publish</button>
    </form>
    <?= dialog_end() ?>
    <?= dialog_start('post-news', 'Post an announcement') ?>
    <?= form_start('post_announcement') ?>
        <input type="hidden" name="course_id" value="<?= $courseId ?>">
        <label class="field"><span class="label">Title</span><input class="input" name="title" required minlength="3"></label>
        <label class="field"><span class="label">Message (Markdown)</span><textarea class="input" name="body" required></textarea></label>
        <button class="btn btn-primary" type="submit">Post</button>
    </form>
    <?= dialog_end() ?>
<?php endif; ?>

<?php elseif ($tab === 'assignments'):
    $enrolled = (int) value('SELECT COUNT(*) FROM enrollments WHERE course_id = ?', [$courseId]);
    $assignments = all('SELECT a.*, (SELECT COUNT(*) FROM submissions s WHERE s.assignment_id = a.id) AS handed_in,
        (SELECT COUNT(*) FROM submissions s WHERE s.assignment_id = a.id AND s.score IS NULL) AS to_grade,
        (SELECT score FROM submissions s WHERE s.assignment_id = a.id AND s.student_id = ?) AS my_score,
        (SELECT COUNT(*) FROM submissions s WHERE s.assignment_id = a.id AND s.student_id = ?) AS mine
        FROM assignments a WHERE a.course_id = ? ORDER BY a.due_at DESC', [$uid, $uid, $courseId]);
?>
    <?= section_head('Assignments', $manage ? dialog_button('new-assignment', 'New assignment', 'btn btn-primary btn-sm', 'plus') : '') ?>
    <?php if (!$assignments): ?><?= empty_state('clipboard', 'No assignments yet') ?><?php else: ?>
        <div class="card list">
            <?php foreach ($assignments as $a): $open = ts($a['due_at']) > time(); ?>
                <a class="row" href="<?= url('assignment', ['id' => $a['id']]) ?>">
                    <span class="row-main"><span class="strong"><?= e($a['title']) ?></span><span class="faint small">Due <span class="mono"><?= e(fmt_datetime($a['due_at'])) ?></span></span></span>
                    <?php if ($manage): ?><span class="small muted tnum"><?= $a['handed_in'] ?>/<?= $enrolled ?> handed in · <?= $a['to_grade'] ?> to grade</span>
                    <?php elseif ($a['mine']): ?><?= $a['my_score'] !== null ? badge($a['my_score'] . ' / 100', 'success') : badge('Handed in', 'accent') ?>
                    <?php elseif (!$open): ?><?= badge('Missed', 'danger') ?><?php endif; ?>
                    <?= $open ? countdown($a['due_at']) : '' ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($manage): ?>
        <?= dialog_start('new-assignment', 'New assignment') ?>
        <?= form_start('create_assignment') ?>
            <input type="hidden" name="course_id" value="<?= $courseId ?>">
            <label class="field"><span class="label">Title</span><input class="input" name="title" required minlength="3"></label>
            <label class="field"><span class="label">Instructions (Markdown)</span><textarea class="input" name="description" required></textarea></label>
            <label class="field"><span class="label">Deadline</span><input class="input" name="due_at" type="datetime-local" required></label>
            <fieldset style="border:0;padding:0;margin:0"><legend class="label">Accepted files</legend><div class="cluster">
                <?php foreach (FILE_KINDS as $kind => $info): ?><label class="chip"><input type="checkbox" name="types[]" value="<?= $kind ?>" <?= $kind === 'pdf' ? 'checked' : '' ?>> <?= e($info['label']) ?></label><?php endforeach; ?>
            </div></fieldset>
            <label class="field"><span class="label">Rubric (optional)</span><textarea class="input mono small" name="rubric" style="min-height:96px" placeholder="Correctness | 50&#10;Analysis | 30&#10;Code quality | 20"></textarea><span class="hint">One line per criterion, written as "Criterion | points". The points must add up to 100.</span></label>
            <button class="btn btn-primary" type="submit">Create assignment</button>
        </form>
        <?= dialog_end() ?>
    <?php endif; ?>

<?php elseif ($tab === 'exams'):
    $exams = all('SELECT e.*, (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.id) AS questions,
        (SELECT COUNT(*) FROM exam_attempts x WHERE x.exam_id = e.id AND x.submitted_at IS NOT NULL) AS handed_in
        FROM exams e WHERE e.course_id = ? ORDER BY e.opens_at DESC', [$courseId]);
?>
    <?= section_head('Exams', $manage ? '<a class="btn btn-primary btn-sm" href="' . url('exam-new', ['course' => $courseId]) . '">' . icon('plus', 15) . 'New exam</a>' : '') ?>
    <?php if (!$exams): ?><?= empty_state('timer', 'No exams scheduled') ?><?php else: ?>
        <div class="card list">
            <?php foreach ($exams as $x):
                $mine = $manage ? null : one('SELECT submitted_at, score FROM exam_attempts WHERE exam_id = ? AND student_id = ?', [$x['id'], $uid]);
                $open = ts($x['opens_at']) <= time() && time() < ts($x['closes_at']); ?>
                <a class="row" href="<?= url('exam', ['id' => $x['id']]) ?>">
                    <span class="row-main"><span class="strong"><?= e($x['title']) ?></span>
                        <span class="faint small"><span class="tnum"><?= $x['questions'] ?> questions · <?= $x['duration_min'] ?> min</span> · <span class="mono"><?= e(fmt_datetime($x['opens_at'])) ?></span> to <span class="mono"><?= e(fmt_datetime($x['closes_at'])) ?></span></span></span>
                    <?php if ($manage): ?><span class="small muted tnum"><?= $x['handed_in'] ?> submitted</span>
                    <?php elseif ($mine && $mine['submitted_at']): ?><?= badge($mine['score'] !== null ? $mine['score'] . ' / 100' : 'Submitted, essay being marked', 'success') ?>
                    <?php elseif ($mine): ?><?= badge('In progress', 'warn') ?>
                    <?php elseif ($open): ?><?= badge('Open', 'accent') ?>
                    <?php else: ?><?= badge(ts($x['opens_at']) > time() ? 'Opens later' : 'Closed', ts($x['opens_at']) > time() ? '' : 'danger') ?><?php endif; ?>
                    <?= $open ? countdown($x['closes_at']) : '' ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php elseif ($tab === 'forum'):
    $threads = all('SELECT t.*, u.name, u.avatar, (SELECT COUNT(*) FROM forum_posts p WHERE p.thread_id = t.id) AS replies,
        (SELECT COUNT(*) FROM forum_posts p WHERE p.thread_id = t.id AND p.verified = 1) AS verified
        FROM forum_threads t JOIN users u ON u.id = t.author_id WHERE t.course_id = ? ORDER BY t.created_at DESC', [$courseId]);
?>
    <?= section_head('Course forum', dialog_button('new-thread', 'Ask a question', 'btn btn-primary btn-sm', 'plus')) ?>
    <?php if (!$threads): ?><?= empty_state('question', 'No questions yet', 'Questions asked here stay available to the whole class.') ?><?php else: ?>
        <div class="card list">
            <?php foreach ($threads as $t): ?>
                <a class="row" href="<?= url('thread', ['id' => $t['id']]) ?>"><?= avatar($t['name'], $t['avatar']) ?>
                    <span class="row-main"><span class="strong truncate"><?= e($t['title']) ?></span><span class="faint small"><?= e($t['name']) ?> · <?= e(relative_time($t['created_at'])) ?></span></span>
                    <?= $t['verified'] ? badge('Verified answer', 'highlight') : '' ?>
                    <span class="small faint tnum"><?= $t['replies'] ?> <?= $t['replies'] == 1 ? 'reply' : 'replies' ?></span></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?= dialog_start('new-thread', 'Ask a question') ?>
    <?= form_start('new_thread') ?>
        <input type="hidden" name="course_id" value="<?= $courseId ?>">
        <label class="field"><span class="label">Question</span><input class="input" name="title" required minlength="5" maxlength="160" placeholder="What exactly are you stuck on?"></label>
        <label class="field"><span class="label">Details (Markdown)</span><textarea class="input" name="body" required placeholder="What you tried, and where it breaks"></textarea></label>
        <button class="btn btn-primary" type="submit">Post question</button>
    </form>
    <?= dialog_end() ?>

<?php elseif ($tab === 'chat'):
    $messages = array_reverse(all('SELECT m.*, u.name, u.role FROM chat_messages m JOIN users u ON u.id = m.author_id WHERE m.course_id = ? ORDER BY m.id DESC LIMIT 50', [$courseId]));
?>
    <div class="card chat" id="chat" data-course="<?= $courseId ?>" data-me="<?= $uid ?>" data-last="<?= $messages ? end($messages)['id'] : 0 ?>">
        <div class="chat-log" aria-live="polite">
            <?php if (!$messages): ?><p class="center faint small" data-empty>No messages yet. Say hello to your class.</p><?php endif; ?>
            <?php foreach ($messages as $m): $mine = (int) $m['author_id'] === $uid; ?>
                <div class="msg <?= $mine ? 'mine' : '' ?>"><div>
                    <?php if (!$mine): ?><p class="msg-meta strong"><?= e($m['name']) ?><?= $m['role'] === 'LECTURER' ? ' · Lecturer' : '' ?></p><?php endif; ?>
                    <div class="msg-bubble"><?= e($m['body']) ?></div><p class="msg-meta mono"><?= e(fmt_time($m['created_at'])) ?></p>
                </div></div>
            <?php endforeach; ?>
        </div>
        <form class="chat-form" data-chat-form>
            <label class="sr-only" for="chat-input">Message the class</label>
            <input class="input" id="chat-input" maxlength="2000" autocomplete="off" placeholder="Message the class">
            <button class="btn btn-primary icon-btn" type="submit" aria-label="Send"><?= icon('up', 18) ?></button>
        </form>
    </div>

<?php elseif ($tab === 'roster'):
    $assignmentCount = (int) value('SELECT COUNT(*) FROM assignments WHERE course_id = ?', [$courseId]);
    $students = all('SELECT u.*, en.created_at AS joined,
        (SELECT COUNT(*) FROM submissions s JOIN assignments a ON a.id = s.assignment_id WHERE a.course_id = :c AND s.student_id = u.id) AS handed_in,
        (SELECT ROUND(AVG(s.score)) FROM submissions s JOIN assignments a ON a.id = s.assignment_id WHERE a.course_id = :c AND s.student_id = u.id AND s.score IS NOT NULL) AS average
        FROM enrollments en JOIN users u ON u.id = en.user_id WHERE en.course_id = :c ORDER BY u.name', ['c' => $courseId]);
?>
    <?= section_head('Roster · ' . count($students) . ' students') ?>
    <?php if (!$students): ?><?= empty_state('users', 'No students yet', 'Share the join code shown above.') ?><?php else: ?>
        <div class="card table-wrap"><table class="table">
            <thead><tr><th>Student</th><th>Student no.</th><th>Joined</th><th class="right">Handed in</th><th class="right">Average</th></tr></thead>
            <tbody><?php foreach ($students as $s): ?>
                <tr><td><span class="who"><?= avatar($s['name'], $s['avatar'], 32) ?><span><span class="strong"><?= e($s['name']) ?></span><?= $s['active'] ? '' : ' ' . badge('Inactive', 'danger') ?><br><span class="faint"><?= e($s['email']) ?></span></span></span></td>
                    <td><?= e($s['student_number'] ?? '-') ?></td><td><?= e(fmt_date($s['joined'])) ?></td>
                    <td class="right"><?= $s['handed_in'] ?>/<?= $assignmentCount ?></td><td class="right strong"><?= $s['average'] ?? '-' ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
<?php endif;
page_end();
