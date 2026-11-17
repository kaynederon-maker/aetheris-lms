<?php
// page: a lecturer writes a new exam. "Add question" copies the <template> below (see app.js).

$user = require_role('LECTURER', 'ADMIN');
$courseId = (int) ($_GET['course'] ?? 0);
require_course($user, $courseId, true);
$course = one('SELECT code FROM courses WHERE id = ?', [$courseId]);

page_start('New exam', $user, 'courses');
echo page_header('New exam for ' . $course['code'], 'Students only see the questions. The correct answers stay on the server.');
?>
<?= form_start('create_exam', ['class' => 'stack-lg narrow']) ?>
    <input type="hidden" name="course_id" value="<?= $courseId ?>">
    <section class="card card-pad stack">
        <h2>Details</h2>
        <label class="field"><span class="label">Title</span><input class="input" name="title" required minlength="3" placeholder="Midterm quiz: Sorting"></label>
        <label class="field"><span class="label">Instructions</span><textarea class="input" name="description" style="min-height:80px" placeholder="Anything students should know before starting"></textarea></label>
        <div class="form-row">
            <label class="field"><span class="label">Opens</span><input class="input" type="datetime-local" name="opens_at" required value="<?= e(local_input_value(now())) ?>"></label>
            <label class="field"><span class="label">Closes</span><input class="input" type="datetime-local" name="closes_at" required value="<?= e(local_input_value(now(7 * 86400))) ?>"></label>
            <label class="field"><span class="label">Minutes</span><input class="input tnum" type="number" name="duration_min" min="1" max="300" value="30" required></label>
        </div>
    </section>
    <section>
        <div class="split"><h2>Questions</h2><div class="cluster">
            <button type="button" class="btn btn-secondary btn-sm" data-add-question="MCQ"><?= icon('list', 15) ?>Multiple choice</button>
            <button type="button" class="btn btn-secondary btn-sm" data-add-question="ESSAY"><?= icon('pen', 15) ?>Essay</button>
        </div></div>
        <div class="stack mt-4" data-questions></div>
    </section>
    <div class="cluster" style="justify-content:flex-end"><button class="btn btn-primary" type="submit">Publish exam</button></div>
</form>

<template id="question-template">
    <div class="card card-pad stack rise" data-question-block>
        <div class="split"><span class="badge" data-question-label></span>
            <span class="cluster"><label class="cluster small muted">Points <input class="input tnum" style="width:80px;min-height:36px;padding:4px 8px" type="number" min="1" max="100" data-field="points" required></label>
            <button type="button" class="btn btn-ghost icon-btn" data-remove-question aria-label="Remove question"><?= icon('trash', 16) ?></button></span></div>
        <input type="hidden" data-field="type">
        <textarea class="input" style="min-height:72px" required minlength="3" placeholder="Question" data-field="prompt"></textarea>
        <fieldset class="stack-sm" style="border:0;padding:0;margin:0" data-options>
            <legend class="small muted">Options. Tick the correct one.</legend>
            <?php for ($o = 0; $o < 4; $o++): ?>
                <div class="cluster" style="flex-wrap:nowrap"><input type="radio" data-correct value="<?= $o ?>" <?= $o === 0 ? 'checked' : '' ?> aria-label="Option <?= $o + 1 ?> is correct" style="accent-color:var(--accent);width:16px;height:16px">
                    <input class="input" data-option placeholder="Option <?= $o + 1 ?><?= $o >= 2 ? ' (optional)' : '' ?>" <?= $o < 2 ? 'required' : '' ?>></div>
            <?php endfor; ?>
        </fieldset>
    </div>
</template>
<?php page_end(); ?>
