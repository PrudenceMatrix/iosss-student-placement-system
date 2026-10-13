<?php

declare(strict_types=1);

/*
 * Loaded at the top of every PHP page.
 * Starts the session, sends security headers and provides the shared helpers:
 * escaping, URLs, CSRF tokens, flash messages, access control, rate limiting,
 * the audit log, settings and pagination.
 */

require_once __DIR__ . '/db_connection.php';

const SESSION_IDLE_SECONDS = 1800; // sign out after 30 minutes of inactivity
const MAX_FAILED_LOGINS    = 5;    // failed attempts allowed ...
const LOCKOUT_MINUTES      = 15;   // ... inside this window

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'");

/* ----------------------------------------------------------------------
 * Output and URL helpers
 * -------------------------------------------------------------------- */

/** Escape a value for safe output inside HTML. */
function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Web path of the project folder (works in /iosss, /htdocs/iosss or the web root). */
function base_path(): string
{
    // Every PHP page sits exactly one folder below the project root (php/, admin/, student/).
    $dir = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/x/y.php')));
    return ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
}

function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

/** 1 -> "1st", 2 -> "2nd", 3 -> "3rd" */
function ordinal(int $n): string
{
    return match ($n) {
        1 => '1st',
        2 => '2nd',
        3 => '3rd',
        default => $n . 'th',
    };
}

/** Read a text field from a request array: '' if missing or not plain text. */
function field(array $in, string $key): string
{
    $value = $in[$key] ?? '';
    return is_string($value) ? $value : '';
}

/** Length in characters (works without the mbstring extension). */
function text_len(string $s): int
{
    return (int)preg_match_all('/./su', $s);
}

/** Same arguments as mb_substr($s, $start, $length), but works without the mbstring extension. */
function text_cut(string $s, int $start, int $length): string
{
    return preg_match('/^.{' . $start . '}(.{0,' . $length . '})/su', $s, $m) ? $m[1] : '';
}

/* ----------------------------------------------------------------------
 * CSRF protection (for forms rendered by PHP)
 * -------------------------------------------------------------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = (string)($_POST['csrf'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), $sent)) {
        http_response_code(400);
        exit('This form has expired. Go back, reload the page and try again.');
    }
}

/* ----------------------------------------------------------------------
 * Flash messages (shown once on the next page)
 * -------------------------------------------------------------------- */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flash(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/* ----------------------------------------------------------------------
 * Authentication and access control
 * -------------------------------------------------------------------- */

function auth(): ?array
{
    return $_SESSION['auth'] ?? null;
}

function end_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

function login_page_for(?string $type): string
{
    return $type === 'staff' ? 'staff/login.html' : 'login.html';
}

function enforce_idle_timeout(): void
{
    if (time() - (int)($_SESSION['last_activity'] ?? 0) > SESSION_IDLE_SECONDS) {
        $page = login_page_for(auth()['type'] ?? null);
        end_session();
        redirect($page . '?error=expired');
    }
    $_SESSION['last_activity'] = time();
}

/** Start a signed-in session (call only after the password was verified). */
function sign_in(array $identity): void
{
    session_regenerate_id(true);
    $_SESSION['auth'] = $identity;
    $_SESSION['last_activity'] = time();
    unset($_SESSION['csrf']);
}

/**
 * Only signed-in staff with one of $roles may continue.
 * Returns the staff member's session identity.
 */
function require_staff(array $roles = ['super_admin', 'school_admin']): array
{
    if (!auth() || auth()['type'] !== 'staff') {
        redirect('staff/login.html?error=required');
    }
    enforce_idle_timeout();
    $a = auth();
    if (!in_array($a['role'], $roles, true)) {
        http_response_code(403);
        exit('You do not have access to this page.');
    }
    return $a;
}

/** Only a signed-in candidate may continue. */
function require_student(): array
{
    if (!auth() || auth()['type'] !== 'student') {
        redirect('login.html?error=required');
    }
    enforce_idle_timeout();
    return auth();
}

/* ----------------------------------------------------------------------
 * Rate limiting (failed sign-ins, sign-ups). Counted in login_attempts.
 * -------------------------------------------------------------------- */

function throttle_key(string $type, string $name): string
{
    return substr($type . ':' . strtolower($name), 0, 100);
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function attempts_in_window(mysqli $conn, string $key, int $minutes): int
{
    $st = $conn->prepare(
        'SELECT COUNT(*) FROM login_attempts
          WHERE identifier = ? AND attempted_at > (NOW() - INTERVAL ' . $minutes . ' MINUTE)'
    );
    $st->bind_param('s', $key);
    $st->execute();
    $st->bind_result($count);
    $st->fetch();
    $st->close();
    return (int)$count;
}

function record_attempt(mysqli $conn, string $key): void
{
    $ip = client_ip();
    $st = $conn->prepare('INSERT INTO login_attempts (identifier, ip_address) VALUES (?, ?)');
    $st->bind_param('ss', $key, $ip);
    $st->execute();
    $st->close();
    // Housekeeping: drop attempts older than a day.
    $conn->query('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
}

function clear_attempts(mysqli $conn, string $key): void
{
    $st = $conn->prepare('DELETE FROM login_attempts WHERE identifier = ?');
    $st->bind_param('s', $key);
    $st->execute();
    $st->close();
}

function login_is_locked(mysqli $conn, string $key): bool
{
    return attempts_in_window($conn, $key, LOCKOUT_MINUTES) >= MAX_FAILED_LOGINS;
}

/* ----------------------------------------------------------------------
 * Audit log (staff actions)
 * -------------------------------------------------------------------- */

function audit(mysqli $conn, string $action, string $subject = '', string $detail = ''): void
{
    $actor   = (string)(auth()['username'] ?? 'system');
    $subject = text_cut($subject, 0, 100);
    $detail  = text_cut($detail, 0, 255);
    $st = $conn->prepare('INSERT INTO audit_log (actor, action, subject, detail) VALUES (?, ?, ?, ?)');
    $st->bind_param('ssss', $actor, $action, $subject, $detail);
    $st->execute();
    $st->close();
}

/* ----------------------------------------------------------------------
 * Settings table
 * -------------------------------------------------------------------- */

function get_setting(mysqli $conn, string $key, string $default = ''): string
{
    $st = $conn->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $st->bind_param('s', $key);
    $st->execute();
    $st->bind_result($value);
    $found = $st->fetch();
    $st->close();
    return $found ? (string)$value : $default;
}

function set_setting(mysqli $conn, string $key, string $value): void
{
    $st = $conn->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $st->bind_param('ss', $key, $value);
    $st->execute();
    $st->close();
}

require_once __DIR__ . '/cycle.php';

/* ----------------------------------------------------------------------
 * Pagination
 * -------------------------------------------------------------------- */

function current_page(): int
{
    $p = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
    return ($p !== false && $p !== null && $p > 0) ? $p : 1;
}

/** Previous / numbered / next links. $query holds the other URL parameters to keep (search, filter). */
function render_pagination(int $total, int $page, int $perPage, array $query = []): void
{
    $pages = (int)max(1, ceil($total / $perPage));
    if ($pages <= 1) {
        return;
    }
    $link = static function (int $p, string $label, string $class = '') use ($query): string {
        $q = http_build_query(array_merge($query, ['page' => $p]));
        return '<a class="page-link ' . $class . '" href="?' . e($q) . '">' . $label . '</a>';
    };

    echo '<nav class="pagination" aria-label="Pages">';
    echo $page > 1 ? $link($page - 1, 'Previous') : '<span class="page-link is-disabled">Previous</span>';

    $from = max(1, $page - 2);
    $to   = min($pages, $page + 2);
    if ($from > 1) {
        echo $link(1, '1');
        if ($from > 2) {
            echo '<span class="page-gap">&hellip;</span>';
        }
    }
    for ($p = $from; $p <= $to; $p++) {
        echo $p === $page ? '<span class="page-link is-current" aria-current="page">' . $p . '</span>' : $link($p, (string)$p);
    }
    if ($to < $pages) {
        if ($to < $pages - 1) {
            echo '<span class="page-gap">&hellip;</span>';
        }
        echo $link($pages, (string)$pages);
    }

    echo $page < $pages ? $link($page + 1, 'Next') : '<span class="page-link is-disabled">Next</span>';
    echo '</nav>';
}
