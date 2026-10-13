<?php

declare(strict_types=1);

/*
 * Download the selected candidates as CSV (opens in Excel).
 * A school administrator can only ever download their own school.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/applications.php';
require_once __DIR__ . '/iosss_app_process.php';

$me = require_staff();

if ($me['role'] === 'school_admin') {
    $schoolId = (int)$me['school_id'];          // the URL cannot widen this
} elseif (($_GET['school_id'] ?? '') === 'all') {
    $schoolId = null;
} else {
    $schoolId = filter_input(INPUT_GET, 'school_id', FILTER_VALIDATE_INT);
    if (!$schoolId) {
        http_response_code(400);
        exit('Choose a school to export.');
    }
}

$label = 'all-schools';
if ($schoolId !== null) {
    $names = fetchSchoolNames($conn);
    if (!isset($names[$schoolId])) {
        http_response_code(404);
        exit('School not found.');
    }
    $label = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $names[$schoolId]), '-'));
}

/** Stop spreadsheet programs from running cells that start with = + - @ */
function csv_safe(string $value): string
{
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="selected-students-' . $label . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 byte order mark so Excel reads names correctly
fputcsv($out, ['School', 'Index number', 'Name', 'Aggregate', 'Choice number'], ',', '"', '');
eachSelectedStudent($conn, $schoolId, static function (array $r) use ($out): void {
    fputcsv($out, [
        csv_safe($r['school_name']),
        csv_safe((string)$r['index_no']),
        csv_safe($r['full_name']),
        (int)$r['aggregate'],
        (int)$r['choice_number'],
    ], ',', '"', '');
});
fclose($out);
