<?php

declare(strict_types=1);
/*
 * iosss_app_process.php  -  the file named in the case study.
 * ---------------------------------------------------------------------
 *  1. RECEIVES the registration form (iosss_application.html), validates it
 *     and STORES the applicant's whole record (account, personal details and
 *     the three school choices) in the database.
 *  2. Holds the SELECTION function (runSelection) and the functions that
 *     DISPLAY the list of selected students (getSelectedStudents,
 *     displaySelectedStudents).
 *
 * Staff pages load this file with require_once to reuse the functions. The
 * form handler at the bottom runs only when this file is requested directly
 * (when the form is submitted), so loading it from another page has no side
 * effects.
 *
 * Where the case study's fields are captured
 *   IndexNo, Name, Sex, DateOfBirth, School (primary), Choices (1st-3rd)
 *        ........ the registration form -> stored by THIS file
 *   Marks per subject .... uploaded by the system administrator (admin/marks.php)
 *   (student/application.php only lets a candidate EDIT the record afterwards)
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/applications.php';

/* =====================================================================
 * PART 1 - SELECTION AND DISPLAY FUNCTIONS
 * =================================================================== */

/*
 * Selection: places candidates into schools, then lists who was placed where.
 *
 * THE RULE (three rounds, one per choice)
 *   Round 1  Take every candidate's 1st choice. For each school, rank the
 *            candidates who chose it by aggregate (highest first) and give
 *            seats to the best until the school is full.
 *   Round 2  Candidates still unplaced are considered for their 2nd choice,
 *            ranked by aggregate, into whatever seats remain.
 *   Round 3  The same again with the 3rd choice.
 *
 * A candidate is placed in at most one school (selection.index_no is the primary
 * key). Equal aggregates are ordered by index number so every run gives the same
 * result.
 *
 * WHY IT SCALES
 *   Each school in each round is ONE indexed query that returns at most the seats
 *   that school has left, so memory use depends on the number of seats, not on the
 *   number of candidates. 4 schools x 3 rounds = 12 queries here; 500 schools x 3
 *   rounds = 1500 small queries, still fast.
 */

/** Insert placements for one school in chunks. */
function insertPlacements(mysqli $conn, array $indexNos, int $schoolId): void
{
    foreach (array_chunk($indexNos, 500) as $chunk) {
        $sql    = 'INSERT INTO selection (index_no, school_id) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?)'));
        $types  = '';
        $params = [];
        foreach ($chunk as $indexNo) {
            $types   .= 'si';
            $params[] = (string)$indexNo;
            $params[] = $schoolId;
        }
        $st = $conn->prepare($sql);
        $st->bind_param($types, ...$params);
        $st->execute();
        $st->close();
    }
}

/**
 * Run the selection and save the result (replacing any earlier run).
 *
 * @return array{eligible:int, placed:int, unplaced:int, awaiting_marks:int, per_round:array, by_school:array}
 */
function runSelection(mysqli $conn): array
{
    if (cycle_load($conn)['published']) {
        throw new RuntimeException('Results are published. Unpublish them before running the selection again.');
    }

    $conn->begin_transaction();
    try {
        $conn->query('DELETE FROM selection');

        $schools = [];
        foreach ($conn->query('SELECT school_id, school_name, capacity FROM schools ORDER BY school_id')->fetch_all(MYSQLI_ASSOC) as $r) {
            $schools[(int)$r['school_id']] = ['name' => $r['school_name'], 'capacity' => (int)$r['capacity']];
        }
        $taken    = array_fill_keys(array_keys($schools), 0);
        $perRound = array_fill(1, CHOICES_PER_CANDIDATE, 0);

        // Best-aggregate candidates who chose this school in this round and are not placed yet.
        $pick = $conn->prepare(
            'SELECT c.index_no
               FROM choices c
               JOIN marks m ON m.index_no = c.index_no
          LEFT JOIN selection s ON s.index_no = c.index_no
              WHERE c.choice_number = ? AND c.school_id = ? AND s.index_no IS NULL
              ORDER BY m.aggregate DESC, c.index_no ASC
              LIMIT ?'
        );

        for ($round = 1; $round <= CHOICES_PER_CANDIDATE; $round++) {
            foreach ($schools as $schoolId => $school) {
                $left = $school['capacity'] - $taken[$schoolId];
                if ($left <= 0) {
                    continue;
                }
                $pick->bind_param('iii', $round, $schoolId, $left);
                $pick->execute();
                $ids = array_column($pick->get_result()->fetch_all(MYSQLI_ASSOC), 'index_no');
                if ($ids) {
                    insertPlacements($conn, $ids, $schoolId);
                    $taken[$schoolId]  += count($ids);
                    $perRound[$round]  += count($ids);
                }
            }
        }
        $pick->close();

        $eligible = (int)$conn->query('SELECT COUNT(*) FROM applications a JOIN marks m ON m.index_no = a.index_no')->fetch_row()[0];
        $awaiting = (int)$conn->query('SELECT COUNT(*) FROM applications a LEFT JOIN marks m ON m.index_no = a.index_no WHERE m.index_no IS NULL')->fetch_row()[0];
        $placed   = array_sum($taken);

        set_setting($conn, 'selection_run_at', now_dt()->format('Y-m-d H:i:s'));
        $conn->commit();

        return [
            'eligible'       => $eligible,
            'placed'         => $placed,
            'unplaced'       => $eligible - $placed,
            'awaiting_marks' => $awaiting,
            'per_round'      => $perRound,
            'by_school'      => $taken,
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

/** True when marks or applications changed after the last selection run. */
function selectionIsStale(mysqli $conn, array $cycle): bool
{
    if (!$cycle['run_at']) {
        return false;
    }
    $run = $cycle['run_at']->format('Y-m-d H:i:s');
    $st = $conn->prepare('SELECT ((SELECT MAX(uploaded_at) FROM marks) > ?) OR ((SELECT MAX(updated_at) FROM applications) > ?)');
    $st->bind_param('ss', $run, $run);
    $st->execute();
    $st->bind_result($stale);
    $st->fetch();
    $st->close();
    return (bool)$stale;
}

function publishResults(mysqli $conn, bool $publish): void
{
    set_setting($conn, 'results_published', $publish ? '1' : '0');
    set_setting($conn, 'results_published_at', $publish ? now_dt()->format('Y-m-d H:i:s') : '');
}

/** Headline numbers for the dashboard. */
function pipelineCounts(mysqli $conn): array
{
    $one = static fn(string $sql): int => (int)$conn->query($sql)->fetch_row()[0];
    return [
        'accounts'     => $one('SELECT COUNT(*) FROM students'),
        'applications' => $one('SELECT COUNT(*) FROM applications'),
        'marks'        => $one('SELECT COUNT(*) FROM marks'),
        'ready'        => $one('SELECT COUNT(*) FROM applications a JOIN marks m ON m.index_no = a.index_no'),
        'awaiting'     => $one('SELECT COUNT(*) FROM applications a LEFT JOIN marks m ON m.index_no = a.index_no WHERE m.index_no IS NULL'),
        'placed'       => $one('SELECT COUNT(*) FROM selection'),
        'seats'        => $one('SELECT COALESCE(SUM(capacity), 0) FROM schools'),
        'schools'      => $one('SELECT COUNT(*) FROM schools'),
    ];
}

/** One row per school: seats, how many were placed, and how many candidates want it. */
function schoolOverview(mysqli $conn): array
{
    return $conn->query(
        'SELECT sch.school_id, sch.school_name, sch.capacity,
                (SELECT COUNT(*) FROM selection s WHERE s.school_id = sch.school_id) AS selected,
                (SELECT COUNT(*) FROM choices c WHERE c.school_id = sch.school_id AND c.choice_number = 1) AS first_choice
           FROM schools sch ORDER BY sch.school_name'
    )->fetch_all(MYSQLI_ASSOC);
}

const SELECTED_SQL =
'SELECT st.index_no, st.full_name, m.aggregate, ch.choice_number, sch.school_id, sch.school_name
       FROM selection sel
       JOIN students st ON st.index_no = sel.index_no
       JOIN marks m     ON m.index_no  = sel.index_no
       JOIN choices ch  ON ch.index_no = sel.index_no AND ch.school_id = sel.school_id
       JOIN schools sch ON sch.school_id = sel.school_id';

function countSelected(mysqli $conn, int $schoolId): int
{
    $st = $conn->prepare('SELECT COUNT(*) FROM selection WHERE school_id = ?');
    $st->bind_param('i', $schoolId);
    $st->execute();
    $st->bind_result($n);
    $st->fetch();
    $st->close();
    return (int)$n;
}

/** One page of a school's selected candidates, best aggregate first. */
function getSelectedStudents(mysqli $conn, int $schoolId, int $limit, int $offset): array
{
    $st = $conn->prepare(SELECTED_SQL . ' WHERE sel.school_id = ? ORDER BY m.aggregate DESC, st.index_no ASC LIMIT ? OFFSET ?');
    $st->bind_param('iii', $schoolId, $limit, $offset);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
    return $rows;
}

/** Stream every selected candidate (one school or all) to $each, without loading them all into memory. */
function eachSelectedStudent(mysqli $conn, ?int $schoolId, callable $each): void
{
    $sql = SELECTED_SQL . ($schoolId !== null ? ' WHERE sel.school_id = ' . (int)$schoolId : '')
        . ' ORDER BY sch.school_name, m.aggregate DESC, st.index_no ASC';
    $res = $conn->query($sql, MYSQLI_USE_RESULT);
    while ($row = $res->fetch_assoc()) {
        $each($row);
    }
    $res->free();
}

/** Print the table of selected candidates: rank, index number, name, aggregate, choice. */
function displaySelectedStudents(array $rows, int $startRank = 1): void
{
    if (!$rows) {
        echo '<div class="empty-state"><strong>Nobody is on this list yet.</strong></div>';
        return;
    }

    echo '<div class="table-wrap"><table class="table"><thead><tr>'
        . '<th class="num">Rank</th><th>Index number</th><th>Name</th><th class="num">Aggregate</th><th>Choice given to this school</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $i => $r) {
        echo '<tr>'
            . '<td class="num">' . ($startRank + $i) . '</td>'
            . '<td class="mono">' . e($r['index_no']) . '</td>'
            . '<td>' . e($r['full_name']) . '</td>'
            . '<td class="num mono">' . (int)$r['aggregate'] . '</td>'
            . '<td>' . choice_badge((int)$r['choice_number']) . '</td>'
            . '</tr>';
    }
    echo '</tbody></table></div>';
}

/** Where a candidate was placed (school and which choice), or null. */
function getStudentPlacement(mysqli $conn, string $indexNo): ?array
{
    $st = $conn->prepare(
        'SELECT sch.school_name, ch.choice_number
           FROM selection sel
           JOIN schools sch ON sch.school_id = sel.school_id
           JOIN choices ch  ON ch.index_no = sel.index_no AND ch.school_id = sel.school_id
          WHERE sel.index_no = ?'
    );
    $st->bind_param('s', $indexNo);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return $row ?: null;
}

/* =====================================================================
 * PART 2 - REGISTRATION FORM HANDLER (runs only when this file is requested directly)
 *
 * iosss_application.html collects the whole applicant record:
 *   IndexNo, Name, Sex, DateOfBirth, primary School and the 1st, 2nd and 3rd
 *   choice of school (plus a password for the candidate's portal).
 * Marks are NOT on the form: the system administrator uploads them.
 * =================================================================== */

/** Completed registrations allowed per connection per hour. A school computer lab shares one address, so keep this generous. */
const SIGNUPS_PER_HOUR = 30;

function registration_error_page(int $status, array $messages, string $title = 'We could not register you'): never
{
    http_response_code($status);
    render_header('Registration failed', 'signup');
    echo '<div class="narrow stack">';
    echo '<h1>' . e($title) . '</h1>';
    echo '<div class="alert alert-error" role="alert"><ul>';
    foreach ($messages as $m) {
        echo '<li>' . e($m) . '</li>';
    }
    echo '</ul></div>';
    echo '<p class="row"><a class="btn btn-primary" href="' . e(url('iosss_application.html')) . '" data-back>Go back and fix it</a>'
       . '<a class="btn btn-ghost" href="' . e(url('login.html')) . '">I already have an account</a></p>';
    echo '</div>';
    render_footer();
    exit;
}

/** Store the account, the application and the ranked schools: all three or nothing. */
function registerCandidate(mysqli $conn, array $signup, array $app): void
{
    $conn->begin_transaction();
    try {
        createStudentAccount($conn, $signup);
        writeApplicationRows($conn, $signup['index_no'], $app);
        $conn->commit();
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        throw $e;
    }
}

/** Receive the registration form, validate it and store the applicant's record. */
function handle_registration(mysqli $conn): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('iosss_application.html');
    }

    // A hidden field real people never fill in. Bots do; they get a normal-looking success.
    if (field($_POST, 'website') !== '') {
        redirect('login.html?notice=created');
    }

    $limitKey = throttle_key('signup', client_ip());
    if (attempts_in_window($conn, $limitKey, 60) >= SIGNUPS_PER_HOUR) {
        registration_error_page(429, ['Too many registrations were made from this connection. Please try again in an hour.']);
    }

    // The application window is enforced here, on the server, not just on the page.
    $cycle = cycle_load($conn);
    if ($cycle['status'] !== 'open') {
        registration_error_page(403, [cycle_headline($cycle) . '. You can register only while applications are open.'], 'Applications are not open');
    }

    $schoolNames = fetchSchoolNames($conn);
    [$signup, $app, $errors] = validateRegistration($_POST, array_keys($schoolNames));
    if ($errors) {
        registration_error_page(422, $errors);
    }

    try {
        registerCandidate($conn, $signup, $app);
    } catch (mysqli_sql_exception $ex) {
        if ((int)$ex->getCode() === 1062) {
            registration_error_page(409, ['This index number is already registered. Sign in to see or edit your application, or ask the administrator to reset your password.']);
        }
        error_log('IOSSS registration failed: ' . $ex->getMessage());
        registration_error_page(500, ['Something went wrong while saving your registration. Nothing was saved. Please try again.']);
    }

    record_attempt($conn, $limitKey);   // only completed registrations count towards the limit

    // Show what was stored, so the applicant can check it.
    render_header('Registration received', 'signup');
    ?>
    <div class="stack">
        <h1>Registration received</h1>
        <div class="alert alert-success" role="status">
            Your record has been saved. Your examination marks are uploaded by the administrator, so you do not enter them.
            Sign in with your index number and password to follow your progress, or to change your choices before applications close.
        </div>

        <section class="card">
            <div class="card-head"><h2>What you submitted</h2></div>
            <dl class="facts">
                <div><dt>Index number</dt><dd class="mono"><?= e($signup['index_no']) ?></dd></div>
                <div><dt>Name</dt><dd><?= e($signup['full_name']) ?></dd></div>
                <div><dt>Sex</dt><dd><?= e($app['sex']) ?></dd></div>
                <div><dt>Date of birth</dt><dd><?= e($app['date_of_birth']) ?></dd></div>
                <div><dt>Primary school</dt><dd><?= e($app['primary_school']) ?></dd></div>
                <?php if ($app['phone'] !== null): ?><div><dt>Phone</dt><dd><?= e($app['phone']) ?></dd></div><?php endif; ?>
            </dl>
        </section>

        <section class="card">
            <div class="card-head"><h2>Your school choices</h2></div>
            <ol class="rank-list">
                <?php foreach ($app['choices'] as $n => $schoolId): ?>
                    <li><?= choice_badge((int)$n) ?><span><?= e($schoolNames[$schoolId] ?? '') ?></span></li>
                <?php endforeach; ?>
            </ol>
        </section>

        <p class="row">
            <a class="btn btn-primary btn-lg" href="<?= e(url('login.html')) ?>">Sign in to my portal</a>
        </p>
    </div>
    <?php
    render_footer();
    exit;
}

if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    handle_registration($conn);
}
