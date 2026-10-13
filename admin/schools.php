<?php

declare(strict_types=1);

/* Schools: add a school with its administrator, change a school's name or number of seats. */

require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/layout.php';
require_once __DIR__ . '/../php/applications.php';
require_once __DIR__ . '/../php/iosss_app_process.php';
require_once __DIR__ . '/../php/schools.php';

$me     = require_staff(['super_admin']);
$cycle  = cycle_load($conn);
$errors = [];
$new    = ['name' => '', 'capacity' => '', 'admin_user' => '', 'admin_name' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = field($_POST, 'action');

    if ($action === 'update') {
        $id = (int)field($_POST, 'school_id');
        [$name, $capacity, $errs] = validateSchoolFields(field($_POST, 'school_name'), field($_POST, 'capacity'));
        $st = $conn->prepare('SELECT capacity FROM schools WHERE school_id = ?');
        $st->bind_param('i', $id);
        $st->execute();
        $current = $st->get_result()->fetch_assoc();
        $st->close();

        if (!$current) {
            $errs[] = 'That school was not found.';
        } elseif ($cycle['published'] && $capacity !== (int)$current['capacity']) {
            $errs[] = 'Seats cannot change while results are published. Unpublish them first.';
        }
        if ($errs) {
            flash('error', implode(' ', $errs));
        } else {
            $err = updateSchool($conn, $id, $name, $capacity);
            if ($err !== '') {
                flash('error', $err);
            } else {
                audit($conn, 'school_update', $name, 'Seats ' . $capacity);
                flash('success', 'Saved ' . $name . '.' . (($cycle['run_at'] && $capacity !== (int)$current['capacity']) ? ' Seats changed, so run the selection again.' : ''));
            }
        }
        redirect('admin/schools.php');
    }

    if ($action === 'create') {
        [$name, $capacity, $errors] = validateSchoolFields(field($_POST, 'school_name'), field($_POST, 'capacity'));
        $adminUser = strtolower(trim(field($_POST, 'admin_user')));
        $adminName = tidy(field($_POST, 'admin_name'));
        $adminPass = field($_POST, 'admin_password');
        if (!preg_match('/^[a-z0-9_]{4,30}$/', $adminUser)) {
            $errors[] = 'Administrator username: 4 to 30 letters, digits or underscores.';
        }
        if (text_len($adminName) < 3 || text_len($adminName) > 100) {
            $errors[] = 'Enter the administrator\'s full name.';
        }
        if (($pe = validateStaffPassword($adminPass)) !== '') {
            $errors[] = $pe;
        }
        $new = ['name' => $name, 'capacity' => (string)$capacity, 'admin_user' => $adminUser, 'admin_name' => $adminName];

        if (!$errors) {
            $err = createSchoolWithAdmin($conn, $name, $capacity, $adminUser, $adminName, $adminPass);
            if ($err === '') {
                audit($conn, 'school_create', $name, 'Seats ' . $capacity . ', admin ' . $adminUser);
                flash('success', $name . ' was added with administrator ' . $adminUser . '. It now appears in candidates\' choices.');
                redirect('admin/schools.php');
            }
            $errors[] = $err;
        }
    }
}

$schools = schoolOverview($conn);
$admins  = schoolAdminNames($conn);
$ran     = $cycle['run_at'] !== null;

render_header('Schools', 'schools');
page_head('Schools', 'Candidates can only choose schools listed here.');
?>

<?php if ($errors): ?>
    <div class="alert alert-error" role="alert">
        <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<section class="card">
    <div class="card-head">
        <h2><?= count($schools) ?> school<?= count($schools) === 1 ? '' : 's' ?></h2>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>School name</th>
                    <th>Seats</th>
                    <th class="num">1st choice</th>
                    <th class="num">Placed</th>
                    <th>Administrator</th>
                    <th><span class="visually-hidden">Save</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($schools as $s): $id = (int)$s['school_id']; ?>
                    <tr>
                        <td colspan="2">
                            <form method="post" class="row-form" id="school-<?= $id ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="school_id" value="<?= $id ?>">
                                <input type="text" name="school_name" value="<?= e($s['school_name']) ?>" maxlength="100" aria-label="School name" required>
                                <input type="number" name="capacity" value="<?= (int)$s['capacity'] ?>" min="1" max="20000" aria-label="Seats" class="narrow-input" required>
                            </form>
                        </td>
                        <td class="num mono"><?= (int)$s['first_choice'] ?></td>
                        <td class="num mono"><?= $ran ? (int)$s['selected'] : '&ndash;' ?></td>
                        <td class="muted"><?= e($admins[$id] ?? 'None') ?></td>
                        <td class="num"><button type="submit" form="school-<?= $id ?>" class="btn btn-ghost btn-sm">Save</button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<form method="post" class="card form-card section-gap">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <fieldset>
        <legend>Add a school</legend>
        <p class="hint">Every school gets its own administrator who can see only that school's selected candidates.</p>
        <div class="form-grid">
            <div class="field"><label for="school_name">School name</label>
                <input type="text" id="school_name" name="school_name" maxlength="100" value="<?= e($new['name']) ?>" required>
            </div>
            <div class="field"><label for="capacity">Seats</label>
                <input type="number" id="capacity" name="capacity" min="1" max="20000" value="<?= e($new['capacity']) ?>" required>
            </div>
            <div class="field"><label for="admin_name">Administrator's full name</label>
                <input type="text" id="admin_name" name="admin_name" maxlength="100" value="<?= e($new['admin_name']) ?>" required>
            </div>
            <div class="field"><label for="admin_user">Administrator username</label>
                <input type="text" id="admin_user" name="admin_user" maxlength="30" autocomplete="off" value="<?= e($new['admin_user']) ?>" required>
            </div>
            <div class="field"><label for="admin_password">Starting password</label>
                <input type="password" id="admin_password" name="admin_password" autocomplete="new-password" minlength="10" required>
                <p class="hint">At least 10 characters with a letter and a digit. They can change it after signing in.</p>
            </div>
        </div>
    </fieldset>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Add school</button></div>
</form>
<?php render_footer();
