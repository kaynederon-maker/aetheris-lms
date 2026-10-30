<?php
/** PAGE: one month of deadlines, exam windows and confirmed consultations. A grid on big screens, a list on phones. */

$user = require_login();
$uid = (int) $user['id'];
$month = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : local_date(null)->format('Y-m');
$first = new DateTime("$month-01", new DateTimeZone(APP_TIMEZONE));
$from = (clone $first)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$to = (clone $first)->modify('+1 month')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

[$courseJoin, $courseParams] = match ($user['role']) {
    'STUDENT' => ['JOIN enrollments en ON en.course_id = c.id AND en.user_id = ?', [$uid]],
    'LECTURER' => ['AND c.lecturer_id = ?', [$uid]],
    default => ['', []],
};
$events = [];
foreach (all("SELECT a.id, a.title, a.due_at, c.code FROM assignments a JOIN courses c ON c.id = a.course_id $courseJoin WHERE a.due_at >= ? AND a.due_at < ?", [...$courseParams, $from, $to]) as $a) {
    $events[] = ['at' => $a['due_at'], 'title' => $a['title'], 'code' => $a['code'], 'kind' => 'Deadline', 'tone' => 'deadline', 'href' => url('assignment', ['id' => $a['id']])];
}
foreach (all("SELECT e.id, e.title, e.opens_at, e.closes_at, c.code FROM exams e JOIN courses c ON c.id = e.course_id $courseJoin
              WHERE (e.opens_at >= ? AND e.opens_at < ?) OR (e.closes_at >= ? AND e.closes_at < ?)", [...$courseParams, $from, $to, $from, $to]) as $x) {
    foreach ([['opens_at', 'Exam opens', 'opens'], ['closes_at', 'Exam closes', 'closes']] as [$field, $kind, $tone]) {
        if ($x[$field] >= $from && $x[$field] < $to) {
            $events[] = ['at' => $x[$field], 'title' => $x['title'], 'code' => $x['code'], 'kind' => $kind, 'tone' => $tone, 'href' => url('exam', ['id' => $x['id']])];
        }
    }
}
[$sopWhere, $sopParams] = match ($user['role']) {
    'STUDENT' => ['student_id = ?', [$uid]],
    'LECTURER' => ['recipient_id = ?', [$uid]],
    default => ["recipient_type = 'SUPPORT'", []],
};
foreach (all("SELECT id, subject, scheduled_at FROM consultations WHERE status = 'APPROVED_SCHEDULED' AND $sopWhere AND scheduled_at >= ? AND scheduled_at < ?", [...$sopParams, $from, $to]) as $s) {
    $events[] = ['at' => $s['scheduled_at'], 'title' => $s['subject'], 'code' => '', 'kind' => 'Consultation', 'tone' => 'meeting', 'href' => url('consultation', ['id' => $s['id']])];
}
usort($events, fn ($a, $b) => strcmp($a['at'], $b['at']));
$byDay = [];
foreach ($events as $ev) {
    $byDay[local_date($ev['at'])->format('j')][] = $ev;
}

$today = local_date(null);
$isThisMonth = $today->format('Y-m') === $month;
$lead = (int) $first->format('N') - 1; // Monday first
$days = (int) $first->format('t');
$prev = (clone $first)->modify('-1 month')->format('Y-m');
$next = (clone $first)->modify('+1 month')->format('Y-m');

page_start('Calendar', $user, 'calendar');
echo page_header('Calendar', 'Deadlines, exam windows and confirmed consultations in one place.',
    '<a class="btn btn-ghost icon-btn" href="' . url('calendar', ['m' => $prev]) . '" aria-label="Previous month">' . icon('back', 19) . '</a>'
    . '<span class="strong" style="min-width:150px;text-align:center">' . e($first->format('F Y')) . '</span>'
    . '<a class="btn btn-ghost icon-btn" href="' . url('calendar', ['m' => $next]) . '" aria-label="Next month">' . icon('next', 19) . '</a>');
?>
<div class="cluster" style="margin-bottom:20px" aria-label="Legend">
    <span class="badge tone-deadline">Deadline</span><span class="badge tone-opens">Exam opens</span><span class="badge tone-closes">Exam closes</span><span class="badge tone-meeting">Consultation</span>
</div>

<div class="card cal-month" style="padding:8px">
    <div class="cal-grid"><?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d): ?><div class="cal-head"><?= $d ?></div><?php endforeach; ?></div>
    <div class="cal-grid">
        <?php for ($i = 0; $i < $lead; $i++): ?><div class="cal-day blank"></div><?php endfor; ?>
        <?php for ($d = 1; $d <= $days; $d++): $list = $byDay[$d] ?? []; ?>
            <div class="cal-day <?= $isThisMonth && (int) $today->format('j') === $d ? 'today' : '' ?>">
                <span class="cal-num tnum"><?= $d ?></span>
                <?php foreach (array_slice($list, 0, 3) as $ev): ?><a class="cal-event tone-<?= $ev['tone'] ?>" href="<?= $ev['href'] ?>" title="<?= e($ev['kind'] . ': ' . $ev['title']) ?>"><?= e(($ev['code'] ? $ev['code'] . ' · ' : '') . $ev['title']) ?></a><?php endforeach; ?>
                <?php if (count($list) > 3): ?><p class="small faint tnum">+<?= count($list) - 3 ?> more</p><?php endif; ?>
            </div>
        <?php endfor; ?>
    </div>
</div>

<div class="cal-agenda stack">
    <?php if (!$byDay): ?><p class="card card-pad muted">Nothing scheduled this month.</p><?php endif; ?>
    <?php foreach ($byDay as $day => $list): ?>
        <div><p class="small strong muted" style="margin-bottom:8px"><?= e((clone $first)->setDate((int) $first->format('Y'), (int) $first->format('n'), $day)->format('l j M')) ?></p>
            <div class="card list"><?php foreach ($list as $ev): ?>
                <a class="row" href="<?= $ev['href'] ?>"><span class="mono small faint"><?= e(fmt_time($ev['at'])) ?></span><span class="row-main"><span class="strong truncate"><?= e($ev['title']) ?></span><span class="faint small"><?= e($ev['code'] ?: 'Consultation') ?></span></span><span class="badge tone-<?= $ev['tone'] ?>"><?= e($ev['kind']) ?></span></a>
            <?php endforeach; ?></div></div>
    <?php endforeach; ?>
</div>
<?php page_end(); ?>
