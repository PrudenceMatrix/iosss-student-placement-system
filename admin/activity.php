<?php

declare(strict_types=1);

/* A read-only record of what staff did: uploads, selection runs, publishing, password resets. */

require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/layout.php';

require_staff(['super_admin']);

$page   = current_page();
$limit  = 50;
$total  = (int)$conn->query('SELECT COUNT(*) FROM audit_log')->fetch_row()[0];
$st = $conn->prepare('SELECT actor, action, subject, detail, created_at FROM audit_log ORDER BY log_id DESC LIMIT ? OFFSET ?');
$offset = ($page - 1) * $limit;
$st->bind_param('ii', $limit, $offset);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$labels = [
    'cycle_update' => ['Dates changed', 'info'],
    'marks_single' => ['Marks entered', 'primary'],
    'marks_csv' => ['Marks uploaded', 'primary'],
    'selection_run' => ['Selection run', 'warning'],
    'results_publish' => ['Results published', 'success'],
    'results_unpublish' => ['Results hidden', 'neutral'],
    'reset_password' => ['Password reset', 'danger'],
    'school_create' => ['School added', 'info'],
    'school_update' => ['School changed', 'info'],
    'password_change' => ['Own password changed', 'neutral'],
];

render_header('Activity log', 'activity');
page_head('Activity log', 'Every action staff take that changes data is recorded here.');
?>
<?php if (!$rows): ?>
    <div class="empty-state"><strong>Nothing has been recorded yet.</strong></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table table-wide">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Who</th>
                    <th>Action</th>
                    <th>About</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): [$text, $type] = $labels[$r['action']] ?? [$r['action'], 'neutral']; ?>
                    <tr>
                        <td class="muted nowrap"><?= e(fmt_dt(parse_stamp((string)$r['created_at']))) ?></td>
                        <td class="mono"><?= e($r['actor']) ?></td>
                        <td><?= badge($text, $type) ?></td>
                        <td><?= e($r['subject']) ?></td>
                        <td class="muted"><?= e($r['detail']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php render_pagination($total, $page, $limit); ?>
<?php render_footer();
