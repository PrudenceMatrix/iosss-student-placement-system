<?php

declare(strict_types=1);

/* Staff sign-in for the system administrator and school administrators. */

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('staff/login.html');
}

$username = trim(field($_POST, 'username'));
$password = field($_POST, 'password');

if ($username === '' || $password === '') {
    redirect('staff/login.html?error=invalid');
}

$key = throttle_key('staff', $username);
if (login_is_locked($conn, $key)) {
    redirect('staff/login.html?error=locked');
}

$st = $conn->prepare(
    'SELECT u.user_id, u.username, u.password_hash, u.full_name, u.role, u.school_id, s.school_name
       FROM users u LEFT JOIN schools s ON s.school_id = u.school_id
      WHERE u.username = ?'
);
$st->bind_param('s', $username);
$st->execute();
$user = $st->get_result()->fetch_assoc();
$st->close();

$dummyHash = '$2y$10$aa48boZMkO82nl8MJfXCM.tLPhQd5GDmyN0KqER2xeuOLEJkwZL/2';
$ok = password_verify($password, $user['password_hash'] ?? $dummyHash) && $user !== null;

if (!$ok) {
    record_attempt($conn, $key);
    redirect('staff/login.html?error=invalid');
}

clear_attempts($conn, $key);
sign_in([
    'type'        => 'staff',
    'user_id'     => (int)$user['user_id'],
    'username'    => $user['username'],
    'name'        => $user['full_name'],
    'role'        => $user['role'],
    'school_id'   => $user['school_id'] !== null ? (int)$user['school_id'] : null,
    'school_name' => $user['school_name'],
]);

redirect($user['role'] === 'super_admin' ? 'admin/dashboard.php' : 'admin/school.php');
