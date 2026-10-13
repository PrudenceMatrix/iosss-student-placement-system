<?php

declare(strict_types=1);

/* School management: add a school (with its administrator), change a school's name or seats. */

/** Validate name and seat count. Returns [name, capacity, errors]. */
function validateSchoolFields(string $name, string $capacityRaw): array
{
    $errors = [];
    $name = tidy($name);
    if (!preg_match('/^[\p{L}\p{N} .,\'&\-]{3,100}$/u', $name)) {
        $errors[] = 'Enter the school name (3 to 100 characters).';
    }
    $capacity = filter_var($capacityRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20000]]);
    if ($capacity === false) {
        $errors[] = 'Seats must be a whole number from 1 to 20000.';
        $capacity = 0;
    }
    return [$name, (int)$capacity, $errors];
}

function validateStaffPassword(string $password): string
{
    if (strlen($password) < 10 || strlen($password) > 72 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Passwords must be 10 to 72 characters with at least one letter and one digit.';
    }
    return '';
}

/** [school_id => 'username, username'] */
function schoolAdminNames(mysqli $conn): array
{
    $out = [];
    foreach ($conn->query("SELECT school_id, username FROM users WHERE role = 'school_admin' ORDER BY username")->fetch_all(MYSQLI_ASSOC) as $r) {
        $out[(int)$r['school_id']][] = $r['username'];
    }
    return array_map(static fn($names) => implode(', ', $names), $out);
}

/**
 * Add a school and its administrator account together.
 * Returns an error message, or '' on success.
 */
function createSchoolWithAdmin(mysqli $conn, string $name, int $capacity, string $adminUser, string $adminName, string $adminPassword): string
{
    $conn->begin_transaction();
    try {
        $st = $conn->prepare('INSERT INTO schools (school_name, capacity) VALUES (?, ?)');
        $st->bind_param('si', $name, $capacity);
        $st->execute();
        $schoolId = (int)$conn->insert_id;
        $st->close();

        $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
        $st = $conn->prepare("INSERT INTO users (username, password_hash, full_name, role, school_id) VALUES (?, ?, ?, 'school_admin', ?)");
        $st->bind_param('sssi', $adminUser, $hash, $adminName, $schoolId);
        $st->execute();
        $st->close();

        $conn->commit();
        return '';
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        if ((int)$e->getCode() === 1062) {
            return str_contains($e->getMessage(), 'uq_school_name')
                ? 'A school with that name already exists.'
                : 'That administrator username is already taken.';
        }
        error_log('IOSSS createSchool failed: ' . $e->getMessage());
        return 'The school could not be saved. Try again.';
    }
}

/** Returns an error message, or '' on success. */
function updateSchool(mysqli $conn, int $schoolId, string $name, int $capacity): string
{
    try {
        $st = $conn->prepare('UPDATE schools SET school_name = ?, capacity = ? WHERE school_id = ?');
        $st->bind_param('sii', $name, $capacity, $schoolId);
        $st->execute();
        $st->close();
        return '';
    } catch (mysqli_sql_exception $e) {
        if ((int)$e->getCode() === 1062) {
            return 'Another school already has that name.';
        }
        error_log('IOSSS updateSchool failed: ' . $e->getMessage());
        return 'The school could not be saved. Try again.';
    }
}
