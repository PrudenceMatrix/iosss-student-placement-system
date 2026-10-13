<?php

declare(strict_types=1);

require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/layout.php';
require_once __DIR__ . '/../php/applications.php';
require_once __DIR__ . '/../php/schools.php';

$me    = require_staff();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $current = field($_POST, 'current');
    $new     = field($_POST, 'new');
    $confirm = field($_POST, 'confirm');

    $st = $conn->prepare('SELECT password_hash FROM users WHERE user_id = ?');
    $st->bind_param('i', $me['user_id']);
    $st->execute();
    $st->bind_result($hash);
    $st->fetch();
    $st->close();

    if (!password_verify($current, (string)$hash)) {
        $error = 'Your current password is not correct.';
    } elseif (($pe = validateStaffPassword($new)) !== '') {
        $error = $pe;
    } elseif ($new !== $confirm) {
        $error = 'The two new passwords do not match.';
    } elseif ($new === $current) {
        $error = 'Choose a password different from the current one.';
    } else {
        $newHash = password_hash($new, PASSWORD_DEFAULT);
        $st = $conn->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
        $st->bind_param('si', $newHash, $me['user_id']);
        $st->execute();
        $st->close();
        audit($conn, 'password_change', $me['username']);
        session_regenerate_id(true);
        flash('success', 'Password changed.');
        redirect('admin/change_password.php');
    }
}

render_header('Change password', 'password');
page_head('Change password', 'Use at least 10 characters with a letter and a digit.');
?>
<?php if ($error !== ''): ?>
    <div class="alert alert-error" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<form method="post" class="card form-card narrow">
    <?= csrf_field() ?>
    <div class="field"><label for="current">Current password</label>
        <input type="password" id="current" name="current" autocomplete="current-password" required>
    </div>
    <div class="field"><label for="new">New password</label>
        <input type="password" id="new" name="new" autocomplete="new-password" minlength="10" required>
    </div>
    <div class="field"><label for="confirm">Confirm new password</label>
        <input type="password" id="confirm" name="confirm" autocomplete="new-password" minlength="10" required>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">Change password</button></div>
</form>
<?php render_footer();
