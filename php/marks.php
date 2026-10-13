<?php

declare(strict_types=1);

/*
 * The marks register. Only the system administrator writes here.
 * Marks are keyed by index number and do not need the candidate to have an
 * account yet, so the register can be loaded before or after sign-up.
 */

/**
 * Validate one candidate's marks. $row has index_no plus one entry per subject.
 * Returns [clean|null, error|''].
 */
function validateMarksRow(array $row): array
{
    $indexNo = trim((string)($row['index_no'] ?? ''));
    if (!preg_match(INDEX_NO_PATTERN, $indexNo)) {
        return [null, 'invalid index number "' . text_cut($indexNo, 0, 25) . '"'];
    }

    $clean = ['index_no' => $indexNo];
    foreach (MARK_SUBJECTS as $field => $label) {
        $raw = $row[$field] ?? '';
        $value = filter_var(
            is_string($raw) ? trim($raw) : $raw,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => MARK_MAX]]
        );
        if ($value === false) {
            return [null, $label . ' must be a whole number from 0 to ' . MARK_MAX];
        }
        $clean[$field] = $value;
    }
    return [$clean, ''];
}

/**
 * Insert or update many marks rows with one statement.
 * Re-uploading a corrected row simply overwrites it.
 *
 * @param array[] $rows validated rows from validateMarksRow()
 */
function upsertMarks(mysqli $conn, array $rows, string $actor): void
{
    if (!$rows) {
        return;
    }
    $fields = array_keys(MARK_SUBJECTS);
    $one    = '(' . implode(',', array_fill(0, count($fields) + 2, '?')) . ')';
    $sql    = 'INSERT INTO marks (index_no, ' . implode(', ', $fields) . ', uploaded_by) VALUES '
        . implode(',', array_fill(0, count($rows), $one))
        . ' ON DUPLICATE KEY UPDATE '
        . implode(', ', array_map(static fn($f) => "$f = VALUES($f)", $fields))
        . ', uploaded_by = VALUES(uploaded_by)';

    $types  = '';
    $params = [];
    foreach ($rows as $r) {
        $types .= 's' . str_repeat('i', count($fields)) . 's';
        $params[] = $r['index_no'];
        foreach ($fields as $f) {
            $params[] = $r[$f];
        }
        $params[] = $actor;
    }

    $conn->begin_transaction();
    try {
        $st = $conn->prepare($sql);
        $st->bind_param($types, ...$params);
        $st->execute();
        $st->close();
        $conn->commit();
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Import a CSV file of marks.
 * Header row required: index_no plus one column per subject (any order,
 * names are case-insensitive). Valid rows are saved; invalid rows are listed
 * so they can be corrected and uploaded again.
 *
 * @return array{processed:int, rejected:int, errors:array, fatal:string, truncated:bool}
 */
function importMarksCsv(mysqli $conn, string $path, string $actor): array
{
    $report = ['processed' => 0, 'rejected' => 0, 'errors' => [], 'fatal' => '', 'truncated' => false];

    $fh = fopen($path, 'rb');
    if ($fh === false) {
        $report['fatal'] = 'The uploaded file could not be read.';
        return $report;
    }

    $header = fgetcsv($fh, 0, ',', '"', '');
    if (!$header) {
        fclose($fh);
        $report['fatal'] = 'The file is empty.';
        return $report;
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);  // Excel's byte order mark
    $columns = [];
    foreach ($header as $i => $name) {
        $key = strtolower(trim((string)$name));
        $key = ['index' => 'index_no', 'indexno' => 'index_no', 'index number' => 'index_no', 'social studies' => 'sst'][$key] ?? $key;
        $columns[$key] = $i;
    }
    $needed  = array_merge(['index_no'], array_keys(MARK_SUBJECTS));
    $missing = array_values(array_diff($needed, array_keys($columns)));
    if ($missing) {
        fclose($fh);
        $report['fatal'] = 'Missing column(s): ' . implode(', ', $missing) . '. The first row must be: ' . implode(',', $needed);
        return $report;
    }

    $batch = [];
    $line  = 1;
    while (($cells = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        $line++;
        if ($cells === [null] || count(array_filter($cells, static fn($c) => trim((string)$c) !== '')) === 0) {
            continue; // blank line
        }
        if ($report['processed'] + $report['rejected'] >= MARKS_MAX_ROWS) {
            $report['truncated'] = true;
            break;
        }

        $row = [];
        foreach ($needed as $key) {
            $row[$key] = $cells[$columns[$key]] ?? '';
        }
        [$clean, $error] = validateMarksRow($row);

        if ($clean === null) {
            $report['rejected']++;
            if (count($report['errors']) < 100) {
                $report['errors'][] = ['line' => $line, 'message' => $error];
            }
            continue;
        }

        $batch[] = $clean;
        $report['processed']++;
        if (count($batch) >= MARKS_BATCH_SIZE) {
            upsertMarks($conn, $batch, $actor);
            $batch = [];
        }
    }
    fclose($fh);
    upsertMarks($conn, $batch, $actor);

    return $report;
}
