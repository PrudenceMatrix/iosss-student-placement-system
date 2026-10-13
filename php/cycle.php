<?php

declare(strict_types=1);

/*
 * The selection cycle: when applications open and close, when results are
 * released, and whether selection has run / results are published.
 * Stored in the settings table so the system administrator can change them.
 */

function now_dt(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
}

function parse_stamp(string $value): ?DateTimeImmutable
{
    if ($value === '') {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone(APP_TIMEZONE));
    return $d ?: null;
}

/** "3 Nov 2026, 11:59 PM" (or just the date). */
function fmt_dt(?DateTimeInterface $d, bool $withTime = true): string
{
    return $d ? $d->format($withTime ? 'j M Y, g:i A' : 'j M Y') : 'Not set';
}

/**
 * Everything about the current cycle in one array.
 * status: unset | not_open | open | closed
 */
function cycle_load(mysqli $conn): array
{
    $raw = [];
    $res = $conn->query('SELECT setting_key, setting_value FROM settings');
    foreach ($res->fetch_all(MYSQLI_ASSOC) as $row) {
        $raw[$row['setting_key']] = $row['setting_value'];
    }
    $g = static fn(string $k): string => $raw[$k] ?? '';

    $open  = parse_stamp($g('applications_open_at'));
    $close = parse_stamp($g('applications_close_at'));
    $now   = now_dt();

    if (!$open || !$close) {
        $status = 'unset';
    } elseif ($now < $open) {
        $status = 'not_open';
    } elseif ($now > $close) {
        $status = 'closed';
    } else {
        $status = 'open';
    }

    return [
        'name'         => $g('cycle_name') !== '' ? $g('cycle_name') : 'Form One Selection',
        'open_at'      => $open,
        'close_at'     => $close,
        'release_at'   => parse_stamp($g('results_release_at')),
        'run_at'       => parse_stamp($g('selection_run_at')),
        'published'    => $g('results_published') === '1',
        'published_at' => parse_stamp($g('results_published_at')),
        'status'       => $status,
        'now'          => $now,
    ];
}

/** Whole days from now until $to (never negative). */
function days_until(DateTimeInterface $now, ?DateTimeInterface $to): int
{
    if (!$to) {
        return 0;
    }
    return max(0, (int)ceil(($to->getTimestamp() - $now->getTimestamp()) / 86400));
}

/** One-line description of the application window for banners. */
function cycle_headline(array $c): string
{
    switch ($c['status']) {
        case 'open':
            $d = days_until($c['now'], $c['close_at']);
            return 'Applications are open until ' . fmt_dt($c['close_at']) . ' (' . ($d <= 1 ? 'closes today or tomorrow' : $d . ' days left') . ')';
        case 'not_open':
            return 'Applications open on ' . fmt_dt($c['open_at']);
        case 'closed':
            return 'Applications closed on ' . fmt_dt($c['close_at']);
        default:
            return 'Application dates will be announced soon';
    }
}

/**
 * Save the cycle dates. $v holds DateTimeImmutable|null for open/close/release
 * and a string name. Callers validate first (see validateCycle in admin/cycle.php).
 */
function cycle_save(mysqli $conn, string $name, DateTimeImmutable $open, DateTimeImmutable $close, ?DateTimeImmutable $release): void
{
    set_setting($conn, 'cycle_name', $name);
    set_setting($conn, 'applications_open_at', $open->format('Y-m-d H:i:s'));
    set_setting($conn, 'applications_close_at', $close->format('Y-m-d H:i:s'));
    set_setting($conn, 'results_release_at', $release ? $release->format('Y-m-d H:i:s') : '');
}
