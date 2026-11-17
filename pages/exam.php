<?php
/**
 * PAGE: one exam.
 * Students: rules and a Start button, then the timed exam (answers save on their own, see app.js),
 * then the result. The correct answers are never sent to students.
 * Lecturers and admins: everyone's attempts, essay grading and the answer key.
 */

$user = require_login();
$exam = one('SELECT e.*, c.code FROM exams e JOIN courses c ON c.id = e.course_id WHERE e.id = ?', [(int) ($_GET['id'] ?? 0)]) ?? fail('Not found', 404);
$role = require_course($user, (int) $exam['course_id']);
$questions = all('SELECT * FROM questions WHERE exam_id = ? ORDER BY position', [$exam['id']]);
$totalPoints = array_sum(array_column($questions, 'points'));
$back = '<a class="cluster small strong" style="color:var(--accent-text);min-height:44px" href="' . url('course', ['id' => $exam['course_id'], 'tab' => 'exams']) . '">' . icon('back', 16) . e($exam['code']) . ' exams</a>';

if (can_manage($role)):
    $attempts = all('SELECT x.*, u.name, u.avatar FROM exam_attempts x JOIN users u ON u.id = x.student_id WHERE x.exam_id = ? ORDER BY x.started_at', [$exam['id']]);
    foreach ($attempts as $i => $x) {
        if (!$x['submitted_at'] && attempt_expired($x)) { // the timer ran out while the student was away
            $attempts[$i] = finalize_attempt($x, true) + ['name' => $x['name'], 'avatar' => $x['avatar']];
        }
    }
    $essays = array_filter($questions, fn ($q) => $q['type'] === 'ESSAY');
    $essayMax = array_sum(array_column($essays, 'points'));
    page_start($exam['title'], $user, 'courses');
?>
    <?= $back ?>
    <h1 class="mt-1"><?= e($exam['title']) ?></h1>
    <p class="muted mt-1"><span class="mono"><?= e(fmt_datetime($exam['opens_at'])) ?></span> to <span class="mono"><?= e(fmt_datetime($exam['closes_at'])) ?></span> · <?= (int) $exam['duration_min'] ?> minutes · <span class="tnum"><?= $totalPoints ?> points</span></p>
    <div class="layout-main-side mt-8">
        <section>
            <?= section_head('Attempts · ' . count($attempts)) ?>
            <?= $attempts ? '' : '<p class="card card-pad muted">Nobody has started this exam yet.</p>' ?>
            <div class="stack-sm">
            <?php foreach ($attempts as $x): $answers = json_decode($x['answers'], true) ?: []; ?>
                <div class="card card-pad">
                    <div class="row" style="padding:0;min-height:0"><?= avatar($x['name'], $x['avatar']) ?>
                        <span class="row-main"><span class="strong"><?= e($x['name']) ?></span><span class="faint small"><?= $x['submitted_at'] ? 'Submitted <span class="mono">' . e(fmt_datetime($x['submitted_at'])) . '</span>' . ($x['auto_submitted'] ? ' (time ran out)' : '') : 'In progress' ?></span></span>
                        <?= $x['tab_switches'] ? badge($x['tab_switches'] . ' tab switches', 'warn') : '' ?>
                        <?= badge('Multiple choice: ' . ($x['mcq_points'] ?? '-')) ?>
                        <?= $x['score'] !== null ? badge($x['score'] . ' / 100', 'success') : ($x['submitted_at'] && $essays ? badge('Essay to mark', 'accent') : '') ?>
                    </div>
                    <?php if ($x['submitted_at'] && $essays): ?>
                        <details class="mt-3" <?= $x['score'] === null ? 'open' : '' ?>>
                            <summary class="small strong" style="cursor:pointer;color:var(--accent-text);min-height:36px">Read the essay answers</summary>
                            <div class="stack-sm mt-3">
                                <?php foreach ($essays as $q): ?>
                                    <div class="panel small"><p class="strong"><?= e($q['prompt']) ?> <span class="faint tnum">(<?= $q['points'] ?> points)</span></p>
                                        <p class="muted mt-2" style="white-space:pre-wrap"><?= e($answers[$q['id']] ?? 'No answer.') ?></p></div>
                                <?php endforeach; ?>
                                <?= form_start('grade_exam', ['class' => 'form-row']) ?>
                                    <input type="hidden" name="attempt_id" value="<?= (int) $x['id'] ?>">
                                    <label class="field"><span class="label">Essay points (out of <?= $essayMax ?>)</span><input class="input tnum" type="number" name="essay_points" min="0" max="<?= $essayMax ?>" required value="<?= e($x['essay_points'] ?? '') ?>"></label>
                                    <label class="field"><span class="label">Feedback</span><input class="input" name="feedback" value="<?= e($x['feedback'] ?? '') ?>"></label>
                                    <div><button class="btn btn-primary btn-sm" type="submit">Save mark</button></div>
                                </form>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        </section>
        <section>
            <?= section_head('Answer key') ?>
            <div class="card card-pad stack small">
                <?php foreach ($questions as $i => $q): $options = json_decode($q['options'], true); ?>
                    <div><p class="strong"><span class="faint tnum"><?= $i + 1 ?>.</span> <?= e($q['prompt']) ?> <span class="faint tnum">(<?= $q['points'] ?>)</span></p>
                        <p class="mt-1" style="color:var(--<?= $q['type'] === 'MCQ' ? 'success' : 'ink-3' ?>)"><?= $q['type'] === 'MCQ' ? e($options[$q['correct_index']] ?? '') : 'Essay, marked by hand' ?></p></div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
<?php
    page_end();
    return;
endif;

// ----- student view -----
$attempt = one('SELECT * FROM exam_attempts WHERE exam_id = ? AND student_id = ?', [$exam['id'], $user['id']]);
if ($attempt && !$attempt['submitted_at'] && attempt_expired($attempt)) {
    $attempt = finalize_attempt($attempt, true);
}
page_start($exam['title'], $user, 'courses');

if ($attempt && !$attempt['submitted_at']):
    $byId = array_column($questions, null, 'id');
    $answers = json_decode($attempt['answers'], true) ?: [];
    $ordered = array_values(array_filter(array_map(fn ($id) => $byId[$id] ?? null, json_decode($attempt['question_order'], true))));
?>
    <div id="exam" data-exam="<?= (int) $exam['id'] ?>" data-deadline="<?= gmdate('Y-m-d\TH:i:s\Z', ts($attempt['deadline_at'])) ?>" data-switches="<?= (int) $attempt['tab_switches'] ?>">
        <div class="exam-bar glass split">
            <div><p class="strong"><?= e($exam['title']) ?></p><p class="faint small tnum"><span data-answered>0</span> of <?= count($ordered) ?> answered · <span data-save-state>Saved</span></p></div>
            <span class="exam-timer mono" role="timer" aria-label="Time left" data-timer>--:--:--</span>
        </div>
        <p class="notice notice-warn" data-switch-warning hidden><?= icon('warn') ?><span></span></p>
        <?= form_start('submit_exam', ['class' => 'stack narrow']) ?>
            <input type="hidden" name="exam_id" value="<?= (int) $exam['id'] ?>">
            <?php foreach ($ordered as $i => $q): $options = json_decode($q['options'], true); ?>
                <fieldset class="card card-pad rise" style="--i:<?= $i ?>;border:1px solid var(--line);margin:0">
                    <legend class="sr-only">Question <?= $i + 1 ?></legend>
                    <div class="split"><p class="strong"><span class="faint tnum"><?= $i + 1 ?>.</span> <?= e($q['prompt']) ?></p><?= badge($q['points'] . ' points') ?></div>
                    <div class="stack-sm mt-4">
                        <?php if ($q['type'] === 'MCQ'): foreach ($options as $o => $text): ?>
                            <label class="option"><input type="radio" name="q[<?= $q['id'] ?>]" value="<?= $o ?>" data-question="<?= $q['id'] ?>" <?= isset($answers[$q['id']]) && is_numeric($answers[$q['id']]) && (int) $answers[$q['id']] === $o ? 'checked' : '' ?>><?= e($text) ?></label>
                        <?php endforeach; else: ?>
                            <textarea class="input" style="min-height:180px" maxlength="20000" data-question="<?= $q['id'] ?>" aria-label="Your answer to question <?= $i + 1 ?>" placeholder="Write your answer"><?= e(is_string($answers[$q['id']] ?? null) ? $answers[$q['id']] : '') ?></textarea>
                        <?php endif; ?>
                    </div>
                </fieldset>
            <?php endforeach; ?>
            <div class="cluster" style="justify-content:flex-end"><button class="btn btn-primary" type="submit" data-confirm="Submit now? You can't change your answers afterwards.">Submit exam</button></div>
        </form>
    </div>
<?php else: ?>
    <div class="narrow">
        <?= $back ?>
        <h1 class="mt-1"><?= e($exam['title']) ?></h1>
        <?php if ($exam['description']): ?><p class="muted mt-2"><?= e($exam['description']) ?></p><?php endif; ?>
        <?php if ($attempt): ?>
            <div class="card card-pad mt-8">
                <p class="faint small">Submitted <span class="mono"><?= e(fmt_datetime($attempt['submitted_at'])) ?></span><?= $attempt['auto_submitted'] ? ', automatically when the time ran out' : '' ?></p>
                <?php if ($attempt['score'] !== null): ?><p class="big-score tnum mt-3"><?= (int) $attempt['score'] ?><span> / 100</span></p>
                <?php else: ?><p class="strong mt-3">Multiple choice: <span class="tnum"><?= (int) $attempt['mcq_points'] ?></span> points. Your lecturer still has to mark the essay.</p><?php endif; ?>
                <?php if ($attempt['feedback']): ?><p class="panel mt-4"><?= e($attempt['feedback']) ?></p><?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card card-pad mt-8 stack">
                <ul class="stack-sm">
                    <li class="cluster" style="flex-wrap:nowrap;align-items:flex-start"><?= icon('clock') ?><span>You get <?= (int) $exam['duration_min'] ?> minutes from when you start, ending no later than <span class="mono"><?= e(fmt_datetime($exam['closes_at'])) ?></span>. The exam hands itself in at <span class="mono">00:00</span>.</span></li>
                    <li class="cluster" style="flex-wrap:nowrap;align-items:flex-start"><?= icon('save') ?><span>Your answers save as you go. There are <?= count($questions) ?> questions, in an order that is different for each student.</span></li>
                    <li class="cluster" style="flex-wrap:nowrap;align-items:flex-start"><span style="color:var(--warn)"><?= icon('warn') ?></span><span>If you switch to another tab or window, it is counted and your lecturer can see the count.</span></li>
                </ul>
                <?php if (time() < ts($exam['opens_at'])): ?><p class="panel">Opens in <?= countdown($exam['opens_at']) ?></p>
                <?php elseif (time() >= ts($exam['closes_at'])): ?><p class="panel muted">This exam has closed.</p>
                <?php else: ?><?= button_form('start_exam', ['exam_id' => $exam['id']], 'Start exam', 'btn btn-primary btn-block', 'Click again to start the timer') ?><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif;
page_end();
