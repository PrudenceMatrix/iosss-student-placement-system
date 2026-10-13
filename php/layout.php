<?php

declare(strict_types=1);

/*
 * Page chrome for every PHP-rendered page. Requires bootstrap.php first.
 *
 * Three shells, chosen automatically from who is signed in:
 *   public   visitors (error pages)       -> top bar like the home page
 *   student  signed-in candidates          -> green portal bar
 *   staff    signed-in administrators      -> dark sidebar
 */

/** Small outline icons (inline SVG, inherit the text colour). */
function icon(string $name): string
{
    static $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
        'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'upload'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/>',
        'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'play'      => '<polygon points="6 3 20 12 6 21 6 3"/>',
        'school'    => '<path d="M2 9l10-5 10 5-10 5z"/><path d="M6 11v5c0 1.5 2.7 3 6 3s6-1.5 6-3v-5"/>',
        'list'      => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="3.5" cy="6" r="1"/><circle cx="3.5" cy="12" r="1"/><circle cx="3.5" cy="18" r="1"/>',
        'activity'  => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'key'       => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3L21 2M16 7l3 3M14 9l2 2"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'download'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'printer'   => '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'file'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M8 13h8M8 17h5"/>',
        'check'     => '<polyline points="20 6 9 17 4 12"/>',
        'user'      => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'refresh'   => '<polyline points="23 4 23 10 17 10"/><path d="M20.5 15a9 9 0 1 1-2.1-9.4L23 10"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? '') . '</svg>';
}

/** A coloured pill. $type: success | warning | danger | info | neutral | primary */
function badge(string $text, string $type = 'neutral'): string
{
    return '<span class="badge badge-' . e($type) . '">' . e($text) . '</span>';
}

/** Pill for "this was the candidate's Nth choice": 1st green, 2nd blue, 3rd neutral. */
function choice_badge(int $n): string
{
    $type = [1 => 'primary', 2 => 'info'][$n] ?? 'neutral';
    return badge(ordinal($n) . ' choice', $type);
}

/** Navigation for the signed-in person: [path, label, key, icon]. */
function nav_items(): array
{
    $a = auth();

    if (!$a) {
        return [
            ['index.html', 'Home', 'home', ''],
            ['iosss_application.html', 'Create account', 'signup', ''],
            ['login.html', 'Student login', 'login', ''],
        ];
    }

    if ($a['type'] === 'student') {
        return [
            ['student/dashboard.php', 'Dashboard', 'dashboard', 'dashboard'],
            ['student/application.php', 'My application', 'application', 'file'],
        ];
    }

    if ($a['role'] === 'super_admin') {
        return [
            ['admin/dashboard.php', 'Dashboard', 'dashboard', 'dashboard'],
            ['admin/cycle.php', 'Cycle and dates', 'cycle', 'calendar'],
            ['admin/marks.php', 'Marks', 'marks', 'upload'],
            ['admin/applicants.php', 'Candidates', 'applicants', 'users'],
            ['admin/selection.php', 'Selection', 'selection', 'play'],
            ['admin/schools.php', 'Schools', 'schools', 'school'],
            ['admin/activity.php', 'Activity log', 'activity', 'activity'],
            ['admin/change_password.php', 'Password', 'password', 'key'],
        ];
    }

    return [
        ['admin/school.php', 'My school', 'school', 'school'],
        ['admin/change_password.php', 'Password', 'password', 'key'],
    ];
}

function layout_state(?string $set = null): string
{
    static $shell = 'public';
    if ($set !== null) {
        $shell = $set;
    }
    return $shell;
}

function render_header(string $title, string $active = ''): void
{
    $a = auth();
    $shell = $a === null ? 'public' : ($a['type'] === 'staff' ? 'staff' : 'student');
    layout_state($shell);
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= e($title) ?> | <?= e(APP_NAME) ?></title>
        <link rel="stylesheet" href="<?= e(url('assets/css/theme.css')) ?>">
        <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
    </head>

    <body class="shell-<?= e($shell) ?>">
        <?php
        if ($shell === 'staff') {
            $roleText = $a['role'] === 'super_admin' ? 'System administrator' : ($a['school_name'] ?? 'School administrator');
        ?>
            <div class="shell">
                <aside class="sidebar">
                    <a class="brand" href="<?= e(url('admin/' . ($a['role'] === 'super_admin' ? 'dashboard.php' : 'school.php'))) ?>">
                        <span class="brand-logo">I</span>
                        <span class="brand-text"><?= e(APP_NAME) ?><small>Staff portal</small></span>
                    </a>
                    <nav class="sidebar-nav" aria-label="Main">
                        <?php foreach (nav_items() as [$path, $label, $key, $ico]): ?>
                            <a href="<?= e(url($path)) ?>" <?= $key === $active ? ' class="is-active" aria-current="page"' : '' ?>><?= icon($ico) ?><span><?= e($label) ?></span></a>
                        <?php endforeach; ?>
                    </nav>
                    <div class="sidebar-foot">
                        <div class="who"><strong><?= e($a['name']) ?></strong><span><?= e($roleText) ?></span></div>
                        <a class="btn btn-ghost btn-sm" href="<?= e(url('php/logout.php')) ?>"><?= icon('logout') ?>Sign out</a>
                    </div>
                </aside>
                <div class="shell-main">
                    <main class="content">
                    <?php
                } elseif ($shell === 'student') {
                    ?>
                        <header class="portal-bar">
                            <div class="container portal-inner">
                                <a class="brand" href="<?= e(url('student/dashboard.php')) ?>">
                                    <span class="brand-logo">I</span>
                                    <span class="brand-text"><?= e(APP_NAME) ?><small>Student portal</small></span>
                                </a>
                                <nav class="portal-nav" aria-label="Main">
                                    <?php foreach (nav_items() as [$path, $label, $key, $ico]): ?>
                                        <a href="<?= e(url($path)) ?>" <?= $key === $active ? ' class="is-active" aria-current="page"' : '' ?>><?= icon($ico) ?><span><?= e($label) ?></span></a>
                                    <?php endforeach; ?>
                                </nav>
                                <div class="portal-user">
                                    <span class="portal-name"><?= e($a['name']) ?></span>
                                    <a class="btn btn-ghost btn-sm" href="<?= e(url('php/logout.php')) ?>"><?= icon('logout') ?>Sign out</a>
                                </div>
                            </div>
                        </header>
                        <main class="container page">
                        <?php
                    } else {
                        ?>
                            <header class="site-header">
                                <div class="container site-inner">
                                    <a class="brand" href="<?= e(url('index.html')) ?>">
                                        <span class="brand-logo">I</span>
                                        <span class="brand-text"><?= e(APP_NAME) ?><small>Secondary Students Selection</small></span>
                                    </a>
                                    <nav class="site-nav" aria-label="Main">
                                        <?php foreach (nav_items() as [$path, $label, $key, $ico]): ?>
                                            <a href="<?= e(url($path)) ?>" <?= $key === $active ? ' class="is-active"' : '' ?>><?= e($label) ?></a>
                                        <?php endforeach; ?>
                                    </nav>
                                </div>
                            </header>
                            <main class="container page">
                            <?php
                        }

                        $flashes = take_flash();
                        if ($flashes) {
                            echo '<div class="toasts" aria-live="polite">';
                            foreach ($flashes as $f) {
                                $type = in_array($f['type'], ['success', 'error', 'info', 'warning', 'secret'], true) ? $f['type'] : 'info';
                                // 'secret' toasts (temporary passwords) stay on screen until dismissed
                                echo '<div class="toast toast-' . $type . '"' . ($type === 'secret' ? ' data-sticky="1"' : '') . ' role="' . ($type === 'error' ? 'alert' : 'status') . '">'
                                    . '<span>' . e($f['message']) . '</span>'
                                    . '<button type="button" class="toast-close" aria-label="Dismiss">&times;</button></div>';
                            }
                            echo '</div>';
                        }
                    }

                    /** Title block at the top of a page: heading, optional sentence, optional buttons (trusted HTML). */
                    function page_head(string $title, string $subtitle = '', string $actionsHtml = ''): void
                    {
                        echo '<div class="page-head"><div><h1>' . e($title) . '</h1>';
                        if ($subtitle !== '') {
                            echo '<p class="muted">' . e($subtitle) . '</p>';
                        }
                        echo '</div>';
                        if ($actionsHtml !== '') {
                            echo '<div class="page-actions no-print">' . $actionsHtml . '</div>';
                        }
                        echo '</div>';
                    }

                    function render_footer(): void
                    {
                        $shell = layout_state();
                        if ($shell === 'staff') {
                            echo "</main>\n</div>\n</div>\n";
                        } else {
                            echo "</main>\n";
                            ?>
                                <footer class="site-footer">
                                    <div class="container"><?= e(APP_NAME) ?> &middot; Integrated Secondary Students Selection System</div>
                                </footer>
                            <?php
                        }
                            ?>
                            <script src="<?= e(url('assets/js/app.js')) ?>"></script>
    </body>

    </html>
<?php
                    }
