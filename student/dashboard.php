<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/layout.php';
require_once __DIR__ . '/../php/applications.php';
require_once __DIR__ . '/../php/iosss_app_process.php';

$me      = require_student();
$indexNo = $me['index_no'];

$st = $conn->prepare('SELECT full_name FROM students WHERE index_no = ?');
$st->bind_param('s', $indexNo);
$st->execute();
$account = $st->get_result()->fetch_assoc();
$st->close();
if (!$account) {          // account was removed while signed in
    end_session();
    redirect('login.html?error=invalid');
}

$cycle     = cycle_load($conn);
$app       = fetchApplication($conn, $indexNo);
$marks     = fetchMarks($conn, $indexNo);
$placement = $cycle['published'] ? getStudentPlacement($conn, $indexNo) : null;
$firstName = explode(' ', $account['full_name'])[0];
$open      = $cycle['status'] === 'open';

/* The five steps shown as a checklist: [state, title, detail, optional button html] */
$steps = [];
$steps[] = ['done', 'Account created', 'You signed up with index number ' . $indexNo . '.', ''];

if ($app) {
    $steps[] = [
        'done',
        'Application submitted',
        'Last saved ' . fmt_dt(parse_stamp((string)$app['updated_at'])) . '.',
        $open ? '<a class="btn btn-sm btn-ghost" href="' . e(url('student/application.php')) . '">Edit application</a>' : ''
    ];
} elseif ($open) {
    $steps[] = [
        'current',
        'Complete your application',
        'Add your personal details and rank three schools before ' . fmt_dt($cycle['close_at']) . '.',
        '<a class="btn btn-sm btn-primary" href="' . e(url('student/application.php')) . '">Start application</a>'
    ];
} elseif ($cycle['status'] === 'not_open') {
    $steps[] = ['todo', 'Application', 'Applications open on ' . fmt_dt($cycle['open_at']) . '.', ''];
} else {
    $steps[] = ['missed', 'Application', 'The application window closed before an application was submitted.', ''];
}

$steps[] = $marks
    ? ['done', 'Marks received', 'Your marks were uploaded by the administrator. Aggregate: ' . (int)$marks['aggregate'] . ' out of ' . (count(MARK_SUBJECTS) * MARK_MAX) . '.', '']
    : ['todo', 'Marks', 'Waiting for the administrator to upload the examination marks. You do not need to do anything.', ''];

$steps[] = $cycle['published']
    ? ['done', 'Selection completed', 'Candidates were placed by choice and aggregate.', '']
    : ['todo', 'Selection', 'Selection runs after applications close on ' . fmt_dt($cycle['close_at']) . '.', ''];

$steps[] = $cycle['published']
    ? ['done', 'Results released', 'Released ' . fmt_dt($cycle['published_at']) . '.', '']
    : ['todo', 'Results', $cycle['release_at'] ? 'Results are expected on ' . fmt_dt($cycle['release_at']) . '.' : 'The results date will be announced.', ''];

render_header('Dashboard', 'dashboard');
page_head('Hello, ' . $firstName, cycle_headline($cycle));
?>

<?php if ($cycle['published']): ?>
    <?php if ($placement): ?>
        <section class="result result-selected" aria-labelledby="result-title">
            <p class="result-kicker" id="result-title">Placement result</p>
            <h2 class="result-title">Congratulations, you have been selected</h2>
            <p class="result-school"><?= e($placement['school_name']) ?></p>
            <p><?= choice_badge((int)$placement['choice_number']) ?> <span class="result-note">You will report to this school as directed by the Ministry.</span></p>
        </section>
    <?php elseif ($app && $marks): ?>
        <section class="result result-none" aria-labelledby="result-title">
            <p class="result-kicker" id="result-title">Placement result</p>
            <h2 class="result-title">You were not placed in one of your three schools</h2>
            <p>The seats in the schools you chose went to candidates with higher aggregates. Please contact your sub-county education office about other options.</p>
        </section>
    <?php else: ?>
        <section class="result result-none" aria-labelledby="result-title">
            <p class="result-kicker" id="result-title">Placement result</p>
            <h2 class="result-title">You were not considered in this selection</h2>
            <p><?= !$app ? 'No application was submitted before the window closed.' : 'No marks were uploaded for your index number.' ?> Please contact your sub-county education office.</p>
        </section>
    <?php endif; ?>
<?php else: ?>
    <section class="result result-wait" aria-labelledby="result-title">
        <p class="result-kicker" id="result-title">Placement result</p>
        <h2 class="result-title">Results have not been released yet</h2>
        <p><?= $cycle['release_at'] ? 'They are expected on ' . e(fmt_dt($cycle['release_at'])) . '.' : 'The release date will be announced.' ?> Check back here, you do not need to do anything else.</p>
    </section>
<?php endif; ?>

<div class="grid grid-2-1 section-gap">
    <section class="card">
        <div class="card-head">
            <h2>Your progress</h2>
        </div>
        <ol class="timeline">
            <?php foreach ($steps as [$state, $title, $detail, $button]): ?>
                <li class="is-<?= e($state) ?>">
                    <span class="timeline-dot"><?= $state === 'done' ? icon('check') : '' ?></span>
                    <div class="timeline-body">
                        <strong><?= e($title) ?></strong>
                        <p><?= e($detail) ?></p>
                        <?= $button ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <div class="stack">
        <section class="card">
            <div class="card-head">
                <h2>Your schools</h2>
            </div>
            <?php if ($app && $app['choices']): ?>
                <ol class="rank-list">
                    <?php foreach ($app['choices'] as $c): ?>
                        <li><?= choice_badge((int)$c['choice_number']) ?><span><?= e($c['school_name']) ?></span></li>
                    <?php endforeach; ?>
                </ol>
            <?php else: ?>
                <p class="muted">You have not chosen schools yet.</p>
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="card-head">
                <h2>Your marks</h2>
            </div>
            <?php if ($marks): ?>
                <table class="table table-compact">
                    <tbody>
                        <?php foreach (MARK_SUBJECTS as $field => $label): ?>
                            <tr>
                                <td><?= e($label) ?></td>
                                <td class="num mono"><?= (int)$marks[$field] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Aggregate</th>
                            <th class="num mono"><?= (int)$marks['aggregate'] ?></th>
                        </tr>
                    </tfoot>
                </table>
            <?php else: ?>
                <p class="muted">Not uploaded yet.</p>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php render_footer();
