<?php

declare(strict_types=1);

/*
 * The selected candidates for one school: index number, name, aggregate and the
 * choice number the candidate gave this school.
 * A school administrator sees only their own school, whatever the URL says.
 */

require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/layout.php';
require_once __DIR__ . '/../php/applications.php';
require_once __DIR__ . '/../php/iosss_app_process.php';

$me = require_staff();

if ($me['role'] === 'school_admin') {
    $schoolId = (int)$me['school_id'];
} else {
    $schoolId = filter_input(INPUT_GET, 'school_id', FILTER_VALIDATE_INT);
    if (!$schoolId) {
        redirect('admin/dashboard.php');
    }
}

$school = null;
foreach (schoolOverview($conn) as $s) {
    if ((int)$s['school_id'] === $schoolId) {
        $school = $s;
    }
}
if ($school === null) {
    http_response_code(404);
    exit('School not found.');
}

$cycle    = cycle_load($conn);
$ran      = $cycle['run_at'] !== null;
$page     = current_page();
$perPage  = 50;
$total    = $ran ? countSelected($conn, $schoolId) : 0;
$rows     = $ran ? getSelectedStudents($conn, $schoolId, $perPage, ($page - 1) * $perPage) : [];
$capacity = (int)$school['capacity'];
$stale    = $ran && selectionIsStale($conn, $cycle);

render_header($school['school_name'], $me['role'] === 'school_admin' ? 'school' : 'dashboard');
$actions = $ran
    ? '<a class="btn btn-ghost" href="' . e(url('php/export_csv.php')) . '?school_id=' . $schoolId . '">' . icon('download') . 'Download CSV</a>'
    . '<button type="button" class="btn btn-ghost" data-print>' . icon('printer') . 'Print</button>'
    : '';
page_head($school['school_name'], 'Selected candidates, best aggregate first.', $actions);
?>

<?php if (!$ran): ?>
    <div class="alert alert-info" role="status">The selection has not been run yet. This list fills in as soon as the system administrator runs it.</div>
<?php else: ?>
    <?php if (!$cycle['published']): ?>
        <div class="alert alert-warning" role="status"><strong>Provisional list.</strong> Candidates cannot see these results until the system administrator publishes them.</div>
    <?php endif; ?>
    <?php if ($stale): ?>
        <div class="alert alert-warning" role="status">Marks or applications changed after the selection ran, so this list may change when it is run again.</div>
    <?php endif; ?>
<?php endif; ?>

<dl class="stats stats-3">
    <div class="stat">
        <dt>Seats</dt>
        <dd><?= $capacity ?></dd>
    </div>
    <div class="stat stat-good">
        <dt>Selected</dt>
        <dd><?= $ran ? $total : '&ndash;' ?></dd>
    </div>
    <div class="stat">
        <dt>Vacant</dt>
        <dd><?= $ran ? max(0, $capacity - $total) : '&ndash;' ?></dd>
    </div>
</dl>

<?php if ($ran): ?>
    <section class="section-gap">
        <?php displaySelectedStudents($rows, ($page - 1) * $perPage + 1); ?>
        <?php render_pagination($total, $page, $perPage, $me['role'] === 'super_admin' ? ['school_id' => $schoolId] : []); ?>
    </section>
<?php endif; ?>
<?php render_footer();
