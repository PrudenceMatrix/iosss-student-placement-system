<?php

declare(strict_types=1);

/* Candidate sign-in: index number + password (set at sign-up). */

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('login.html');
}

$indexNo  = trim(field($_POST, 'index_no'));
$password = field($_POST, 'password');

if ($indexNo === '' || $password === '') {
    redirect('login.html?error=invalid');
}

$key = throttle_key('student', $indexNo);
if (login_is_locked($conn, $key)) {
    redirect('login.html?error=locked');
}

$st = $conn->prepare('SELECT index_no, full_name, password_hash FROM students WHERE index_no = ?');
$st->bind_param('s', $indexNo);
$st->execute();
$student = $st->get_result()->fetch_assoc();
$st->close();

// Always run password_verify (against a dummy hash when the account does not exist)
// so unknown accounts take as long to reject as wrong passwords.
$dummyHash = '$2y$10$aa48boZMkO82nl8MJfXCM.tLPhQd5GDmyN0KqER2xeuOLEJkwZL/2';
$ok = password_verify($password, $student['password_hash'] ?? $dummyHash) && $student !== null;

if (!$ok) {
    record_attempt($conn, $key);
    redirect('login.html?error=invalid');
}

clear_attempts($conn, $key);
$st = $conn->prepare('UPDATE students SET last_login_at = NOW() WHERE index_no = ?');
$st->bind_param('s', $student['index_no']);
$st->execute();
$st->close();

sign_in([
    'type'     => 'student',
    'index_no' => $student['index_no'],
    'name'     => $student['full_name'],
]);
redirect('student/dashboard.php');
