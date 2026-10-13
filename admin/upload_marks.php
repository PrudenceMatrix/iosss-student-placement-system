<?php

declare(strict_types=1);
require_once __DIR__ . '/../php/iosss_app_process.php';
require_staff(['super_admin']);

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $indexNo = trim((string)($_POST['index_no'] ?? ''));
    $scores = [];
    $valid = true;

    foreach (SUBJECTS as $field => $label) {
        $val = filter_var($_POST[$field] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
        if ($val === false) {
            $valid = false;
            break;
        }
        $scores[$field] = $val;
    }

    if ($valid && $indexNo !== '') {
        try {
            saveStudentMarks($conn, $indexNo, $scores);
            $message = "Marks updated successfully for $indexNo.";
            $messageType = 'success';
        } catch (Throwable $e) {
            $message = "Failed to update marks: " . $e->getMessage();
            $messageType = 'error';
        }
    } else {
        $message = "Invalid input. Ensure all marks are between 0 and 100.";
        $messageType = 'error';
    }
}

// Fetch students who need marks or to edit existing ones
$q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$like = '%' . addcslashes($q, '%_\\') . '%';
$st = $conn->prepare(
    'SELECT st.index_no, st.name, sc.aggregate 
     FROM students st 
     LEFT JOIN scores sc ON sc.index_no = st.index_no 
     WHERE (CHAR_LENGTH(?) = 0 OR st.index_no LIKE ? OR st.name LIKE ?) 
     ORDER BY st.name LIMIT 50'
);
$st->bind_param('sss', $q, $like, $like);
$st->execute();
$students = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

render_header('Upload Marks', 'dashboard');
?>
<h1>Student Marks Management</h1>

<?php if ($message): ?>
    <div class="alert alert-<?= e($messageType) ?>"><?= e($message) ?></div>
<?php endif; ?>

<div class="dashboard-grid">
    <section class="block">
        <h2>Update Marks</h2>
        <p class="muted">Select a student from the list or enter their index number manually.</p>
        <form method="post" class="form-stack">
            <?= csrf_field() ?>
            <div>
                <label for="index_no">Index Number</label>
                <input type="text" id="index_no" name="index_no" required list="student-list">
                <datalist id="student-list">
                    <?php foreach ($students as $s): ?>
                        <option value="<?= e($s['index_no']) ?>"><?= e($s['name']) ?></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-grid">
                <?php foreach (SUBJECTS as $field => $label): ?>
                    <div>
                        <label for="<?= $field ?>"><?= $label ?></label>
                        <input type="number" id="<?= $field ?>" name="<?= $field ?>" min="0" max="100" required>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-success">Save Marks</button>
        </form>
    </section>

    <section class="block">
        <h2>Applicants List</h2>
        <form method="get" class="search no-print" role="search" style="margin-bottom: 1rem;">
            <div class="search-row" style="display: flex; gap: 0.5rem;">
                <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Search name or index...">
                <button type="submit" class="btn btn-primary btn-sm">Search</button>
            </div>
        </form>
        <div class="table-wrap">
            <table class="data">
                <thead>
                    <tr>
                        <th>Index No</th>
                        <th>Name</th>
                        <th class="num">Current Aggregate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($students)): ?>
                        <tr>
                            <td colspan="3">No students found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($students as $s): ?>
                            <tr>
                                <td><?= e($s['index_no']) ?></td>
                                <td><?= e($s['name']) ?></td>
                                <td class="num"><?= $s['aggregate'] !== null ? (int)$s['aggregate'] : '<span class="tag tag-pending">Pending</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php render_footer(); ?>