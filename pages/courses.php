<?php
/** PAGE: the student's classes (with the join form) or the classes a lecturer teaches. Admins manage classes on the admin page. */

$user = require_login();
if ($user['role'] === 'ADMIN') {
    redirect(url('admin', ['tab' => 'classes']));
}
$student = $user['role'] === 'STUDENT';
$courses = $student
    ? all('SELECT c.*, u.name AS lecturer_name FROM courses c JOIN enrollments en ON en.course_id = c.id AND en.user_id = ? LEFT JOIN users u ON u.id = c.lecturer_id ORDER BY c.code', [$user['id']])
    : all('SELECT c.*, u.name AS lecturer_name FROM courses c LEFT JOIN users u ON u.id = c.lecturer_id WHERE c.lecturer_id = ? ORDER BY c.code', [$user['id']]);

page_start('Courses', $user, 'courses');
echo page_header($student ? 'Courses' : 'My courses', $student ? 'The classes you are in. Join another one with the code from your lecturer.' : 'The classes you teach, with the join codes to give your students.');
?>
<?php if ($student): ?>
    <div class="card card-pad" style="max-width:560px;margin-bottom:40px">
        <?= form_start('join_course', ['class' => 'cluster']) ?>
            <label class="field grow"><span class="label">Class join code</span><input class="input input-code" name="code" required placeholder="DSTR-7Q4K" maxlength="14" autocomplete="off" spellcheck="false"><span class="hint">Spaces, dashes and lowercase letters are fine.</span></label>
            <button class="btn btn-primary" type="submit">Join class</button>
        </form>
    </div>
<?php endif; ?>

<?php if (!$courses): ?>
    <?= empty_state('book', $student ? 'No classes yet' : 'No classes assigned', $student ? 'Enter a join code above to add your first class.' : 'An administrator assigns classes to lecturers.') ?>
<?php else: ?>
    <div class="grid-3">
        <?php foreach ($courses as $i => $c):
            $counts = one('SELECT (SELECT COUNT(*) FROM materials WHERE course_id = :id) m, (SELECT COUNT(*) FROM assignments WHERE course_id = :id) a, (SELECT COUNT(*) FROM enrollments WHERE course_id = :id) s', ['id' => $c['id']]); ?>
            <a class="card course-card rise" style="--i:<?= $i ?>" href="<?= url('course', ['id' => $c['id']]) ?>">
                <div class="cover"><?= banner($c) ?></div>
                <div class="body">
                    <p class="faint small strong"><?= e($c['code']) ?></p>
                    <h2 class="mt-1"><?= e($c['title']) ?></h2>
                    <p class="muted small mt-1"><?= e($c['lecturer_name'] ?? 'Lecturer not assigned yet') ?></p>
                    <p class="faint small tnum mt-4"><?= $counts['m'] ?> materials · <?= $counts['a'] ?> assignments<?= $student ? '' : ' · ' . $counts['s'] . ' students' ?></p>
                    <?php if (!$student): ?><p class="small mt-3">Join code <span class="mono strong"><?= e(format_join_code($c['join_code'])) ?></span></p><?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php page_end(); ?>
