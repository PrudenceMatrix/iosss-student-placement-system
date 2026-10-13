<?php

declare(strict_types=1);

/*
 * Application-wide settings. Safe to edit.
 * (Database login details live in config.php.)
 */

const APP_NAME     = 'IOSSS';
const APP_TIMEZONE = 'Africa/Nairobi';   // every date shown and stored uses this zone
const DB_TIME_ZONE = '+03:00';           // the same zone, as MySQL/MariaDB wants it
date_default_timezone_set(APP_TIMEZONE);

/** How many schools a candidate ranks. The selection runs one round per choice. */
const CHOICES_PER_CANDIDATE = 3;

/**
 * Accepted index number format.
 * To require the 11-digit KCPE style, use:  '/^[0-9]{11}$/'
 */
const INDEX_NO_PATTERN = '/^[A-Za-z0-9\/\-]{3,20}$/';

/** Kenyan mobile numbers: 07xx, 01xx or +2547xx / +2541xx (optional on the form). */
const PHONE_PATTERN = '/^(?:\+254|0)[17][0-9]{8}$/';

/** Rows per page on staff lists. */
const PAGE_SIZE = 25;

/** Marks upload limits. Also check upload_max_filesize / post_max_size in php.ini. */
const MARKS_UPLOAD_MAX_BYTES = 5242880;  // 5 MB
const MARKS_BATCH_SIZE       = 500;      // rows written per database statement
const MARKS_MAX_ROWS         = 200000;   // rows accepted per file
const MARK_MAX               = 100;      // marks are 0 to this value per subject

/** Subjects: database column => label shown to people. Aggregate = sum of all. */
const MARK_SUBJECTS = [
    'english'     => 'English',
    'kiswahili'   => 'Kiswahili',
    'mathematics' => 'Mathematics',
    'science'     => 'Science',
    'sst'         => 'Social Studies (SST)',
];
