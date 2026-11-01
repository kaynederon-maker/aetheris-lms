<?php
/**
 * PAGE: one consultation request, with its progress chart and the next steps.
 * When the lecturer or support staff opens a new request, it is marked as "Read" automatically.
 */

$user = require_login();
[$c, $side] = consultation_access((int) ($_GET['id'] ?? 0), $user);
if ($side === 'recipient' && $c['status'] === 'DELIVERED') {
    update('consultations', (int) $c['id'], ['status' => 'READ', 'updated_at' => now()]);
    insert('consultation_events', ['consultation_id' => $c['id'], 'status' => 'READ', 'actor_id' => $user['id'], 'created_at' => now()]);
    $c['status'] = 'READ';
}
$student = one('SELECT name FROM users WHERE id = ?', [$c['student_id']]);
$recipient = $c['recipient_type'] === 'SUPPORT' ? 'Academic and technical support' : (value('SELECT name FROM users WHERE id = ?', [$c['recipient_id']]) ?: 'Course lecturer');
$events = all('SELECT status, note, created_at FROM consultation_events WHERE consultation_id = ? ORDER BY id', [$c['id']]);
$steps = sop_actions($c['status'], $side);
$decisions = array_values(array_intersect(['approve', 'request_revision', 'decline'], $steps));

/** The small form for one next step. */
function step_form(int $id, string $step, string $fields, string $button, string $class = 'btn btn-primary'): string
{
    return form_start('update_consultation') . '<input type="hidden" name="consultation_id" value="' . $id . '"><input type="hidden" name="step" value="' . $step . '">'
        . $fields . '<button class="' . $class . '" type="submit">' . e($button) . '</button></form>';
}

page_start($c['subject'], $user, 'consultations');
?>
<a class="cluster small strong" style="color:var(--accent-text);min-height:44px" href="<?= url('consultations') ?>"><?= icon('back', 16) ?>All requests</a>
<p class="mono small faint mt-2">SOP-<?= (int) $c['id'] ?></p>
<div class="page-header" style="margin-top:4px"><div><h1><?= e($c['subject']) ?></h1></div><?= status_badge($c['status']) ?></div>

<div class="layout-main-side">
    <div class="stack-lg">
        <section class="card card-pad stack">
            <div class="form-row small">
                <div><p class="faint">From</p><p class="strong"><?= e($student['name']) ?></p></div>
                <div><p class="faint">To</p><p class="strong"><?= e($recipient) ?></p></div>
                <div><p class="faint">Type</p><p class="strong"><?= e(CATEGORY_LABELS[$c['category']] ?? $c['category']) ?></p></div>
                <div><p class="faint">How</p><p class="strong"><?= e(MEDIUM_LABELS[$c['medium']]) ?></p></div>
            </div>
            <?php if ($c['preferred_at']): ?><p class="small"><span class="faint">Preferred time</span> <span class="mono"><?= e(fmt_datetime($c['preferred_at'])) ?></span></p><?php endif; ?>
            <p class="panel" style="white-space:pre-wrap"><?= e($c['details']) ?></p>
            <?php if ($c['status'] === 'APPROVED_SCHEDULED' && $c['scheduled_at']): ?>
                <div class="notice notice-ok" style="margin:0;display:block"><p class="strong">Session confirmed</p><p class="mono small mt-1"><?= e(fmt_datetime($c['scheduled_at'])) ?></p>
                    <p class="small mt-1" style="color:var(--ink)"><?= e(MEDIUM_LABELS[$c['medium']]) ?> · <?= e($c['session_link']) ?></p></div>
            <?php endif; ?>
            <?php if ($c['summary']): ?><p class="panel small"><span class="strong">Summary:</span> <?= e($c['summary']) ?></p><?php endif; ?>
        </section>

        <?php if ($steps): ?>
            <section class="card card-pad stack" aria-label="Next step">
                <h2>Next step</h2>
                <?php if (in_array('review', $steps, true)): ?>
                    <?= step_form((int) $c['id'], 'review', '', 'Mark as under review', 'btn btn-secondary btn-block') ?>
                <?php endif; ?>
                <?php if ($decisions): ?>
                    <div class="segmented full" role="tablist" data-step-tabs>
                        <?php foreach ($decisions as $i => $d): ?><button type="button" role="tab" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" data-step-tab="<?= $d ?>" <?= $i === 0 ? 'aria-current="page"' : '' ?>><?= ['approve' => 'Approve', 'request_revision' => 'Ask for more detail', 'decline' => 'Decline'][$d] ?></button><?php endforeach; ?>
                    </div>
                    <?php foreach ($decisions as $i => $d): ?>
                        <div data-step-panel="<?= $d ?>" <?= $i === 0 ? '' : 'hidden' ?>>
                            <?php if ($d === 'approve'): ?>
                                <?= step_form((int) $c['id'], 'approve', '<label class="field"><span class="label">Date and time</span><input class="input" type="datetime-local" name="scheduled_at" required></label>'
                                    . '<label class="field"><span class="label">' . ($c['medium'] === 'CHAT' ? 'Chat room' : 'Meeting link or room') . '</span><input class="input" name="session_link" required minlength="3" placeholder="https://meet.example.edu/abc-defg"></label>', 'Approve and schedule') ?>
                            <?php elseif ($d === 'request_revision'): ?>
                                <?= step_form((int) $c['id'], 'request_revision', '<label class="field"><span class="label">What do you need from the student?</span><textarea class="input" name="note" required minlength="10" style="min-height:96px"></textarea></label>', 'Send back to the student', 'btn btn-secondary') ?>
                            <?php else: ?>
                                <?= step_form((int) $c['id'], 'decline', '<label class="field"><span class="label">Reason (the student will see this)</span><textarea class="input" name="reason" required minlength="10" style="min-height:96px" placeholder="Why, and what the student can do instead."></textarea><span class="hint">Required, at least 10 characters.</span></label>', 'Decline request', 'btn btn-danger') ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if (in_array('complete', $steps, true)): ?>
                    <?= step_form((int) $c['id'], 'complete', '<label class="field"><span class="label">Session summary (optional)</span><textarea class="input" name="summary" style="min-height:96px" placeholder="What you agreed on"></textarea></label>', 'Mark as completed') ?>
                <?php endif; ?>
                <?php if (in_array('respond', $steps, true)): ?>
                    <?= step_form((int) $c['id'], 'respond', '<label class="field"><span class="label">Your reply</span><textarea class="input" name="note" required minlength="5" style="min-height:110px" placeholder="Add the details that were asked for"></textarea></label>', 'Send reply') ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
    <section class="card card-pad"><h2 style="margin-bottom:16px">Progress</h2><?= sop_flow_html(sop_flow($c['status'], $events)) ?></section>
</div>
<?php page_end(); ?>
