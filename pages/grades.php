<?php
/**
 * PAGE: a student's own grades. Every query uses the signed-in student's id, and the page takes no
 * id from the address bar, so there is nothing to change to see someone else's results.
 */

$user = require_role('STUDENT');
$uid = (int) $user['id'];
$subs = all('SELECT s.*, a.title, a.rubric, a.id AS assignment_id, c.code FROM submissions s JOIN assignments a ON a.id = s.assignment_id
             JOIN courses c ON c.id = a.course_id WHERE s.student_id = ? ORDER BY s.submitted_at DESC', [$uid]);
$exams = all('SELECT x.*, e.title, c.code FROM exam_attempts x JOIN exams e ON e.id = x.exam_id JOIN courses c ON c.id = e.course_id
              WHERE x.student_id = ? AND x.submitted_at IS NOT NULL ORDER BY x.submitted_at DESC', [$uid]);
$scores = array_filter([...array_column($subs, 'score'), ...array_column($exams, 'score')], fn ($s) => $s !== null);
$pending = count($subs) + count($exams) - count($scores);

page_start('Grades', $user, 'grades');
echo page_header('Grades and feedback', 'Only you can see these results.');
?>
<div class="stats" style="max-width:640px;margin-bottom:40px">
    <?= stat_card('chart', 'Average', $scores ? (string) round(array_sum($scores) / count($scores)) : '-') ?>
    <?= stat_card('done', 'Graded', count($scores)) ?>
    <?= stat_card('hourglass', 'Waiting', $pending) ?>
</div>
<?php if (!$subs && !$exams): ?>
    <?= empty_state('cap', 'No grades yet', 'Your scores show up here once your lecturer has marked your work.') ?>
<?php else: ?>
    <div class="stack-lg">
        <section>
            <?= section_head('Assignments') ?>
            <div class="stack-sm">
                <?php foreach ($subs as $s): $rubric = json_decode($s['rubric'], true) ?: []; $parts = $s['rubric_scores'] ? json_decode($s['rubric_scores'], true) : null; ?>
                    <div class="card card-pad">
                        <div class="split"><a href="<?= url('assignment', ['id' => $s['assignment_id']]) ?>"><span class="faint small"><?= e($s['code']) ?> · handed in <?= e(fmt_date($s['submitted_at'])) ?></span><br><span class="strong"><?= e($s['title']) ?></span></a>
                            <?= $s['score'] !== null ? '<span class="big-score tnum" style="font-size:1.5rem">' . (int) $s['score'] . '<span> / 100</span></span>' : badge('Waiting for a grade', 'accent') ?></div>
                        <?php if ($parts): ?><div class="cluster mt-3 small tnum"><?php foreach ($rubric as $i => $r): ?><span class="panel" style="padding:6px 12px"><?= e($r['criterion']) ?> <span class="strong"><?= (int) $parts[$i] ?>/<?= $r['max'] ?></span></span><?php endforeach; ?></div><?php endif; ?>
                        <?php if ($s['feedback']): ?><p class="muted mt-3" style="white-space:pre-wrap"><?= e($s['feedback']) ?></p><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php if ($exams): ?>
            <section>
                <?= section_head('Exams') ?>
                <div class="stack-sm">
                    <?php foreach ($exams as $x): ?>
                        <a class="card card-pad split" href="<?= url('exam', ['id' => $x['exam_id']]) ?>"><span><span class="faint small"><?= e($x['code']) ?></span><br><span class="strong"><?= e($x['title']) ?></span></span>
                            <?= $x['score'] !== null ? '<span class="big-score tnum" style="font-size:1.5rem">' . (int) $x['score'] . '<span> / 100</span></span>' : badge('Essay being marked', 'accent') ?></a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
<?php endif;
page_end();
