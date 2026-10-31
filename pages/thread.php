<?php
/** PAGE: one forum question with its answers. The verified answer comes first, then the most upvoted. */

$user = require_login();
$thread = one('SELECT t.*, u.name, u.avatar FROM forum_threads t JOIN users u ON u.id = t.author_id WHERE t.id = ?', [(int) ($_GET['id'] ?? 0)]) ?? fail('Not found', 404);
$role = require_course($user, (int) $thread['course_id']);
$posts = all('SELECT p.*, u.name, u.avatar, u.role, (SELECT COUNT(*) FROM forum_votes v WHERE v.post_id = p.id) AS votes,
    (SELECT COUNT(*) FROM forum_votes v WHERE v.post_id = p.id AND v.user_id = ?) AS voted
    FROM forum_posts p JOIN users u ON u.id = p.author_id WHERE p.thread_id = ? ORDER BY p.verified DESC, votes DESC, p.created_at', [$user['id'], $thread['id']]);

page_start($thread['title'], $user, 'courses');
?>
<div class="narrow" style="max-width:760px">
    <a class="cluster small strong" style="color:var(--accent-text);min-height:44px" href="<?= url('course', ['id' => $thread['course_id'], 'tab' => 'forum']) ?>"><?= icon('back', 16) ?>All questions</a>
    <div class="card card-pad mt-2">
        <h1 style="font-size:1.375rem"><?= e($thread['title']) ?></h1>
        <p class="who small faint mt-2"><?= avatar($thread['name'], $thread['avatar'], 24) ?><?= e($thread['name']) ?> · <?= e(relative_time($thread['created_at'])) ?></p>
        <div class="prose mt-4"><?= markdown($thread['body']) ?></div>
    </div>

    <h2 class="mt-10 tnum"><?= count($posts) ?> <?= count($posts) === 1 ? 'answer' : 'answers' ?></h2>
    <?php foreach ($posts as $p): ?>
        <div class="card card-pad mt-3" <?= $p['verified'] ? 'style="border-color:color-mix(in srgb,var(--highlight) 45%,transparent)"' : '' ?>>
            <div class="cluster small">
                <?= avatar($p['name'], $p['avatar'], 26) ?><span class="strong"><?= e($p['name']) ?></span>
                <?= $p['role'] === 'LECTURER' ? badge('Lecturer', 'accent') : '' ?><span class="faint">· <?= e(relative_time($p['created_at'])) ?></span>
                <?= $p['verified'] ? '<span class="grow"></span>' . badge('Verified by the lecturer', 'highlight') : '' ?>
            </div>
            <div class="prose mt-3"><?= markdown($p['body']) ?></div>
            <div class="cluster mt-4">
                <?= button_form('vote', ['post_id' => $p['id']], icon('thumb', 15) . '<span class="tnum">' . $p['votes'] . '</span>', 'btn btn-sm ' . ($p['voted'] ? 'btn-secondary' : 'btn-ghost'), '', $p['voted'] ? 'Remove your upvote' : 'Upvote') ?>
                <?php if (can_manage($role)): ?><?= button_form('verify_answer', ['post_id' => $p['id']], icon('verified', 15) . ($p['verified'] ? 'Remove verification' : 'Mark as verified'), 'btn btn-ghost btn-sm') ?><?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="card card-pad mt-6">
        <?= form_start('reply') ?>
            <input type="hidden" name="thread_id" value="<?= (int) $thread['id'] ?>">
            <label class="field"><span class="label">Your answer (Markdown)</span><textarea class="input" name="body" required></textarea></label>
            <button class="btn btn-primary" type="submit">Post answer</button>
        </form>
    </div>
</div>
<?php page_end(); ?>
