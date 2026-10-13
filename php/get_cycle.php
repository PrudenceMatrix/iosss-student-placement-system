<?php

declare(strict_types=1);

/*
 * Public JSON for the home page: the application window, key dates and a few
 * totals. Contains no personal data.
 */

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/cycle.php';

// cycle.php uses set_setting/get_setting only when saving, so loading it alone is safe here.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$c   = cycle_load($conn);
$iso = static fn(?DateTimeInterface $d): ?string => $d ? $d->format(DATE_ATOM) : null;
$one = static fn(string $sql): int => (int)$conn->query($sql)->fetch_row()[0];

echo json_encode([
    'name'         => $c['name'],
    'status'       => $c['status'],
    'server_time'  => $iso($c['now']),
    'open_at'      => $iso($c['open_at']),
    'close_at'     => $iso($c['close_at']),
    'release_at'   => $iso($c['release_at']),
    'selection_run' => $c['run_at'] !== null,
    'published'    => $c['published'],
    'applications' => $one('SELECT COUNT(*) FROM applications'),
    'schools'      => $one('SELECT COUNT(*) FROM schools'),
    'seats'        => $one('SELECT COALESCE(SUM(capacity), 0) FROM schools'),
]);
