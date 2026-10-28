<?php
/**
 * LAYOUT: the page frame (sidebar, top bar, footer) and small reusable pieces of HTML
 * such as badges, avatars, countdowns and the consultation progress chart.
 */

const APP_TIMEZONE = 'Asia/Jakarta';

const THEMES = [
    'apple-light' => ['label' => 'Apple Light', 'bg' => '#FBFBFD', 'accent' => '#0071E3'],
    'apple-dark' => ['label' => 'Apple Dark', 'bg' => '#0D0D0F', 'accent' => '#3B9EFF'],
    'earth' => ['label' => 'Earth Tone', 'bg' => '#F5F0E8', 'accent' => '#B85D3B'],
    'burgundy' => ['label' => 'Academic Burgundy', 'bg' => '#FAF6F5', 'accent' => '#6B1D2F'],
    'nordic' => ['label' => 'Nordic Slate', 'bg' => '#EEF2F6', 'accent' => '#0E7490'],
];

const CREATORS = [
    ['KAYNE KUSNADI', '2802405150'],
    ['NADYA ROSELANI BRAMANJAYA', '2802403605'],
    ['SHINJI PRUDENT ZHANG', '2802402104'],
    ['JOSHUA NATHANAEL TANADI', '2802396972'],
];

const AVATARS = ['a1', 'a2', 'a3', 'a4', 'a5', 'a6'];

const CATEGORY_LABELS = [
    'ACADEMIC_CONSULTATION' => 'Academic consultation',
    'GRADE_REVISION' => 'Grade or assignment revision',
    'PROJECT_GUIDANCE' => 'Project guidance',
    'TECHNICAL_SUPPORT' => 'Technical support',
    'ACADEMIC_SUPPORT' => 'Academic support',
];

const MEDIUM_LABELS = ['VIDEO' => 'Video call', 'VOICE' => 'Voice call', 'CHAT' => 'Live chat'];

/* ---------- links and dates ---------- */

function url(string $page, array $params = []): string
{
    return 'index.php?' . http_build_query(['page' => $page] + $params);
}

function local_date(?string $utc): DateTime
{
    return (new DateTime(($utc ?? 'now') . ' UTC'))->setTimezone(new DateTimeZone(APP_TIMEZONE));
}

function fmt_datetime(?string $utc): string
{
    return $utc ? local_date($utc)->format('j M Y, H:i') : '';
}

function fmt_date(?string $utc): string
{
    return $utc ? local_date($utc)->format('j M Y') : '';
}

function fmt_time(?string $utc): string
{
    return $utc ? local_date($utc)->format('H:i') : '';
}

/** "in 3 days", "2 hours ago" */
function relative_time(string $utc): string
{
    $diff = ts($utc) - time();
    $abs = abs($diff);
    foreach ([['day', 86400], ['hour', 3600], ['minute', 60]] as [$unit, $seconds]) {
        if ($abs >= $seconds) {
            $n = (int) round($abs / $seconds);
            $label = $n . ' ' . $unit . ($n === 1 ? '' : 's');
            if ($unit === 'day' && $n === 1) {
                return $diff > 0 ? 'tomorrow' : 'yesterday';
            }
            return $diff > 0 ? "in $label" : "$label ago";
        }
    }
    return $diff > 0 ? 'in under a minute' : 'just now';
}

/** Value for <input type="datetime-local"> in Jakarta time. */
function local_input_value(?string $utc): string
{
    return $utc ? local_date($utc)->format('Y-m-d\TH:i') : '';
}

function bytes_label(int $n): string
{
    return $n < 1024 ? "$n B" : ($n < 1048576 ? round($n / 1024, 1) . ' KB' : round($n / 1048576, 1) . ' MB');
}

/* ---------- small pieces of HTML ---------- */

/** Opens a form that posts to an action. Close it with </form>. */
function form_start(string $action, array $options = []): string
{
    $attrs = ' class="' . e($options['class'] ?? 'stack') . '"';
    if (!empty($options['files'])) {
        $attrs .= ' enctype="multipart/form-data"';
    }
    return '<form method="post" action="index.php"' . $attrs . '>'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<input type="hidden" name="action" value="' . e($action) . '">'
        . '<input type="hidden" name="back" value="' . e($_SERVER['QUERY_STRING'] ?? '') . '">';
}

/** A one-button form, e.g. "Delete" or "Upvote". Add 'confirm' to ask twice before it submits. */
function button_form(string $action, array $fields, string $label, string $class = 'btn btn-secondary btn-sm', string $confirm = '', string $title = ''): string
{
    $html = form_start($action, ['class' => 'inline-form']);
    foreach ($fields as $name => $value) {
        $html .= '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">';
    }
    $extra = $confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '';
    $extra .= $title !== '' ? ' title="' . e($title) . '" aria-label="' . e($title) . '"' : '';
    return $html . '<button type="submit" class="' . e($class) . '"' . $extra . '>' . $label . '</button></form>';
}

function badge(string $text, string $tone = ''): string
{
    return '<span class="badge' . ($tone ? " badge-$tone" : '') . '">' . e($text) . '</span>';
}

function status_badge(string $status): string
{
    $tones = ['UNDER_REVIEW' => 'accent', 'REVISION_REQUESTED' => 'warn', 'APPROVED_SCHEDULED' => 'success', 'DECLINED' => 'danger', 'COMPLETED' => 'highlight'];
    return badge(SOP_LABELS[$status] ?? $status, $tones[$status] ?? '');
}

function initials(string $name): string
{
    $parts = array_slice(preg_split('/\s+/', trim($name)), 0, 2);
    return strtoupper(implode('', array_map(fn ($p) => mb_substr($p, 0, 1), $parts)));
}

function avatar(string $name, ?string $avatar, int $size = 36): string
{
    if ($avatar && in_array($avatar, AVATARS, true)) {
        return '<img class="avatar" src="images/avatars/' . $avatar . '.webp" alt="" width="' . $size . '" height="' . $size . '">';
    }
    return '<span class="avatar avatar-initials" style="width:' . $size . 'px;height:' . $size . 'px;font-size:' . round($size * 0.38) . 'px" aria-hidden="true">' . e(initials($name)) . '</span>';
}

/** Course artwork, or simple shapes drawn with SVG when there is no image. */
function banner(array $course): string
{
    if (!empty($course['banner']) && preg_match('/^[a-z0-9-]+\.webp$/', $course['banner'])) {
        return '<img class="banner" src="images/banners/' . $course['banner'] . '" alt="">';
    }
    $seed = array_sum(array_map('ord', str_split($course['code'])));
    return '<svg class="banner" viewBox="0 0 400 170" preserveAspectRatio="xMidYMid slice" aria-hidden="true">'
        . '<rect width="400" height="170" fill="var(--surface-2)"/>'
        . '<rect x="' . (40 + $seed % 120) . '" y="30" width="120" height="120" rx="22" fill="var(--accent)" opacity=".14"/>'
        . '<circle cx="' . (260 - $seed % 60) . '" cy="95" r="58" fill="var(--accent)" opacity=".1"/></svg>';
}

/** A live countdown; app.js updates it every second. */
function countdown(string $utc, string $closedText = 'Closed'): string
{
    return '<span class="countdown mono" data-deadline="' . gmdate('Y-m-d\TH:i:s\Z', ts($utc)) . '" data-closed="' . e($closedText) . '">--:--:--</span>';
}

function empty_state(string $icon, string $title, string $text = ''): string
{
    return '<div class="card empty"><span class="empty-icon">' . icon($icon, 22) . '</span><p class="strong">' . e($title) . '</p>'
        . ($text ? '<p class="muted small">' . e($text) . '</p>' : '') . '</div>';
}

function stat_card(string $icon, string $label, string|int $value, string $sub = ''): string
{
    return '<div class="card stat"><p class="stat-label">' . icon($icon, 16) . e($label) . '</p><p class="stat-value tnum">' . e($value) . '</p>'
        . ($sub ? '<p class="muted small">' . e($sub) . '</p>' : '') . '</div>';
}

function page_header(string $title, string $description = '', string $actionsHtml = ''): string
{
    return '<header class="page-header"><div><h1>' . e($title) . '</h1>' . ($description ? '<p class="muted">' . e($description) . '</p>' : '')
        . '</div>' . ($actionsHtml ? '<div class="cluster">' . $actionsHtml . '</div>' : '') . '</header>';
}

function section_head(string $title, string $actionHtml = ''): string
{
    return '<div class="section-head"><h2>' . e($title) . '</h2>' . ($actionHtml ? '<div class="nowrap">' . $actionHtml . '</div>' : '') . '</div>';
}

/** A <dialog> pop-up opened by a button (see app.js). */
function dialog_button(string $id, string $label, string $class = 'btn btn-primary', string $icon = ''): string
{
    return '<button type="button" class="' . e($class) . '" data-open-dialog="' . e($id) . '">' . ($icon ? icon($icon, 16) : '') . e($label) . '</button>';
}

function dialog_start(string $id, string $title): string
{
    return '<dialog id="' . e($id) . '" class="modal" aria-label="' . e($title) . '"><div class="modal-body">'
        . '<div class="modal-head"><h2>' . e($title) . '</h2><button type="button" class="btn btn-ghost btn-sm" data-close-dialog>Close</button></div>';
}

function dialog_end(): string
{
    return '</div></dialog>';
}

/** The consultation progress chart. $steps comes from sop_flow() in rules.php. */
function sop_flow_html(array $steps): string
{
    $html = '<ol class="flow" aria-label="Request progress">';
    foreach ($steps as $i => $s) {
        $declined = $s['key'] === 'DECLINED';
        $finished = $s['state'] === 'done' || ($s['state'] === 'current' && in_array($s['key'], ['COMPLETED', 'DECLINED'], true));
        $classes = 'flow-step rise flow-' . $s['state'] . ($s['branch'] ? ' flow-branch' : '') . ($declined && $s['state'] === 'current' ? ' flow-declined' : '');
        $dot = $declined && $s['state'] === 'current' ? icon('x', 15) : ($finished ? icon('check', 15) : ($s['state'] === 'current' ? '<span class="flow-pulse"></span>' : icon('dashed', 14)));
        $html .= '<li class="' . $classes . '" style="--i:' . $i . '"' . ($s['state'] === 'current' ? ' aria-current="step"' : '') . '>'
            . '<span class="flow-dot">' . $dot . '</span><div class="flow-text"><p class="strong">'
            . ($s['branch'] ? icon('branch', 14) . ' ' : '') . e($s['label'])
            . ($s['branch'] ? ' <span class="muted small">' . ($s['state'] === 'skipped' ? '(not needed)' : '(only if needed)') . '</span>' : '')
            . '</p>' . ($s['at'] ? '<p class="mono small muted">' . e(fmt_datetime($s['at'])) . '</p>' : '')
            . ($s['note'] ? '<p class="flow-note' . ($declined ? ' flow-note-danger' : '') . '">' . ($declined ? 'Reason: ' : '') . e($s['note']) . '</p>' : '')
            . '</div></li>';
    }
    return $html . '</ol>';
}

/* ---------- the page frame ---------- */

function theme_for(?array $user): string
{
    $theme = $user['theme'] ?? ($_COOKIE['aetheris_theme'] ?? 'apple-light');
    return isset(THEMES[$theme]) ? $theme : 'apple-light';
}

function nav_items(string $role): array
{
    return match ($role) {
        'STUDENT' => [['dashboard', 'Today', 'dashboard'], ['courses', 'Courses', 'book'], ['assignments', 'Assignments', 'clipboard'],
            ['grades', 'Grades', 'cap'], ['consultations', 'Consultations', 'messages'], ['calendar', 'Calendar', 'calendar']],
        'LECTURER' => [['dashboard', 'Today', 'dashboard'], ['courses', 'My courses', 'book'], ['assignments', 'Grading', 'clipboard'],
            ['consultations', 'Consultation inbox', 'messages'], ['calendar', 'Calendar', 'calendar']],
        default => [['dashboard', 'Overview', 'dashboard'], ['admin', 'Users & classes', 'users'], ['consultations', 'Support queue', 'messages'],
            ['admin-log', 'Audit log', 'log']],
    };
}

function theme_menu(): string
{
    $html = '<div class="menu-wrap"><button type="button" class="btn btn-ghost icon-btn" data-menu="theme-menu" aria-haspopup="menu" aria-expanded="false" aria-label="Change colour theme">'
        . icon('palette', 19) . '</button><div class="menu" id="theme-menu" role="menu" hidden>';
    foreach (THEMES as $id => $t) {
        $html .= '<button type="button" role="menuitemradio" class="menu-item" data-theme-choice="' . $id . '">'
            . '<span class="swatch" style="background:' . $t['bg'] . '"><span style="background:' . $t['accent'] . '"></span></span>' . e($t['label']) . '</button>';
    }
    return $html . '</div></div>';
}

function page_start(string $title, ?array $user = null, string $activePage = ''): void
{
    $theme = theme_for($user);
    echo '<!doctype html><html lang="en" data-theme="' . $theme . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($title) . ' · Aetheris</title>'
        . '<meta name="csrf-token" content="' . e(csrf_token()) . '"><meta name="signed-in" content="' . ($user ? '1' : '0') . '">'
        . '<link rel="icon" href="images/icon.svg"><link rel="preconnect" href="https://fonts.googleapis.com">'
        . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap">'
        . '<link rel="stylesheet" href="style.css"><script src="app.js" defer></script></head><body>';

    $GLOBALS['public_layout'] = !$user;
    if (!$user) {
        echo '<div class="public"><header class="topbar glass"><div class="topbar-inner wide">'
            . '<a class="brand" href="index.php">' . brand_mark() . 'Aetheris</a><span class="grow"></span>' . theme_menu()
            . '<a class="btn btn-ghost btn-sm" href="' . url('login') . '">Sign in</a>'
            . '<a class="btn btn-primary btn-sm hide-mobile" href="' . url('register') . '">Create account</a></div></header><main class="public-main wide">';
        flash_html();
        return;
    }

    echo '<div class="shell"><aside class="sidebar" id="sidebar"><a class="brand" href="' . url('dashboard') . '">' . brand_mark() . '<span class="nav-label">Aetheris</span></a><nav aria-label="Main">';
    foreach (nav_items($user['role']) as [$page, $label, $icon]) {
        $current = $page === $activePage ? ' aria-current="page"' : '';
        echo '<a class="nav-item" href="' . url($page) . '"' . $current . ' title="' . e($label) . '">' . icon($icon, 19) . '<span class="nav-label">' . e($label) . '</span></a>';
    }
    echo '</nav><button type="button" class="nav-item collapse-btn" data-collapse-sidebar aria-label="Collapse sidebar">' . icon('collapse', 19) . '<span class="nav-label">Collapse</span></button></aside>'
        . '<div class="sidebar-scrim" data-close-sidebar></div><div class="content"><header class="topbar glass"><div class="topbar-inner">'
        . '<button type="button" class="btn btn-ghost icon-btn show-mobile" data-open-sidebar aria-label="Open menu">' . icon('menu', 20) . '</button>'
        . '<span class="grow"></span>' . theme_menu()
        . '<div class="menu-wrap"><button type="button" class="avatar-btn" data-menu="user-menu" aria-haspopup="menu" aria-expanded="false">'
        . avatar($user['name'], $user['avatar'], 32) . '<span class="sr-only">Account menu</span></button><div class="menu menu-wide" id="user-menu" role="menu" hidden>'
        . '<div class="menu-user"><p class="strong">' . e($user['name']) . '</p><p class="muted small">' . e($user['email']) . '</p>'
        . badge(ucfirst(strtolower($user['role'])), 'accent') . '</div>'
        . '<a class="menu-item" role="menuitem" href="' . url('profile') . '">' . icon('user', 17) . 'Profile and settings</a>'
        . form_start('logout', ['class' => '']) . '<button type="submit" class="menu-item danger" role="menuitem">' . icon('logout', 17) . 'Sign out</button></form>'
        . '</div></div></div></header><main class="main">';
    if ($user['must_change_password']) {
        echo '<a class="notice notice-warn" href="' . url('profile') . '#password">' . icon('key') . 'You are using a temporary password. Choose your own password to finish setting up your account.</a>';
    }
    flash_html();
}

function page_end(): void
{
    echo '<footer class="footer"><p>Aetheris LMS · Made by ' . e(implode(', ', array_map(fn ($c) => $c[0], array_slice(CREATORS, 0, 3)))) . ' and ' . e(CREATORS[3][0]) . '.</p>'
        . '<button type="button" class="link-btn" data-open-dialog="about">About &amp; Creators</button></footer>'
        . dialog_start('about', 'About Aetheris LMS')
        . '<p class="muted">A portal for courses, assignments, timed exams, lecturer consultations and grades, written in PHP with an SQLite database.</p>'
        . '<h3 class="small muted mt-6">Created by</h3><ul class="stack-sm mt-2">';
    foreach (CREATORS as $i => [$name, $id]) {
        echo '<li class="panel creator rise" style="--i:' . $i . '"><span class="strong">' . e($name) . '</span><span class="mono muted">' . e($id) . '</span></li>';
    }
    echo '</ul>' . dialog_end() . ($GLOBALS['public_layout'] ?? false ? '</main></div>' : '</main></div></div>') . '</body></html>';
}

function brand_mark(): string
{
    return '<span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19 12 5l8 14"/><path d="M7.5 13.5h9"/></svg></span>';
}

function flash_html(): void
{
    $flash = take_flash();
    if ($flash) {
        [$type, $message] = $flash;
        echo '<div class="notice ' . ($type === 'ok' ? 'notice-ok' : 'notice-error') . '" role="' . ($type === 'ok' ? 'status' : 'alert') . '">'
            . ($type === 'ok' ? '<svg class="check-draw" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>' : icon('warn'))
            . e($message) . '</div>';
    }
}
