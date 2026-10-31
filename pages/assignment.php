<?php
/**
 * PAGE: one assignment.
 * Students: instructions, upload box, and their grade once it is marked.
 * Lecturers and admins: who handed in, downloads, and the grading form.
 */

$user = require_login();
$a = one('SELECT a.*, c.code, c.title AS course_title FROM assignments a JOIN courses c ON c.id = a.course_id WHERE a.id = ?', [(int) ($_GET['id'] ?? 0)]) ?? fail('Not found', 404);
$role = require_course($user, (int) $a['course_id']);
$rubric = json_decode($a['rubric'], true) ?: [];
$kinds = explode(',', $a['allowed_types']);
$open = ts($a['due_at']) > time();
$extensions = array_merge(...array_map(fn ($k) => FILE_KINDS[$k]['exts'], $kinds));

page_start($a['title'], $user, 'assignments');
?>
<a class="cluster small strong" style="color:var(--accent-text);min-height:44px" href="<?= url('course', ['id' => $a['course_id'], 'tab' => 'assignments']) ?>"><?= icon('back', 16) ?><?= e($a['code'] . ' · ' . $a['course_title']) ?></a>
<div class="page-header" style="margin-top:4px">
    <div><h1><?= e($a['title']) ?></h1><p class="muted">Due <span class="mono"><?= e(fmt_datetime($a['due_at'])) ?></span> · <?= e(implode(', ', array_map(fn ($k) => FILE_KINDS[$k]['label'], $kinds))) ?></p></div>
    <?php if ($open): ?><span class="panel" style="font-size:1.125rem"><?= countdown($a['due_at']) ?></span><?php endif; ?>
</div>

<?php
$brief = '<div class="card card-pad"><div class="prose">' . markdown($a['description']) . '</div>';
if ($rubric) {
    $brief .= '<table class="table mt-6"><caption class="strong" style="text-align:left;margin-bottom:6px">Rubric</caption><tbody>';
    foreach ($rubric as $r) {
        $brief .= '<tr><td>' . e($r['criterion']) . '</td><td class="right muted">' . $r['max'] . ' points</td></tr>';
    }
    $brief .= '</tbody></table>';
}
$brief .= '</div>';
?>

<?php if (!can_manage($role)):
    $sub = one('SELECT s.*, f.original_name, f.size FROM submissions s JOIN files f ON f.id = s.file_id WHERE s.assignment_id = ? AND s.student_id = ?', [$a['id'], $user['id']]);
    $parts = $sub && $sub['rubric_scores'] ? json_decode($sub['rubric_scores'], true) : null;
?>
    <div class="layout-main-side">
        <?= $brief ?>
        <div class="stack-lg">
            <section>
                <?= section_head('Your submission') ?>
                <div class="card card-pad stack">
                    <?php if ($sub): ?>
                        <div class="panel split"><span class="cluster"><span style="color:var(--success)"><?= icon('done', 20) ?></span><span><span class="strong"><?= e($sub['original_name']) ?></span><br>
                            <span class="faint small"><?= e(bytes_label((int) $sub['size'])) ?> · <span class="mono"><?= e(fmt_datetime($sub['submitted_at'])) ?></span></span></span></span>
                            <a class="btn btn-ghost icon-btn" href="index.php?action=download&amp;id=<?= (int) $sub['file_id'] ?>" aria-label="Download your file"><?= icon('download', 17) ?></a></div>
                    <?php endif; ?>
                    <?php if (!$open): ?>
                        <div class="panel cluster muted"><?= icon('padlock', 18) ?>The deadline has passed, so uploads are closed.</div>
                    <?php elseif (!$sub || $sub['score'] === null): ?>
                        <?= form_start('submit_assignment', ['files' => true]) ?>
                            <input type="hidden" name="assignment_id" value="<?= (int) $a['id'] ?>">
                            <label class="dropzone" data-dropzone>
                                <input type="file" name="file" required accept="<?= e(implode(',', array_map(fn ($x) => ".$x", $extensions))) ?>">
                                <?= icon('file-up', 26) ?>
                                <span class="strong" data-file-name><?= $sub ? 'Drop a file here to replace your submission' : 'Drop your file here, or click to choose' ?></span>
                                <span class="faint small"><?= e(implode('  ', array_map(fn ($x) => ".$x", array_slice($extensions, 0, 8)))) ?><?= count($extensions) > 8 ? ' ...' : '' ?> · up to 10 MB</span>
                            </label>
                            <button class="btn btn-primary" type="submit"><?= $sub ? 'Replace submission' : 'Hand in' ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
            <?php if ($sub && $sub['score'] !== null): ?>
                <section>
                    <?= section_head('Grade and feedback') ?>
                    <div class="card card-pad">
                        <p class="big-score tnum"><?= (int) $sub['score'] ?><span> / 100</span></p>
                        <?php if ($parts): ?><ul class="stack-sm mt-4 small tnum">
                            <?php foreach ($rubric as $i => $r): ?>
                                <li><span class="split"><span><?= e($r['criterion']) ?></span><span class="strong"><?= (int) $parts[$i] ?> / <?= $r['max'] ?></span></span>
                                    <span class="bar"><span style="width:<?= round(100 * $parts[$i] / $r['max']) ?>%"></span></span></li>
                            <?php endforeach; ?></ul><?php endif; ?>
                        <?php if ($sub['feedback']): ?><p class="panel mt-4" style="white-space:pre-wrap"><?= e($sub['feedback']) ?></p><?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </div>

<?php else:
    $students = all('SELECT u.id, u.name, u.avatar, s.id AS submission_id, s.submitted_at, s.score, s.rubric_scores, s.feedback, s.file_id, f.original_name
        FROM enrollments en JOIN users u ON u.id = en.user_id LEFT JOIN submissions s ON s.assignment_id = ? AND s.student_id = u.id
        LEFT JOIN files f ON f.id = s.file_id WHERE en.course_id = ? ORDER BY u.name', [$a['id'], $a['course_id']]);
    $handedIn = count(array_filter($students, fn ($s) => $s['submission_id']));
    $graded = count(array_filter($students, fn ($s) => $s['score'] !== null));
?>
    <div class="stats" style="max-width:560px;margin-bottom:32px"><?= stat_card('file-check', 'Handed in', $handedIn) . stat_card('warn', 'Missing', count($students) - $handedIn) . stat_card('done', 'Graded', $graded) ?></div>
    <?= section_head('Submissions') ?>
    <div class="stack-sm">
        <?php foreach ($students as $s): ?>
            <div class="card card-pad">
                <div class="row" style="padding:0;min-height:0">
                    <?= avatar($s['name'], $s['avatar']) ?>
                    <span class="row-main"><span class="strong"><?= e($s['name']) ?></span>
                        <span class="faint small"><?= $s['submission_id'] ? 'Handed in <span class="mono">' . e(fmt_datetime($s['submitted_at'])) . '</span>' : ($open ? 'Not handed in yet' : 'Nothing handed in') ?></span></span>
                    <?= $s['submission_id'] ? ($s['score'] !== null ? badge($s['score'] . ' / 100', 'success') : badge('To grade', 'warn')) : badge('Missing', 'danger') ?>
                    <?php if ($s['submission_id']): ?><a class="btn btn-secondary btn-sm" href="index.php?action=download&amp;id=<?= (int) $s['file_id'] ?>"><?= icon('download', 15) ?>Download</a><?php endif; ?>
                </div>
                <?php if ($s['submission_id']): $parts = $s['rubric_scores'] ? json_decode($s['rubric_scores'], true) : []; ?>
                    <details class="mt-3" <?= $s['score'] === null ? 'open' : '' ?>>
                        <summary class="small strong" style="cursor:pointer;color:var(--accent-text);min-height:36px"><?= $s['score'] === null ? 'Grade this submission' : 'Change the grade' ?></summary>
                        <?= form_start('grade_submission', ['class' => 'stack mt-3']) ?>
                            <input type="hidden" name="submission_id" value="<?= (int) $s['submission_id'] ?>">
                            <?php if ($rubric): ?>
                                <div class="form-row" data-rubric>
                                    <?php foreach ($rubric as $i => $r): ?>
                                        <label class="panel field"><span class="label truncate"><?= e($r['criterion']) ?></span>
                                            <span class="cluster"><input class="input tnum" style="width:84px" type="number" name="rubric_scores[]" min="0" max="<?= $r['max'] ?>" required placeholder="0" value="<?= e($parts[$i] ?? '') ?>"><span class="faint small">/ <?= $r['max'] ?></span></span></label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="small muted">Total <span class="strong tnum" data-rubric-total><?= $s['score'] ?? 0 ?></span> / 100</p>
                            <?php else: ?>
                                <label class="field" style="max-width:160px"><span class="label">Score (0-100)</span><input class="input tnum" type="number" name="score" min="0" max="100" required value="<?= e($s['score'] ?? '') ?>"></label>
                            <?php endif; ?>
                            <label class="field"><span class="label">Feedback</span><textarea class="input" name="feedback" style="min-height:88px" placeholder="What went well, and what to work on"><?= e($s['feedback'] ?? '') ?></textarea></label>
                            <button class="btn btn-primary btn-sm" type="submit">Save grade</button>
                        </form>
                    </details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <section class="mt-10"><?= section_head('Instructions') ?><?= $brief ?></section>
<?php endif;
page_end();
