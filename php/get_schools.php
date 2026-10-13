<?php

declare(strict_types=1);

/* Public JSON list of schools for the home page. Placement counts show only after results are published. */

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/cycle.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$published = cycle_load($conn)['published'];

$rows = $conn->query(
    'SELECT sch.school_id, sch.school_name, sch.capacity,
            (SELECT COUNT(*) FROM selection s WHERE s.school_id = sch.school_id) AS selected
       FROM schools sch ORDER BY sch.school_name'
)->fetch_all(MYSQLI_ASSOC);

echo json_encode(array_map(static fn($r) => [
    'school_id'   => (int)$r['school_id'],
    'school_name' => $r['school_name'],
    'capacity'    => (int)$r['capacity'],
    'selected'    => $published ? (int)$r['selected'] : null,
], $rows));
