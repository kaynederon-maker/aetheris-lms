<?php
/** PAGE (admin only): the latest 200 security events. */

$admin = require_role('ADMIN');
$logs = all('SELECT l.*, u.name AS actor FROM audit_log l LEFT JOIN users u ON u.id = l.actor_id ORDER BY l.id DESC LIMIT 200');

page_start('Audit log', $admin, 'admin-log');
echo page_header('Audit log', 'Sign-ins, failed attempts, refused downloads, uploads and admin changes, newest first.');
?>
<div class="card table-wrap"><table class="table">
    <thead><tr><th>Time</th><th>Event</th><th>Who</th><th>About</th><th>IP address</th></tr></thead>
    <tbody><?php foreach ($logs as $l): ?>
        <tr><td class="mono small faint nowrap"><?= e(fmt_datetime($l['created_at'])) ?></td>
            <td><?= badge($l['action'], preg_match('/fail|denied|rejected|limited|blocked|deactivated/', $l['action']) ? 'danger' : '') ?></td>
            <td><?= e($l['actor'] ?? '-') ?></td><td class="muted"><?= e($l['target'] ?? '-') ?></td><td class="faint"><?= e($l['ip'] ?? '-') ?></td></tr>
    <?php endforeach; ?></tbody>
</table></div>
<?php page_end(); ?>
