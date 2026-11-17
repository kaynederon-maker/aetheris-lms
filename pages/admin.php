<?php
// page (admin only): user accounts and classes, on two tabs.

$admin = require_role('ADMIN');
$tab = ($_GET['tab'] ?? '') === 'classes' ? 'classes' : 'users';
$newLecturer = $_SESSION['new_lecturer'] ?? null; // the temporary password is shown this one time only
unset($_SESSION['new_lecturer']);

page_start($tab === 'users' ? 'Users' : 'Classes', $admin, 'admin');
echo page_header('Users and classes', 'Create lecturer accounts and classes. Switching an account off signs it out everywhere at once.',
    $tab === 'users' ? dialog_button('new-lecturer', 'Create lecturer', 'btn btn-primary', 'user-plus') : dialog_button('new-class', 'New class', 'btn btn-primary', 'plus'));
?>
<nav class="tabs" aria-label="Admin sections"><div class="segmented">
    <a href="<?= url('admin') ?>" <?= $tab === 'users' ? 'aria-current="page"' : '' ?>>Users</a>
    <a href="<?= url('admin', ['tab' => 'classes']) ?>" <?= $tab === 'classes' ? 'aria-current="page"' : '' ?>>Classes</a>
</div></nav>

<?php if ($newLecturer): ?>
    <div class="card card-pad" style="margin-bottom:24px;border-color:var(--accent)">
        <p class="strong">Sign-in details for the new lecturer</p>
        <p class="muted small mt-1">Give these to the lecturer privately. This password is shown only once, and they must change it the first time they sign in.</p>
        <div class="panel mt-3"><p class="small faint">Email</p><p class="mono"><?= e($newLecturer['email']) ?></p>
            <p class="small faint mt-2">Temporary password</p><p class="cluster"><span class="mono" style="font-size:1.125rem" id="temp-password"><?= e($newLecturer['password']) ?></span>
            <button type="button" class="btn btn-ghost btn-sm" data-copy="#temp-password"><?= icon('copy', 15) ?>Copy</button></p></div>
    </div>
<?php endif; ?>

<?php if ($tab === 'users'):
    $role = in_array($_GET['role'] ?? '', ['STUDENT', 'LECTURER', 'ADMIN'], true) ? $_GET['role'] : '';
    $users = all('SELECT * FROM users' . ($role ? ' WHERE role = ?' : '') . ' ORDER BY role, name', $role ? [$role] : []);
?>
    <div class="segmented" style="margin-bottom:16px" aria-label="Filter by role">
        <?php foreach (['' => 'Everyone', 'STUDENT' => 'Students', 'LECTURER' => 'Lecturers', 'ADMIN' => 'Admins'] as $value => $label): ?>
            <a href="<?= url('admin', $value ? ['role' => $value] : []) ?>" <?= $role === $value ? 'aria-current="page"' : '' ?>><?= $label ?></a>
        <?php endforeach; ?>
    </div>
    <div class="card table-wrap"><table class="table">
        <thead><tr><th>User</th><th>Role</th><th>Joined</th><th class="right">Access</th></tr></thead>
        <tbody><?php foreach ($users as $u): ?>
            <tr><td><span class="who"><?= avatar($u['name'], $u['avatar'], 32) ?><span><span class="strong"><?= e($u['name']) ?></span><br><span class="faint"><?= e($u['email']) ?></span></span></span></td>
                <td><?= badge(strtolower($u['role']), ['LECTURER' => 'accent', 'ADMIN' => 'highlight'][$u['role']] ?? '') ?><?= $u['must_change_password'] ? ' ' . badge('Temporary password', 'warn') : '' ?></td>
                <td><?= e(fmt_date($u['created_at'])) ?></td>
                <td class="right"><?php if ((int) $u['id'] === (int) $admin['id']): ?><span class="faint">You</span>
                    <?php elseif ($u['active']): ?><?= button_form('set_user_active', ['user_id' => $u['id'], 'active' => 0], 'Deactivate', 'btn btn-danger btn-sm', 'Confirm') ?>
                    <?php else: ?><?= button_form('set_user_active', ['user_id' => $u['id'], 'active' => 1], 'Reactivate', 'btn btn-secondary btn-sm') ?><?php endif; ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?= dialog_start('new-lecturer', 'Create a lecturer account') ?>
    <?= form_start('create_lecturer') ?>
        <label class="field"><span class="label">Full name</span><input class="input" name="name" required minlength="2"></label>
        <label class="field"><span class="label">Campus email</span><input class="input" type="email" name="email" required></label>
        <p class="hint">A temporary password is generated and shown once on the next screen.</p>
        <button class="btn btn-primary" type="submit">Create account</button>
    </form>
    <?= dialog_end() ?>

<?php else:
    $lecturers = all("SELECT id, name FROM users WHERE role = 'LECTURER' AND active = 1 ORDER BY name");
    $courses = all('SELECT c.*, (SELECT COUNT(*) FROM enrollments en WHERE en.course_id = c.id) AS students FROM courses c ORDER BY c.code');
    $options = fn ($selected) => implode('', array_map(fn ($l) => '<option value="' . $l['id'] . '"' . ((int) $l['id'] === (int) $selected ? ' selected' : '') . '>' . e($l['name']) . '</option>', $lecturers));
?>
    <div class="stack-sm">
        <?php foreach ($courses as $i => $c): ?>
            <div class="card card-pad rise split" style="--i:<?= $i ?>;align-items:flex-end">
                <a href="<?= url('course', ['id' => $c['id']]) ?>" style="min-width:220px"><span class="faint small strong"><?= e($c['code']) ?></span><br><span class="strong" style="font-size:1.0625rem"><?= e($c['title']) ?></span><br><span class="muted small tnum"><?= $c['students'] ?> students</span></a>
                <span class="cluster"><span class="panel small">Join code <span class="mono strong"><?= e(format_join_code($c['join_code'])) ?></span></span>
                    <?= button_form('update_course', ['course_id' => $c['id'], 'regenerate_code' => 1], icon('refresh', 16), 'btn btn-ghost icon-btn', 'New code?', 'Make a new join code') ?></span>
                <?= form_start('update_course', ['class' => 'cluster']) ?><input type="hidden" name="course_id" value="<?= (int) $c['id'] ?>">
                    <label class="field" style="min-width:220px"><span class="label">Lecturer</span><select class="input" name="lecturer_id" required><?= $c['lecturer_id'] ? '' : '<option value="" selected disabled>Choose a lecturer</option>' ?><?= $options($c['lecturer_id']) ?></select></label>
                    <button class="btn btn-secondary" type="submit" style="align-self:flex-end">Assign</button></form>
            </div>
        <?php endforeach; ?>
    </div>
    <?= dialog_start('new-class', 'Create a class') ?>
    <?= form_start('create_course') ?>
        <div class="form-row"><label class="field"><span class="label">Code</span><input class="input" name="code" required pattern="[A-Za-z]{4}\d{4}" placeholder="COMP6200" style="text-transform:uppercase"></label>
            <label class="field"><span class="label">Title</span><input class="input" name="title" required minlength="3"></label></div>
        <label class="field"><span class="label">Description</span><textarea class="input" name="description" style="min-height:80px"></textarea></label>
        <label class="field"><span class="label">Lecturer</span><select class="input" name="lecturer_id"><option value="">Assign later</option><?= $options(0) ?></select></label>
        <p class="hint">A join code is created automatically.</p>
        <button class="btn btn-primary" type="submit">Create class</button>
    </form>
    <?= dialog_end() ?>
<?php endif;
page_end();
