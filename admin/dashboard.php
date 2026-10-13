<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/layout.php';
require_once __DIR__ . '/../php/iosss_app_process.php';

require_staff(['super_admin']);

$cycle    = cycle_load($conn);
$n        = pipelineCounts($conn);
$schools  = schoolOverview($conn);
$stale    = selectionIsStale($conn, $cycle);
$ran      = $cycle['run_at'] !== null;
$closed   = $cycle['status'] === 'closed';

/* The cycle as a checklist. The first unfinished step is highlighted as "current". */
$pipeline = [
    [
        $cycle['status'] !== 'unset',
        'Set the application dates',
        $cycle['status'] === 'unset' ? 'No dates are set. Visitors see "dates will be announced".' : cycle_headline($cycle) . '.',
        '<a class="btn btn-sm btn-ghost" href="cycle.php">Edit dates</a>'
    ],
    [
        $closed,
        'Applications close',
        $n['applications'] . ' application' . ($n['applications'] === 1 ? '' : 's') . ' so far from ' . $n['accounts'] . ' account' . ($n['accounts'] === 1 ? '' : 's') . '.',
        ''
    ],
    [
        $n['applications'] > 0 && $n['awaiting'] === 0,
        'Upload marks',
        $n['awaiting'] > 0 ? $n['awaiting'] . ' applicant' . ($n['awaiting'] === 1 ? ' has' : 's have') . ' no marks yet.' : ($n['applications'] > 0 ? 'Every applicant has marks.' : 'No applications yet.'),
        '<a class="btn btn-sm btn-ghost" href="marks.php">Upload marks</a>'
    ],
    [
        $ran && !$stale,
        'Run the selection',
        !$ran ? 'Not run yet.' : ($stale ? 'Out of date: marks or applications changed after the last run (' . fmt_dt($cycle['run_at']) . ').' : 'Last run ' . fmt_dt($cycle['run_at']) . '.'),
        '<a class="btn btn-sm btn-ghost" href="selection.php">Open selection</a>'
    ],
    [
        $cycle['published'],
        'Publish the results',
        $cycle['published'] ? 'Published ' . fmt_dt($cycle['published_at']) . '. Candidates can see their placement.' : 'Candidates cannot see results yet.',
        '<a class="btn btn-sm btn-ghost" href="selection.php">Open selection</a>'
    ],
];
$currentSet = false;

render_header('Dashboard', 'dashboard');
page_head('Dashboard', $cycle['name']);
?>

<?php if ($stale): ?>
    <div class="alert alert-warning" role="status"><strong>The selection is out of date.</strong> Marks or applications changed after it was run. <a href="selection.php">Run it again</a> before publishing.</div>
<?php endif; ?>

<dl class="stats">
    <div class="stat">
        <dt>Accounts</dt>
        <dd data-count="<?= $n['accounts'] ?>"><?= $n['accounts'] ?></dd>
    </div>
    <div class="stat">
        <dt>Applications</dt>
        <dd data-count="<?= $n['applications'] ?>"><?= $n['applications'] ?></dd>
    </div>
    <div class="stat">
        <dt>Marks uploaded</dt>
        <dd data-count="<?= $n['marks'] ?>"><?= $n['marks'] ?></dd>
    </div>
    <div class="stat stat-warn">
        <dt>Awaiting marks</dt>
        <dd data-count="<?= $n['awaiting'] ?>"><?= $n['awaiting'] ?></dd>
    </div>
    <div class="stat stat-good">
        <dt>Placed</dt>
        <dd><?= $ran ? $n['placed'] : '&ndash;' ?></dd>
    </div>
    <div class="stat">
        <dt>Seats</dt>
        <dd data-count="<?= $n['seats'] ?>"><?= $n['seats'] ?></dd>
    </div>
</dl>

<div class="grid grid-2 section-gap">
    <section class="card">
        <div class="card-head">
            <h2>Selection cycle</h2>
        </div>
        <ol class="timeline">
            <?php foreach ($pipeline as [$done, $title, $detail, $button]):
                $state = $done ? 'done' : (!$currentSet ? 'current' : 'todo');
                if (!$done) {
                    $currentSet = true;
                } ?>
                <li class="is-<?= $state ?>">
                    <span class="timeline-dot"><?= $done ? icon('check') : '' ?></span>
                    <div class="timeline-body">
                        <strong><?= e($title) ?></strong>
                        <p><?= e($detail) ?></p>
                        <?= $button ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>Schools</h2><a href="schools.php" class="link-small">Manage</a>
        </div>
        <div class="table-wrap">
            <table class="table table-compact">
                <thead>
                    <tr>
                        <th>School</th>
                        <th class="num">Seats</th>
                        <th class="num">1st choice</th>
                        <th class="num">Placed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schools as $s): ?>
                        <tr>
                            <td><a href="school.php?school_id=<?= (int)$s['school_id'] ?>"><?= e($s['school_name']) ?></a></td>
                            <td class="num mono"><?= (int)$s['capacity'] ?></td>
                            <td class="num mono"><?= (int)$s['first_choice'] ?></td>
                            <td class="num mono"><?= $ran ? (int)$s['selected'] : '&ndash;' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php render_footer();
