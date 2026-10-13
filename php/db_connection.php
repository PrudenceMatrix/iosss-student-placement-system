<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/constants.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '" . DB_TIME_ZONE . "'");
} catch (mysqli_sql_exception $e) {
    // Log the real reason for the developer; show visitors a neutral message.
    error_log('IOSSS database connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('The system cannot reach the database right now. Please try again later.');
}
