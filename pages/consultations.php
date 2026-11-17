<?php
// page: consultation requests.
// Students see their own requests and can make a new one. Lecturers see the requests sent to them.
// Admins see the support desk queue.

$user = require_login();
$uid = (int) $user['id'];
[$where, $params] = match ($user['role']) {
    'STUDENT' => ['c.student_id = ?', [$uid]],
    'LECTURER' => ["c.recipient_type = 'LECTURER' AND c.recipient_id = ? AND co.lecturer_id = ?", [$uid, $uid]],
    default => ["c.recipient_type = 'SUPPORT'", []],
};
$rows = all("SELECT c.*, s.name AS student_name, r.name AS recipient_name, co.code FROM consultations c JOIN users s ON s.id = c.student_id
             LEFT JOIN users r ON r.id = c.recipient_id LEFT JOIN courses co ON co.id = c.course_id WHERE $where ORDER BY c.updated_at DESC", $params);
$open = array_filter($rows, fn ($r) => sop_is_open($r['status']));
$closed = array_filter($rows, fn ($r) => !sop_is_open($r['status']));
$myCourses = $user['role'] === 'STUDENT'
    ? all('SELECT c.id, c.code, u.name AS lecturer FROM courses c JOIN enrollments en ON en.course_id = c.id AND en.user_id = ? LEFT JOIN users u ON u.id = c.lecturer_id ORDER BY c.code', [$uid])
    : [];

function request_rows(array $rows, array $user): string
{
    $mediumIcon = ['VIDEO' => 'video', 'VOICE' => 'phone', 'CHAT' => 'chat'];
    $html = '<div class="card list">';
    foreach ($rows as $i => $r) {
        $other = $user['role'] === 'STUDENT' ? ($r['recipient_type'] === 'SUPPORT' ? 'Support desk' : ($r['recipient_name'] ?? 'Lecturer')) : $r['student_name'];
        $html .= '<a class="row rise" style="--i:' . min($i, 8) . '" href="' . url('consultation', ['id' => $r['id']]) . '"><span class="empty-icon" style="width:40px;height:40px;margin:0">' . icon($mediumIcon[$r['medium']], 18) . '</span>'
            . '<span class="row-main"><span class="strong truncate">' . e($r['subject']) . '</span><span class="faint small"><span class="mono">SOP-' . (int) $r['id'] . '</span> · ' . e($other) . ($r['code'] ? ' · ' . e($r['code']) : '') . '</span></span>'
            . status_badge($r['status']) . '</a>';
    }
    return $html . '</div>';
}

$titles = ['STUDENT' => ['Consultations', 'Book time with a lecturer or the support desk, and follow each request step by step.'],
    'LECTURER' => ['Consultation inbox', 'Opening a request marks it as read for the student. A decline always needs a reason.'],
    'ADMIN' => ['Support queue', 'Requests sent to academic and technical support.']];

page_start($titles[$user['role']][0], $user, 'consultations');
echo page_header($titles[$user['role']][0], $titles[$user['role']][1], $user['role'] === 'STUDENT' ? dialog_button('new-request', 'New request', 'btn btn-primary', 'plus') : '');
?>
<div class="stack-lg">
    <section>
        <?= section_head('Open · ' . count($open)) ?>
        <?= $open ? request_rows($open, $user) : '<p class="card card-pad center muted">' . ($user['role'] === 'STUDENT' ? 'No open requests. Use New request to book a session.' : 'Nothing waiting for you.') . '</p>' ?>
    </section>
    <?php if ($closed): ?><section><?= section_head('Finished · ' . count($closed)) ?><?= request_rows($closed, $user) ?></section><?php endif; ?>
</div>

<?php if ($user['role'] === 'STUDENT'): ?>
    <?= dialog_start('new-request', 'New consultation request') ?>
    <?= form_start('request_consultation') ?>
        <fieldset style="border:0;padding:0;margin:0"><legend class="label">Who do you need?</legend>
            <div class="segmented full">
                <label><input class="sr-only" type="radio" name="recipient_type" value="LECTURER" checked>My course lecturer</label>
                <label><input class="sr-only" type="radio" name="recipient_type" value="SUPPORT">Academic or technical support</label>
            </div></fieldset>
        <label class="field"><span class="label">Course</span><select class="input" name="course_id">
            <option value="">Not about one course</option>
            <?php foreach ($myCourses as $i => $c): ?><option value="<?= (int) $c['id'] ?>" <?= $i === 0 ? 'selected' : '' ?>><?= e($c['code'] . ' · ' . ($c['lecturer'] ?? 'no lecturer yet')) ?></option><?php endforeach; ?>
        </select><span class="hint">Requests for a lecturer go to the lecturer of this course.</span></label>
        <div class="form-row">
            <label class="field"><span class="label">Type of request</span><select class="input" name="category"><?php foreach (CATEGORY_LABELS as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span class="label">How to meet</span><select class="input" name="medium"><?php foreach (MEDIUM_LABELS as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
        </div>
        <label class="field"><span class="label">Subject</span><input class="input" name="subject" required minlength="5" maxlength="160"></label>
        <label class="field"><span class="label">Details</span><textarea class="input" name="details" required minlength="20" placeholder="What you need, what you have tried, and any deadline."></textarea><span class="hint">At least 20 characters.</span></label>
        <label class="field"><span class="label">Preferred time (optional)</span><input class="input" type="datetime-local" name="preferred_at"></label>
        <button class="btn btn-primary" type="submit">Send request</button>
    </form>
    <?= dialog_end() ?>
<?php endif;
page_end();
