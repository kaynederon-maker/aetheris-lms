<?php
/**
 * Login & permissions: sessions, the signed-in user, and who may see or change what.
 */

const SESSION_IDLE_SECONDS = 8 * 3600;

function start_session(): void
{
    session_name('aetheris');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,       // JavaScript cannot read the cookie
        'samesite' => 'Strict',   // other sites cannot send it along
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
    if (isset($_SESSION['seen']) && time() - $_SESSION['seen'] > SESSION_IDLE_SECONDS) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['seen'] = time();
}

/**
 * The signed-in user, checked again on every request: if the account was deactivated or the
 * password changed (session_version went up), the session stops working immediately.
 */
function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    $id = $_SESSION['user_id'] ?? null;
    if ($id) {
        $row = one('SELECT * FROM users WHERE id = ?', [$id]);
        if ($row && $row['active'] && (int) $row['session_version'] === ($_SESSION['session_version'] ?? -1)) {
            $user = $row;
        } else {
            unset($_SESSION['user_id'], $_SESSION['session_version']);
        }
    }
    return $user;
}

function log_in(array $user): void
{
    session_regenerate_id(true); // new session id after login (stops session fixation)
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['session_version'] = (int) $user['session_version'];
    unset($_SESSION['csrf']);
}

function log_out(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

/** Logs a user out on every device. */
function end_all_sessions(int $userId): void
{
    query('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$userId]);
}

/* ---------- guards for pages ---------- */

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('index.php?page=login');
    }
    return $user;
}

function require_role(string ...$roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        redirect('index.php?page=dashboard');
    }
    return $user;
}

/* ---------- guard for actions (form posts / fetch) ---------- */

function action_user(string ...$roles): array
{
    $user = current_user();
    if (!$user) {
        fail('Please sign in again.', 401);
    }
    if ($roles && !in_array($user['role'], $roles, true)) {
        fail('You do not have permission for this action.', 403);
    }
    return $user;
}

/* ---------- course permissions ---------- */

/** 'student' (enrolled), 'lecturer' (teaches it), 'admin', or null when the user has no link to the course. */
function course_role(array $user, int $courseId): ?string
{
    return match ($user['role']) {
        'ADMIN' => value('SELECT 1 FROM courses WHERE id = ?', [$courseId]) ? 'admin' : null,
        'LECTURER' => value('SELECT 1 FROM courses WHERE id = ? AND lecturer_id = ?', [$courseId, $user['id']]) ? 'lecturer' : null,
        default => value('SELECT 1 FROM enrollments WHERE course_id = ? AND user_id = ?', [$courseId, $user['id']]) ? 'student' : null,
    };
}

function can_manage(?string $courseRole): bool
{
    return $courseRole === 'lecturer' || $courseRole === 'admin';
}

/**
 * A consultation is visible to the student who asked, the lecturer it was sent to (while they
 * still teach that course), and admins for support-desk requests. Returns [row, 'student'|'recipient'].
 */
function consultation_access(int $id, array $user): array
{
    $c = one('SELECT c.*, co.lecturer_id AS course_lecturer FROM consultations c LEFT JOIN courses co ON co.id = c.course_id WHERE c.id = ?', [$id]);
    $side = null;
    if ($c && (int) $c['student_id'] === (int) $user['id']) {
        $side = 'student';
    } elseif ($c && $c['recipient_type'] === 'LECTURER' && (int) $c['recipient_id'] === (int) $user['id']
        && ($c['course_id'] === null || (int) $c['course_lecturer'] === (int) $user['id'])) {
        $side = 'recipient';
    } elseif ($c && $c['recipient_type'] === 'SUPPORT' && $user['role'] === 'ADMIN') {
        $side = 'recipient';
    }
    if (!$side) {
        fail('Request not found.', 404);
    }
    return [$c, $side];
}

/** Missing access is reported as "not found", so nobody can probe which course ids exist. */
function require_course(array $user, int $courseId, bool $manage = false): string
{
    $role = course_role($user, $courseId);
    if (!$role) {
        fail('Course not found.', 404);
    }
    if ($manage && !can_manage($role)) {
        fail('Only the course lecturer can do this.', 403);
    }
    return $role;
}
