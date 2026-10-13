<?php

declare(strict_types=1);

/*
 * Candidate accounts and applications.
 * Sign-up creates the account; the application (personal details + ranked
 * schools) is filled in later, inside the student portal, while the
 * application window is open. Marks are NOT entered here: the system
 * administrator uploads them (see marks.php).
 */

/** Strip repeated spaces, trim. */
function tidy(string $s): string
{
    return preg_replace('/\s+/u', ' ', trim($s)) ?? '';
}

/**
 * Check the sign-up form. Returns [clean, errors].
 */
function validateSignup(array $in): array
{
    $errors = [];
    $clean = [];

    $clean['index_no'] = trim(field($in, 'index_no'));
    if (!preg_match(INDEX_NO_PATTERN, $clean['index_no'])) {
        $errors[] = 'Enter your index number exactly as it appears on your exam slip.';
    }

    $clean['full_name'] = tidy(field($in, 'full_name'));
    if (!preg_match('/^\p{L}[\p{L} .\'\-]{2,99}$/u', $clean['full_name'])) {
        $errors[] = 'Enter your full name using letters only (3 to 100 characters).';
    } elseif (count(explode(' ', $clean['full_name'])) < 2) {
        $errors[] = 'Enter at least two names, for example "Brian Ongatia".';
    }

    $password = field($in, 'password');
    if (
        strlen($password) < 8 || strlen($password) > 72
        || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)
    ) {
        $errors[] = 'Password must be 8 to 72 characters with at least one letter and one digit.';
    } elseif ($password !== field($in, 'password_confirm')) {
        $errors[] = 'The two passwords do not match.';
    }
    $clean['password'] = $password;

    return [$clean, $errors];
}

/** Create the account. Throws mysqli_sql_exception (code 1062) if the index number exists. */
function createStudentAccount(mysqli $conn, array $c): void
{
    $hash = password_hash($c['password'], PASSWORD_DEFAULT);
    $st = $conn->prepare('INSERT INTO students (index_no, full_name, password_hash) VALUES (?, ?, ?)');
    $st->bind_param('sss', $c['index_no'], $c['full_name'], $hash);
    $st->execute();
    $st->close();
}

/** [school_id => school_name] ordered by name. */
function fetchSchoolNames(mysqli $conn): array
{
    $names = [];
    foreach ($conn->query('SELECT school_id, school_name FROM schools ORDER BY school_name')->fetch_all(MYSQLI_ASSOC) as $r) {
        $names[(int)$r['school_id']] = $r['school_name'];
    }
    return $names;
}

/**
 * Check the application form (personal details + ranked schools).
 * Returns [clean, errors].
 *
 * @param int[] $validSchoolIds ids of schools that exist
 */
function validateApplication(array $in, array $validSchoolIds): array
{
    $errors = [];
    $clean  = [];

    $clean['sex'] = field($in, 'sex');
    if (!in_array($clean['sex'], ['Male', 'Female'], true)) {
        $errors[] = 'Select Male or Female.';
    }

    $dobRaw = field($in, 'date_of_birth');
    $dob    = DateTime::createFromFormat('!Y-m-d', $dobRaw);
    $clean['date_of_birth'] = $dobRaw;
    if (!$dob || $dob->format('Y-m-d') !== $dobRaw) {
        $errors[] = 'Enter a valid date of birth.';
    } else {
        $today = new DateTime('today');
        if ($dob > $today) {
            $errors[] = 'Date of birth cannot be in the future.';
        } else {
            $age = $dob->diff($today)->y;
            if ($age < 10 || $age > 25) {
                $errors[] = 'Date of birth gives an age outside the accepted range (10 to 25 years).';
            }
        }
    }

    $clean['primary_school'] = tidy(field($in, 'primary_school'));
    if (!preg_match('/^[\p{L}\p{N} .,\'&\-]{2,100}$/u', $clean['primary_school'])) {
        $errors[] = 'Enter the name of your primary school (2 to 100 characters).';
    }

    $phone = preg_replace('/[\s\-]/', '', field($in, 'phone')) ?? '';
    $clean['phone'] = $phone === '' ? null : $phone;
    if ($phone !== '' && !preg_match(PHONE_PATTERN, $phone)) {
        $errors[] = 'Enter a valid Kenyan mobile number such as 0712 345 678, or leave it blank.';
    }

    $clean['choices'] = [];
    for ($n = 1; $n <= CHOICES_PER_CANDIDATE; $n++) {
        $id = filter_var($in['choice' . $n] ?? '', FILTER_VALIDATE_INT);
        if ($id === false || !in_array($id, $validSchoolIds, true)) {
            $errors[] = 'Select a school for choice ' . $n . '.';
        } else {
            $clean['choices'][$n] = $id;
        }
    }
    if (
        count($clean['choices']) === CHOICES_PER_CANDIDATE
        && count(array_unique($clean['choices'])) !== CHOICES_PER_CANDIDATE
    ) {
        $errors[] = 'Your choices must be different schools.';
    }

    return [$clean, $errors];
}

/**
 * Check the whole registration form in one go: the account part (index number,
 * name, password) and the application part (sex, date of birth, primary school,
 * phone, ranked schools).
 * Returns [signupClean, applicationClean, errors].
 *
 * @param int[] $validSchoolIds ids of schools that exist
 */
function validateRegistration(array $in, array $validSchoolIds): array
{
    [$signup, $e1] = validateSignup($in);
    [$app, $e2]    = validateApplication($in, $validSchoolIds);
    return [$signup, $app, array_merge($e1, $e2)];
}

/**
 * Write the application row and its ranked schools. It does NOT manage a
 * transaction, so a caller can combine it with other writes (registration
 * creates the account and the application together).
 */
function writeApplicationRows(mysqli $conn, string $indexNo, array $c): void
{
    $st = $conn->prepare(
        'INSERT INTO applications (index_no, sex, date_of_birth, primary_school, phone)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE sex = VALUES(sex), date_of_birth = VALUES(date_of_birth),
                                 primary_school = VALUES(primary_school), phone = VALUES(phone)'
    );
    $st->bind_param('sssss', $indexNo, $c['sex'], $c['date_of_birth'], $c['primary_school'], $c['phone']);
    $st->execute();
    $st->close();

    $st = $conn->prepare('DELETE FROM choices WHERE index_no = ?');
    $st->bind_param('s', $indexNo);
    $st->execute();
    $st->close();

    $st = $conn->prepare('INSERT INTO choices (index_no, choice_number, school_id) VALUES (?, ?, ?)');
    foreach ($c['choices'] as $choiceNumber => $schoolId) {
        $num = (int)$choiceNumber;
        $sid = (int)$schoolId;
        $st->bind_param('sii', $indexNo, $num, $sid);
        $st->execute();
    }
    $st->close();
}

/**
 * Save (create or update) an existing candidate's application, all or nothing.
 * Used by the student portal when a candidate edits their application.
 * Changing choices removes any placement the candidate already had; the next
 * selection run decides again.
 */
function saveApplication(mysqli $conn, string $indexNo, array $c): void
{
    $conn->begin_transaction();
    try {
        writeApplicationRows($conn, $indexNo, $c);
        $conn->commit();
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        throw $e;
    }
}

/** The candidate's application and ranked schools, or null if none yet. */
function fetchApplication(mysqli $conn, string $indexNo): ?array
{
    $st = $conn->prepare('SELECT sex, date_of_birth, primary_school, phone, submitted_at, updated_at FROM applications WHERE index_no = ?');
    $st->bind_param('s', $indexNo);
    $st->execute();
    $app = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$app) {
        return null;
    }

    $st = $conn->prepare(
        'SELECT c.choice_number, c.school_id, s.school_name
           FROM choices c JOIN schools s ON s.school_id = c.school_id
          WHERE c.index_no = ? ORDER BY c.choice_number'
    );
    $st->bind_param('s', $indexNo);
    $st->execute();
    $app['choices'] = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $app;
}

/** The candidate's marks row, or null if the administrator has not uploaded them yet. */
function fetchMarks(mysqli $conn, string $indexNo): ?array
{
    $st = $conn->prepare('SELECT english, kiswahili, mathematics, science, sst, aggregate, uploaded_at FROM marks WHERE index_no = ?');
    $st->bind_param('s', $indexNo);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/** A readable random password for administrator-assisted resets (no look-alike characters). */
function generateTempPassword(int $length = 10): string
{
    $letters = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    $digits  = '23456789';
    $all     = $letters . $digits;
    $pw      = $letters[random_int(0, strlen($letters) - 1)] . $digits[random_int(0, strlen($digits) - 1)];
    for ($i = strlen($pw); $i < $length; $i++) {
        $pw .= $all[random_int(0, strlen($all) - 1)];
    }
    return str_shuffle($pw);
}
