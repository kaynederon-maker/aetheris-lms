<?php
// page: read one learning material. PDFs are shown in the page; everything can be downloaded.

$user = require_login();
$m = one('SELECT m.*, c.code, c.title AS course_title, f.original_name, f.size, f.mime FROM materials m JOIN courses c ON c.id = m.course_id
          LEFT JOIN files f ON f.id = m.file_id WHERE m.id = ?', [(int) ($_GET['id'] ?? 0)]) ?? fail('Not found', 404);
require_course($user, (int) $m['course_id']);

page_start($m['title'], $user, 'courses');
?>
<a class="cluster small strong" style="color:var(--accent-text);min-height:44px" href="<?= url('course', ['id' => $m['course_id']]) ?>"><?= icon('back', 16) ?><?= e($m['code'] . ' · ' . $m['course_title']) ?></a>
<h1 class="mt-1" style="margin-bottom:24px"><?= e($m['title']) ?></h1>
<?php if ($m['body']): ?><div class="card card-pad"><div class="prose"><?= markdown($m['body']) ?></div></div><?php endif; ?>
<?php if ($m['file_id']): ?>
    <div class="card mt-6" style="overflow:hidden">
        <div class="split card-pad"><span><span class="strong"><?= e($m['original_name']) ?></span><br><span class="faint small"><?= e(bytes_label((int) $m['size'])) ?></span></span>
            <a class="btn btn-primary btn-sm" href="index.php?action=download&amp;id=<?= (int) $m['file_id'] ?>"><?= icon('download', 15) ?>Download</a></div>
        <?php if ($m['mime'] === 'application/pdf'): ?>
            <iframe src="index.php?action=download&amp;id=<?= (int) $m['file_id'] ?>&amp;inline=1" title="<?= e($m['original_name']) ?>" style="width:100%;height:75vh;border:0;border-top:1px solid var(--line);background:var(--surface-2)"></iframe>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php page_end(); ?>
