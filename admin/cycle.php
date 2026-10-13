<?php

declare(strict_types=1);

/* The system administrator sets when applications open and close and when results are released. */

require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/layout.php';
require_once __DIR__ . '/../php/applications.php';

$me    = require_staff(['super_admin']);
$cycle = cycle_load($conn);
$errors = [];

/** "2026-11-03T23:59" from a datetime-local input -> DateTimeImmutable or null. */
function parse_local(string $v): ?DateTimeImmutable
{
    $d = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $v, new DateTimeZone(APP_TIMEZONE));
    return ($d && $d->format('Y-m-d\TH:i') === $v) ? $d : null;
}

function to_local(?DateTimeInterface $d): string
{
    return $d ? $d->format('Y-m-d\TH:i') : '';
}

$form = [
    'name'    => $cycle['name'],
    'open'    => to_local($cycle['open_at']),
    'close'   => to_local($cycle['close_at']),
    'release' => to_local($cycle['release_at']),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $form = [
        'name'    => tidy(field($_POST, 'name')),
        'open'    => field($_POST, 'open'),
        'close'   => field($_POST, 'close'),
        'release' => field($_POST, 'release'),
    ];
    $open = parse_local($form['open']);
    $close = parse_local($form['close']);
    $release = $form['release'] === '' ? null : parse_local($form['release']);

    if (text_len($form['name']) < 3 || text_len($form['name']) > 80) {
        $errors[] = 'Give the cycle a name of 3 to 80 characters, for example "Form One Selection 2027".';
    }
    if (!$open) {
        $errors[] = 'Enter a valid opening date and time.';
    }
    if (!$close) {
        $errors[] = 'Enter a valid closing date and time.';
    }
    if ($open && $close && $close <= $open) {
        $errors[] = 'Applications must close after they open.';
    }
    if ($form['release'] !== '' && !$release) {
        $errors[] = 'The results date is not valid. Leave it blank if it is not decided.';
    }
    if ($release && $close && $release < $close) {
        $errors[] = 'Results cannot be released before applications close.';
    }

    if (!$errors) {
        cycle_save($conn, $form['name'], $open, $close, $release);
        audit($conn, 'cycle_update', $form['name'], 'Open ' . $open->format('Y-m-d H:i') . ', close ' . $close->format('Y-m-d H:i'));
        flash('success', 'The cycle dates were saved. The home page already shows them.');
        redirect('admin/cycle.php');
    }
}

render_header('Cycle and dates', 'cycle');
page_head('Cycle and dates', 'These dates appear on the public home page and control when candidates can apply.');
?>

<?php if ($errors): ?>
    <div class="alert alert-error" role="alert">
        <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="grid grid-2-1">
    <form method="post" class="card form-card">
        <?= csrf_field() ?>
        <fieldset>
            <legend>Application window</legend>
            <div class="field">
                <label for="name">Cycle name</label>
                <input type="text" id="name" name="name" maxlength="80" value="<?= e($form['name']) ?>" required>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label for="open">Applications open</label>
                    <input type="datetime-local" id="open" name="open" value="<?= e($form['open']) ?>" required>
                </div>
                <div class="field">
                    <label for="close">Applications close</label>
                    <input type="datetime-local" id="close" name="close" value="<?= e($form['close']) ?>" required>
                </div>
                <div class="field">
                    <label for="release">Results released <span class="optional">(optional)</span></label>
                    <input type="datetime-local" id="release" name="release" value="<?= e($form['release']) ?>">
                    <p class="hint">Shown to candidates as the expected date. Results appear only after you publish them.</p>
                </div>
            </div>
        </fieldset>
        <div class="form-actions"><button type="submit" class="btn btn-primary">Save dates</button></div>
    </form>

    <section class="card">
        <div class="card-head">
            <h2>Right now</h2>
        </div>
        <p><?= badge(
                ['open' => 'Open', 'not_open' => 'Not open yet', 'closed' => 'Closed', 'unset' => 'Not set'][$cycle['status']],
                ['open' => 'success', 'not_open' => 'warning', 'closed' => 'danger', 'unset' => 'neutral'][$cycle['status']]
            ) ?></p>
        <p><?= e(cycle_headline($cycle)) ?>.</p>
        <p class="muted">All times are East Africa Time (<?= e(APP_TIMEZONE) ?>). Applications are accepted only inside the window, even if a candidate has the page open.</p>
    </section>
</div>
<?php render_footer();
