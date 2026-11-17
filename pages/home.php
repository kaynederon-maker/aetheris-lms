<?php
// page: the public landing page, with a small working preview of the student dashboard.

if (current_user()) {
    redirect(url('dashboard'));
}

// Preview data: three deadlines and one consultation request at different steps.
$deadlines = [
    ['Proof set 3: induction', 'MATH6025', now(5 * 3600 + 720)],
    ['Linked list implementation', 'COMP6100', now(2 * 86400 + 3 * 3600)],
    ['Threat model report', 'ISYS6197', now(6 * 86400)],
];
$flowStates = ['DELIVERED', 'READ', 'UNDER_REVIEW', 'APPROVED_SCHEDULED', 'COMPLETED'];

page_start('Welcome');
?>
<section class="hero">
    <h1 class="rise">Classes, deadlines and exams, all in one place.</h1>
    <p class="rise" style="--i:1">Aetheris is the portal for your courses. Read the materials, hand in assignments, sit timed exams and book time with your lecturer.</p>
    <div class="cluster rise" style="--i:2">
        <a class="btn btn-primary" href="<?= url('login') ?>">Sign in</a>
        <a class="btn btn-secondary" href="<?= url('register') ?>">Create a student account</a>
    </div>
</section>

<section class="card preview rise" style="--i:3" aria-label="Preview of the student dashboard">
    <div class="preview-bar" aria-hidden="true"><i></i><i></i><i></i></div>
    <div class="preview-body">
        <nav class="preview-nav" aria-label="Preview menu">
            <?php foreach ([['dashboard', 'Today'], ['book', 'Courses'], ['clipboard', 'Assignments'], ['cap', 'Grades'], ['messages', 'Consultations'], ['calendar', 'Calendar']] as $i => [$ic, $label]): ?>
                <span class="nav-item" <?= $i === 0 ? 'aria-current="page"' : '' ?>><?= icon($ic, 16) ?><?= e($label) ?></span>
            <?php endforeach; ?>
        </nav>
        <div class="preview-col">
            <p class="strong">Active coursework &amp; deadlines</p>
            <ul class="stack-sm mt-3">
                <?php foreach ($deadlines as [$title, $code, $due]): ?>
                    <li class="panel split"><span><span class="strong small"><?= e($title) ?></span><br><span class="faint small"><?= e($code) ?></span></span><?= countdown($due) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="preview-col" data-preview>
            <div class="split"><p class="strong">Consultation request</p><button type="button" class="btn btn-secondary btn-sm" data-preview-next>Next step</button></div>
            <?php foreach ($flowStates as $i => $state):
                $events = [];
                foreach (array_slice($flowStates, 0, $i + 1) as $j => $s) {
                    $events[] = ['status' => $s, 'created_at' => now(-($i - $j) * 2400), 'note' => $s === 'APPROVED_SCHEDULED' ? 'Thursday 10:00, video call' : null];
                } ?>
                <div class="mt-4 small" data-step="<?= $i ?>" <?= $i === 2 ? '' : 'hidden' ?>><?= sop_flow_html(sop_flow($state, $events)) ?></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<p class="center faint small mt-3">Try it: the timers are running, and Next step moves the request along.</p>

<section class="pillars">
    <div><?= icon('clock', 22) ?><h2>Deadlines</h2><p>Every assignment and exam shows a countdown. When it hits zero the server stops taking uploads, so there's no arguing later about what made it in.</p></div>
    <div><?= icon('route', 22) ?><h2>Consultation requests</h2><p>Ask your lecturer or the support desk for a video call, voice call or chat. You can see when the request was delivered, read and reviewed, and if it gets declined, the reason is right there.</p></div>
    <div><?= icon('lock', 22) ?><h2>Your grades stay yours</h2><p>Only you and your lecturer can see your submissions and scores. The server checks who owns each record every time something is requested.</p></div>
</section>

<section class="roles">
    <h2 style="font-size:1.5rem">What each role can do</h2>
    <div class="grid-3 mt-6">
        <?php foreach ([
            'Students' => ['Join classes with a code', 'Hand in work before the deadline', 'Take timed exams that save as you go', 'Book time with a lecturer'],
            'Lecturers' => ['Publish and reorder materials', 'Write multiple-choice and essay exams', 'Grade with rubrics and feedback', 'Answer consultation requests'],
            'Administrators' => ['Create lecturer accounts', 'Create classes and join codes', 'Switch accounts off instantly', 'Read the security log'],
        ] as $role => $items): ?>
            <div class="card card-pad"><h3><?= e($role) ?></h3><ul><?php foreach ($items as $item): ?><li><?= e($item) ?></li><?php endforeach; ?></ul></div>
        <?php endforeach; ?>
    </div>
</section>
<?php page_end(); ?>
