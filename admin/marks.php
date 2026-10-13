<?php

declare(strict_types=1);

/*
 * Marks register. Only the system administrator uploads marks:
 * one candidate at a time, or a whole CSV file.
 */

require_once __DIR__ . '/../php/bootstrap.php';
require_once __DIR__ . '/../php/layout.php';
require_once __DIR__ . '/../php/applications.php';
require_once __DIR__ . '/../php/marks.php';

$me    = require_staff(['super_admin']);
$cycle = cycle_load($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = field($_POST, 'action');

    if ($action === 'single') {
        $row = ['index_no' => field($_POST, 'index_no')];
        foreach (MARK_SUBJECTS as $f => $label) {
            $row[$f] = field($_POST, $f);
        }
        [$clean, $error] = validateMarksRow($row);
        if ($clean === null) {
            flash('error', 'Marks were not saved: ' . $error . '.');
        } else {
            upsertMarks($conn, [$clean], $me['username']);
            $total = array_sum(array_intersect_key($clean, MARK_SUBJECTS));
            audit($conn, 'marks_single', $clean['index_no'], 'Aggregate ' . $total);
            flash('success', 'Saved marks for ' . $clean['index_no'] . ' (aggregate ' . $total . ').');
        }
    } elseif ($action === 'csv') {
        $file = $_FILES['marks_file'] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            flash('error', 'Choose a CSV file first.');
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            flash('error', $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
                ? 'The file is larger than the server allows (check upload_max_filesize in php.ini).'
                : 'The upload failed. Please try again.');
        } elseif ($file['size'] > MARKS_UPLOAD_MAX_BYTES) {
            flash('error', 'The file is larger than ' . (int)(MARKS_UPLOAD_MAX_BYTES / 1048576) . ' MB. Split it into smaller files.');
        } elseif (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'csv') {
            flash('error', 'Only .csv files are accepted. In Excel use Save As, CSV (Comma delimited).');
        } else {
            try {
                $report = importMarksCsv($conn, $file['tmp_name'], $me['username']);
                if ($report['fatal'] !== '') {
                    flash('error', $report['fatal']);
                } else {
                    audit(
                        $conn,
                        'marks_csv',
                        text_cut(basename((string)$file['name']), 0, 60),
                        $report['processed'] . ' saved, ' . $report['rejected'] . ' rejected'
                    );
                    flash(
                        $report['rejected'] ? 'warning' : 'success',
                        $report['processed'] . ' row(s) saved' . ($report['rejected'] ? ', ' . $report['rejected'] . ' rejected (see the list below)' : '') . '.'
                    );
                    $_SESSION['marks_report'] = $report;
                }
            } catch (Throwable $ex) {
                error_log('IOSSS marks import failed: ' . $ex->getMessage());
                flash('error', 'The import failed part-way. Rows already saved are kept; upload the file again to finish.');
            }
        }
    }
    redirect('admin/marks.php');
}

$report = $_SESSION['marks_report'] ?? null;
unset($_SESSION['marks_report']);

/* Register list: search + pagination */
$q    = trim(field($_GET, 'q'));
$page = current_page();
$like = addcslashes($q, '%_\\') . '%';
$likeName = '%' . addcslashes($q, '%_\\') . '%';

$st = $conn->prepare('SELECT COUNT(*) FROM marks m LEFT JOIN students s ON s.index_no = m.index_no
                       WHERE (CHAR_LENGTH(?) = 0 OR m.index_no LIKE ? OR s.full_name LIKE ?)');
$st->bind_param('sss', $q, $like, $likeName);
$st->execute();
$st->bind_result($total);
$st->fetch();
$st->close();

$limit  = PAGE_SIZE;
$offset = ($page - 1) * $limit;
$st = $conn->prepare(
    'SELECT m.index_no, m.english, m.kiswahili, m.mathematics, m.science, m.sst, m.aggregate, m.uploaded_by, m.uploaded_at,
            s.full_name, a.index_no AS has_application
       FROM marks m
  LEFT JOIN students s ON s.index_no = m.index_no
  LEFT JOIN applications a ON a.index_no = m.index_no
      WHERE (CHAR_LENGTH(?) = 0 OR m.index_no LIKE ? OR s.full_name LIKE ?)
   ORDER BY m.uploaded_at DESC, m.index_no
      LIMIT ? OFFSET ?'
);
$st->bind_param('sssii', $q, $like, $likeName, $limit, $offset);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

$columns = implode(',', array_merge(['index_no'], array_keys(MARK_SUBJECTS)));

render_header('Marks', 'marks');
page_head('Marks register', 'Marks come from you, not from candidates. Upload them before or after candidates sign up.');
?>

<div class="grid grid-2">
    <section class="card">
        <div class="card-head">
            <h2>Upload a CSV file</h2>
        </div>
        <form method="post" enctype="multipart/form-data" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="csv">
            <p>The first row must be the column names:</p>
            <p><code class="code-block"><?= e($columns) ?></code></p>
            <p class="hint">Marks are whole numbers from 0 to <?= MARK_MAX ?>. A row for an index number that already has marks replaces them, so correcting a mistake is just uploading it again. Up to <?= (int)(MARKS_UPLOAD_MAX_BYTES / 1048576) ?> MB per file.</p>
            <div class="field">
                <label for="marks_file">CSV file</label>
                <input type="file" id="marks_file" name="marks_file" accept=".csv,text/csv" required>
            </div>
            <div><button type="submit" class="btn btn-primary"><?= icon('upload') ?>Upload marks</button></div>
        </form>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>Enter one candidate</h2>
        </div>
        <form method="post" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="single">
            <div class="field">
                <label for="index_no">Index number</label>
                <input type="text" id="index_no" name="index_no" maxlength="20" autocomplete="off" required>
            </div>
            <div class="mark-grid">
                <?php foreach (MARK_SUBJECTS as $f => $label): ?>
                    <div class="field">
                        <label for="m_<?= $f ?>"><?= e($label) ?></label>
                        <input type="number" id="m_<?= $f ?>" name="<?= $f ?>" min="0" max="<?= MARK_MAX ?>" inputmode="numeric" required>
                    </div>
                <?php endforeach; ?>
            </div>
            <div><button type="submit" class="btn btn-primary">Save marks</button></div>
        </form>
    </section>
</div>

<?php if ($report && $report['errors']): ?>
    <section class="card section-gap">
        <div class="card-head">
            <h2>Rows that were not saved</h2><?= badge($report['rejected'] . ' rejected', 'danger') ?>
        </div>
        <p class="muted">Fix these lines in your file and upload it again. Rows that were fine are already saved.</p>
        <div class="table-wrap">
            <table class="table table-compact">
                <thead>
                    <tr>
                        <th class="num">Line</th>
                        <th>Problem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['errors'] as $er): ?>
                        <tr>
                            <td class="num mono"><?= (int)$er['line'] ?></td>
                            <td><?= e($er['message']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($report['rejected'] > count($report['errors'])): ?><p class="hint">Showing the first <?= count($report['errors']) ?> problems.</p><?php endif; ?>
        <?php if ($report['truncated']): ?><p class="hint">The file had more than <?= number_format(MARKS_MAX_ROWS) ?> rows; the rest were not read.</p><?php endif; ?>
    </section>
<?php endif; ?>

<section class="section-gap">
    <div class="toolbar">
        <h2><?= number_format((int)$total) ?> candidate<?= (int)$total === 1 ? '' : 's' ?> with marks</h2>
        <form method="get" class="searchbar" role="search">
            <label class="visually-hidden" for="q">Search by index number or name</label>
            <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Search index number or name">
            <button type="submit" class="btn btn-ghost">Search</button>
        </form>
    </div>

    <?php if (!$rows): ?>
        <div class="empty-state"><strong><?= $q === '' ? 'No marks uploaded yet.' : 'No marks match that search.' ?></strong></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table-wide">
                <thead>
                    <tr>
                        <th>Index number</th>
                        <th>Name</th>
                        <?php foreach (MARK_SUBJECTS as $f => $label): ?><th class="num"><?= e(['mathematics' => 'Maths', 'sst' => 'SST'][$f] ?? $label) ?></th><?php endforeach; ?>
                        <th class="num">Aggregate</th>
                        <th>Account</th>
                        <th>Uploaded</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="mono"><?= e($r['index_no']) ?></td>
                            <td><?= $r['full_name'] !== null ? e($r['full_name']) : '<span class="muted">Not signed up</span>' ?></td>
                            <?php foreach (MARK_SUBJECTS as $f => $label): ?><td class="num mono"><?= (int)$r[$f] ?></td><?php endforeach; ?>
                            <td class="num mono"><strong><?= (int)$r['aggregate'] ?></strong></td>
                            <td><?= $r['has_application'] !== null ? badge('Applied', 'success') : ($r['full_name'] !== null ? badge('Signed up', 'info') : badge('No account', 'neutral')) ?></td>
                            <td class="muted nowrap"><?= e(fmt_dt(parse_stamp((string)$r['uploaded_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php render_pagination((int)$total, $page, $limit, $q !== '' ? ['q' => $q] : []); ?>
</section>
<?php render_footer();
