<?php

/*
 * Class work assignment configuration for local demonstration.
 * These defaults are intended for the assignment setup, not production use.
 * Set IOSSS_DB_PASS in the local environment before connecting to the database.
 */
define('DB_HOST', getenv('IOSSS_DB_HOST') ?: 'localhost');
define('DB_USER', getenv('IOSSS_DB_USER') ?: 'root');
define('DB_PASS', getenv('IOSSS_DB_PASS') !== false ? getenv('IOSSS_DB_PASS') : '');
define('DB_NAME', getenv('IOSSS_DB_NAME') ?: 'iosss_db');
