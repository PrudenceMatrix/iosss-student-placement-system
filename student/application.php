<?php

declare(strict_types=1);

/*
 * The candidate's application: personal details and ranked schools.
 * Open for editing only while the application window is open.
 * Marks are not entered here; the administrator uploads them.
 */

require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/layout.php';
require_once __DIR__ . '/../php/applications.php';

$me      = require_student();
$indexNo = $me['index_no'];
$cycle   = cycle_load($conn);
$open    = $cycle['status'] === 'open';
$schools = fetchSchoolNames($conn);
$saved   = fetchApplication($conn, $indexNo);

$errors = [];
$values = [
    'sex'            => $saved['sex'] ?? '',
    'date_of_birth'  => $saved['date_of_birth'] ?? '',
    'primary_school' => $saved['primary_school'] ?? '',
    'phone'          => $saved['phone'] ?? '',
    'choices'        => array_column($saved['choices'] ?? [], 'school_id', 'choice_number'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!$open) {
        flash('error', 'Applications are not open, so your application was not changed.');
        redirect('student/application.php');
    }

    [$clean, $errors] = validateApplication($_POST, array_keys($schools));
    if (!$errors) {
        try {
            saveApplication($conn, $indexNo, $clean);
            flash('success', $saved ? 'Your application was updated.' : 'Your application was submitted.');
            redirect('student/dashboard.php');
        } catch (mysqli_sql_exception $e) {
            error_log('IOSSS saveApplication failed: ' . $e->getMessage());
            $errors[] = 'Your application could not be saved. Please try again.';
        }
    }
    // Show what the person typed so nothing is lost
    $values = [
        'sex'            => field($_POST, 'sex'),
        'date_of_birth'  => field($_POST, 'date_of_birth'),
        'primary_school' => field($_POST, 'primary_school'),
        'phone'          => field($_POST, 'phone'),
        'choices'        => [],
    ];
    for ($n = 1; $n <= CHOICES_PER_CANDIDATE; $n++) {
        $values['choices'][$n] = (int)field($_POST, 'choice' . $n);
    }
}

$today = new DateTimeImmutable('today');
$dobMax = $today->modify('-10 years')->format('Y-m-d');
$dobMin = $today->modify('-26 years +1 day')->format('Y-m-d');

render_header('My application', 'application');
page_head('My application', cycle_headline($cycle));
?>

<?php if ($errors): ?>
    <div class="alert alert-error" role="alert">
        <strong>Please fix the following:</strong>
        <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<?php if (!$open): ?>
    <div class="alert alert-info" role="status">
        <?= $cycle['status'] === 'not_open'
            ? 'Applications are not open yet. You can fill in your application from ' . e(fmt_dt($cycle['open_at'])) . '.'
            : 'The application window is closed, so your application can no longer be changed.' ?>
    </div>
<?php endif; ?>

<form method="post" class="card form-card">
    <?= csrf_field() ?>
    <fieldset <?= $open ? '' : 'disabled' ?>>
        <legend>Personal details</legend>
        <p class="hint">Your name is <strong><?= e($me['name']) ?></strong> and your index number is <strong class="mono"><?= e($indexNo) ?></strong>. Your marks are uploaded by the administrator, so you do not enter them.</p>

        <div class="form-grid">
            <div class="field">
                <label for="sex">Sex</label>
                <select id="sex" name="sex" required>
                    <option value="">Select</option>
                    <?php foreach (['Male', 'Female'] as $s): ?>
                        <option value="<?= $s ?>" <?= $values['sex'] === $s ? ' selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="date_of_birth">Date of birth</label>
                <input type="date" id="date_of_birth" name="date_of_birth" min="<?= e($dobMin) ?>" max="<?= e($dobMax) ?>" value="<?= e($values['date_of_birth']) ?>" required>
            </div>
            <div class="field">
                <label for="primary_school">Primary school</label>
                <input type="text" id="primary_school" name="primary_school" maxlength="100" value="<?= e($values['primary_school']) ?>" required>
                <p class="hint">The school you sat the exam from.</p>
            </div>
            <div class="field">
                <label for="phone">Phone number <span class="optional">(optional)</span></label>
                <input type="tel" id="phone" name="phone" maxlength="20" inputmode="tel" placeholder="0712 345 678" value="<?= e($values['phone']) ?>">
                <p class="hint">A parent or guardian's number we can reach.</p>
            </div>
        </div>
    </fieldset>

    <fieldset <?= $open ? '' : 'disabled' ?> data-unique-selects>
        <legend>Schools you are applying to</legend>
        <p class="hint">Rank <?= CHOICES_PER_CANDIDATE ?> different schools. Selection works round by round: everyone's 1st choice is considered first, then the 2nd, then the 3rd, and inside each round the highest aggregate gets the seat.</p>

        <div class="form-grid">
            <?php for ($n = 1; $n <= CHOICES_PER_CANDIDATE; $n++): ?>
                <div class="field">
                    <label for="choice<?= $n ?>"><?= e(ordinal($n)) ?> choice</label>
                    <select id="choice<?= $n ?>" name="choice<?= $n ?>" required>
                        <option value="">Select a school</option>
                        <?php foreach ($schools as $id => $name): ?>
                            <option value="<?= $id ?>" <?= (int)($values['choices'][$n] ?? 0) === $id ? ' selected' : '' ?>><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endfor; ?>
        </div>
        <p class="error-text" data-unique-error role="alert"></p>
    </fieldset>

    <?php if ($open): ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg"><?= $saved ? 'Save changes' : 'Submit application' ?></button>
            <a class="btn btn-ghost btn-lg" href="<?= e(url('student/dashboard.php')) ?>">Cancel</a>
        </div>
    <?php endif; ?>
</form>
<?php render_footer();
