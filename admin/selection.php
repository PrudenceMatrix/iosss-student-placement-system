<?php

declare(strict_types=1);

/* Run the selection, review the outcome per school and round, and publish the results. */

require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/layout.php';
require_once __DIR__ . '/../php/applications.php';
require_once __DIR__ . '/../php/iosss_app_process.php';

$me = require_staff(['super_admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = field($_POST, 'action');
    $cycle  = cycle_load($conn);

    if ($action === 'run') {
        try {
            $r = runSelection($conn);
            audit($conn, 'selection_run', '', $r['placed'] . ' placed, ' . $r['unplaced'] . ' unplaced of ' . $r['eligible']);
            flash('success', sprintf(
                'Selection complete: %d of %d candidates placed (round 1: %d, round 2: %d, round 3: %d), %d unplaced.',
                $r['placed'],
                $r['eligible'],
                $r['per_round'][1],
                $r['per_round'][2],
                $r['per_round'][3],
                $r['unplaced']
            ));
        } catch (RuntimeException $ex) {
            flash('error', $ex->getMessage());
        } catch (Throwable $ex) {
            error_log('IOSSS selection failed: ' . $ex->getMessage());
            flash('error', 'The selection failed and nothing was changed. Try again.');
        }
    } elseif ($action === 'publish') {
        if ($cycle['run_at'] === null) {
            flash('error', 'Run the selection before publishing.');
        } elseif (selectionIsStale($conn, $cycle)) {
            flash('error', 'The selection is out of date. Run it again, then publish.');
        } else {
            publishResults($conn, true);
            audit($conn, 'results_publish');
            flash('success', 'Results are published. Candidates can now see their placement.');
        }
    } elseif ($action === 'unpublish') {
        publishResults($conn, false);
        audit($conn, 'results_unpublish');
        flash('info', 'Results are hidden from candidates again.');
    }
    redirect('admin/selection.php');
}

$cycle    = cycle_load($conn);
$n        = pipelineCounts($conn);
$stale    = selectionIsStale($conn, $cycle);
$ran      = $cycle['run_at'] !== null;
$schools  = schoolOverview($conn);

/* placed per school per round (the round a candidate was placed in is the choice number they gave) */
$matrix = [];
foreach (
    $conn->query('SELECT sel.school_id, ch.choice_number, COUNT(*) AS c
                         FROM selection sel JOIN choices ch ON ch.index_no = sel.index_no AND ch.school_id = sel.school_id
                        GROUP BY sel.school_id, ch.choice_number')->fetch_all(MYSQLI_ASSOC) as $r
) {
    $matrix[(int)$r['school_id']][(int)$r['choice_number']] = (int)$r['c'];
}

render_header('Selection', 'selection');
page_head('Selection', 'Three rounds, one per choice. Inside each round the highest aggregate gets the seat.');
?>

<?php if ($stale): ?>
    <div class="alert alert-warning" role="status"><strong>Out of date.</strong> Marks or applications changed after the last run on <?= e(fmt_dt($cycle['run_at'])) ?>. Run the selection again.</div>
<?php endif; ?>

<div class="grid grid-2">
    <section class="card">
        <div class="card-head">
            <h2>1. Run the selection</h2>
        </div>
        <dl class="facts">
            <div>
                <dt>Candidates ready</dt>
                <dd><?= $n['ready'] ?></dd>
            </div>
            <div>
                <dt>Awaiting marks</dt>
                <dd><?= $n['awaiting'] ?></dd>
            </div>
            <div>
                <dt>Total seats</dt>
                <dd><?= $n['seats'] ?></dd>
            </div>
            <div>
                <dt>Last run</dt>
                <dd><?= $ran ? e(fmt_dt($cycle['run_at'])) : 'Never' ?></dd>
            </div>
        </dl>
        <?php if ($cycle['status'] === 'open'): ?>
            <div class="alert alert-info" role="status">Applications are still open until <?= e(fmt_dt($cycle['close_at'])) ?>. You can run a trial now and run again after they close.</div>
        <?php endif; ?>
        <?php if ($n['awaiting'] > 0): ?>
            <div class="alert alert-warning" role="status"><?= $n['awaiting'] ?> applicant<?= $n['awaiting'] === 1 ? ' has' : 's have' ?> no marks and will not be placed. <a href="marks.php">Upload marks</a></div>
        <?php endif; ?>
        <form method="post" data-confirm="Run the selection now? Any earlier result will be replaced.">
            <?= csrf_field() ?><input type="hidden" name="action" value="run">
            <button type="submit" class="btn btn-primary btn-lg" <?= $cycle['published'] ? ' disabled' : '' ?>><?= icon('play') ?><?= $ran ? 'Run again' : 'Run selection' ?></button>
            <?php if ($cycle['published']): ?><p class="hint">Results are published. Unpublish them first to run again.</p><?php endif; ?>
        </form>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>2. Publish the results</h2><?= $cycle['published'] ? badge('Published', 'success') : badge('Not published', 'neutral') ?>
        </div>
        <p>School administrators can see their provisional lists as soon as the selection has run. Candidates see their placement only after you publish.</p>
        <?php if ($cycle['published']): ?>
            <p class="muted">Published <?= e(fmt_dt($cycle['published_at'])) ?>.</p>
            <form method="post" data-confirm="Hide the results from candidates?">
                <?= csrf_field() ?><input type="hidden" name="action" value="unpublish">
                <button type="submit" class="btn btn-ghost">Unpublish results</button>
            </form>
        <?php else: ?>
            <form method="post" data-confirm="Publish the results? Every candidate will be able to see where they were placed.">
                <?= csrf_field() ?><input type="hidden" name="action" value="publish">
                <button type="submit" class="btn btn-accent btn-lg" <?= (!$ran || $stale) ? ' disabled' : '' ?>><?= icon('check') ?>Publish results</button>
                <?php if (!$ran): ?><p class="hint">Run the selection first.</p><?php elseif ($stale): ?><p class="hint">Run the selection again first.</p><?php endif; ?>
            </form>
        <?php endif; ?>
    </section>
</div>

<section class="section-gap">
    <div class="toolbar">
        <h2>Outcome by school</h2>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>School</th>
                    <th class="num">Seats</th>
                    <?php for ($r = 1; $r <= CHOICES_PER_CANDIDATE; $r++): ?><th class="num">Round <?= $r ?></th><?php endfor; ?>
                    <th class="num">Placed</th>
                    <th class="num">Vacant</th>
                    <th><span class="visually-hidden">List</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($schools as $s): $cap = (int)$s['capacity'];
                    $sel = (int)$s['selected']; ?>
                    <tr>
                        <td><?= e($s['school_name']) ?></td>
                        <td class="num mono"><?= $cap ?></td>
                        <?php for ($r = 1; $r <= CHOICES_PER_CANDIDATE; $r++): ?><td class="num mono"><?= $ran ? (int)($matrix[(int)$s['school_id']][$r] ?? 0) : '&ndash;' ?></td><?php endfor; ?>
                        <td class="num mono"><strong><?= $ran ? $sel : '&ndash;' ?></strong></td>
                        <td class="num mono"><?= $ran ? max(0, $cap - $sel) : '&ndash;' ?></td>
                        <td class="num"><a href="school.php?school_id=<?= (int)$s['school_id'] ?>">View list</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="hint">"Round 2" is the number of candidates placed in that school as their 2nd choice, after the 1st-choice round filled what it could.</p>
</section>
<?php render_footer();
