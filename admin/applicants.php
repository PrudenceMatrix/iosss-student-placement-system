<?php

declare(strict_types=1);

/* Candidates: everyone who signed up, where they are in the process, and password resets. */

require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/layout.php';
require_once __DIR__ . '/../php/applications.php';

$me     = require_staff(['super_admin']);
$cycle  = cycle_load($conn);
$ran    = $cycle['run_at'] !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (field($_POST, 'action') === 'reset') {
        $indexNo = trim(field($_POST, 'index_no'));
        $st = $conn->prepare('SELECT full_name FROM students WHERE index_no = ?');
        $st->bind_param('s', $indexNo);
        $st->execute();
        $who = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$who) {
            flash('error', 'That candidate was not found.');
        } else {
            $temp = generateTempPassword();
            $hash = password_hash($temp, PASSWORD_DEFAULT);
            $st = $conn->prepare('UPDATE students SET password_hash = ? WHERE index_no = ?');
            $st->bind_param('ss', $hash, $indexNo);
            $st->execute();
            $st->close();
            clear_attempts($conn, throttle_key('student', $indexNo));
            audit($conn, 'reset_password', $indexNo, $who['full_name']);
            flash('secret', 'Temporary password for ' . $who['full_name'] . ' (' . $indexNo . '): ' . $temp . '. Give it to the candidate privately.');
        }
    }
    redirect('admin/applicants.php' . (!empty($_POST['back']) && is_string($_POST['back']) && preg_match('/^\?[A-Za-z0-9_=&%.\-]*$/', $_POST['back']) ? $_POST['back'] : ''));
}

$filters = [
    'all'            => 'All candidates',
    'no_application' => 'No application yet',
    'awaiting_marks' => 'Awaiting marks',
    'ready'          => 'Ready for selection',
    'placed'         => 'Placed',
    'unplaced'       => 'Unplaced',
];
$filter = field($_GET, 'status');
if (!isset($filters[$filter])) {
    $filter = 'all';
}
$q    = trim(field($_GET, 'q'));
$page = current_page();
$like = addcslashes($q, '%_\\') . '%';
$likeName = '%' . addcslashes($q, '%_\\') . '%';

$cond = [
    'all'            => '1=1',
    'no_application' => 'a.index_no IS NULL',
    'awaiting_marks' => 'a.index_no IS NOT NULL AND m.index_no IS NULL',
    'ready'          => 'a.index_no IS NOT NULL AND m.index_no IS NOT NULL AND sel.index_no IS NULL' . ($ran ? ' AND 1=0' : ''),
    'placed'         => 'sel.index_no IS NOT NULL',
    'unplaced'       => 'a.index_no IS NOT NULL AND m.index_no IS NOT NULL AND sel.index_no IS NULL' . ($ran ? '' : ' AND 1=0'),
][$filter];

$from = 'FROM students s
    LEFT JOIN applications a ON a.index_no = s.index_no
    LEFT JOIN marks m ON m.index_no = s.index_no
    LEFT JOIN selection sel ON sel.index_no = s.index_no
    LEFT JOIN schools sch ON sch.school_id = sel.school_id
   WHERE (CHAR_LENGTH(?) = 0 OR s.index_no LIKE ? OR s.full_name LIKE ?) AND ' . $cond;

$st = $conn->prepare('SELECT COUNT(*) ' . $from);
$st->bind_param('sss', $q, $like, $likeName);
$st->execute();
$st->bind_result($total);
$st->fetch();
$st->close();

$limit  = PAGE_SIZE;
$offset = ($page - 1) * $limit;
$st = $conn->prepare('SELECT s.index_no, s.full_name, s.created_at, a.index_no AS has_application, m.aggregate, sch.school_name '
    . $from . ' ORDER BY s.created_at DESC, s.index_no LIMIT ? OFFSET ?');
$st->bind_param('sssii', $q, $like, $likeName, $limit, $offset);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$query = array_filter(['q' => $q, 'status' => $filter === 'all' ? '' : $filter], static fn($v) => $v !== '');
$backQuery = $query ? '?' . http_build_query($query) : '';

render_header('Candidates', 'applicants');
page_head('Candidates', 'Everyone who signed up, and where they are in the process.');
?>

<div class="toolbar">
    <h2><?= number_format((int)$total) ?> candidate<?= (int)$total === 1 ? '' : 's' ?></h2>
    <form method="get" class="searchbar" role="search">
        <label class="visually-hidden" for="status">Show</label>
        <select id="status" name="status" data-autosubmit>
            <?php foreach ($filters as $k => $label): ?>
                <option value="<?= $k ?>" <?= $k === $filter ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="visually-hidden" for="q">Search</label>
        <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Search index number or name">
        <button type="submit" class="btn btn-ghost">Search</button>
    </form>
</div>

<?php if (!$rows): ?>
    <div class="empty-state"><strong>No candidates match.</strong></div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table table-wide">
            <thead>
                <tr>
                    <th>Index number</th>
                    <th>Name</th>
                    <th>Signed up</th>
                    <th>Application</th>
                    <th class="num">Aggregate</th>
                    <th>Status</th>
                    <th><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r):
                    if ($r['school_name'] !== null) {
                        $status = badge('Placed', 'success') . ' <span class="muted">' . e($r['school_name']) . '</span>';
                    } elseif ($r['has_application'] === null) {
                        $status = badge('No application', 'neutral');
                    } elseif ($r['aggregate'] === null) {
                        $status = badge('Awaiting marks', 'warning');
                    } elseif ($ran) {
                        $status = badge('Unplaced', 'danger');
                    } else {
                        $status = badge('Ready', 'info');
                    } ?>
                    <tr>
                        <td class="mono"><?= e($r['index_no']) ?></td>
                        <td><?= e($r['full_name']) ?></td>
                        <td class="muted nowrap"><?= e(fmt_dt(parse_stamp((string)$r['created_at']), false)) ?></td>
                        <td><?= $r['has_application'] !== null ? badge('Submitted', 'success') : badge('Not yet', 'neutral') ?></td>
                        <td class="num mono"><?= $r['aggregate'] !== null ? (int)$r['aggregate'] : '&ndash;' ?></td>
                        <td><?= $status ?></td>
                        <td class="num">
                            <form method="post" class="inline" data-confirm="Reset the password for <?= e($r['full_name']) ?>? A temporary password will be shown once.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reset">
                                <input type="hidden" name="index_no" value="<?= e($r['index_no']) ?>">
                                <input type="hidden" name="back" value="<?= e($backQuery) ?>">
                                <button type="submit" class="btn btn-ghost btn-sm"><?= icon('key') ?>Reset password</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php render_pagination((int)$total, $page, $limit, $query); ?>
<?php render_footer();
