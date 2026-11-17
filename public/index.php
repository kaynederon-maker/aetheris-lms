<?php
/**
 * Front door: every request to the site comes through this one file.
 *
 *   index.php?page=courses          shows pages/courses.php
 *   a form with action=join_course   runs action_join_course() from actions/courses.php
 *   index.php?action=download&id=5   sends a file (actions/files.php)
 *
 * Only names listed below can be loaded, so a visitor can never make PHP open another file.
 */

define('ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');

foreach (['database', 'security', 'auth', 'rules', 'uploads', 'markdown', 'icons', 'layout'] as $helper) {
    require ROOT . "/helpers/$helper.php";
}

/** Screens in /pages. Each page checks its own login and role at the top. */
const PAGES = [
    'home', 'login', 'register', 'dashboard', 'courses', 'course', 'material', 'thread', 'assignments', 'assignment',
    'exam', 'exam-new', 'grades', 'consultations', 'consultation', 'calendar', 'profile', 'admin', 'admin-log', 'not-found',
];

/** Form actions: action name => file in /actions. The function that runs is action_<name>(). */
const ACTIONS = [
    'login' => 'account', 'register' => 'account', 'logout' => 'account', 'update_profile' => 'account',
    'set_theme' => 'account', 'change_password' => 'account',
    'join_course' => 'courses', 'create_course' => 'courses', 'update_course' => 'courses', 'add_material' => 'courses',
    'move_material' => 'courses', 'delete_material' => 'courses', 'post_announcement' => 'courses',
    'create_assignment' => 'assignments', 'submit_assignment' => 'assignments', 'grade_submission' => 'assignments',
    'create_exam' => 'exams', 'start_exam' => 'exams', 'save_exam' => 'exams', 'submit_exam' => 'exams', 'grade_exam' => 'exams',
    'request_consultation' => 'consultations', 'update_consultation' => 'consultations',
    'new_thread' => 'discussion', 'reply' => 'discussion', 'vote' => 'discussion', 'verify_answer' => 'discussion', 'send_chat' => 'discussion',
    'create_lecturer' => 'admin', 'set_user_active' => 'admin',
];

/** Read-only requests that return a file or data instead of a page. */
const GET_ACTIONS = ['download' => 'files', 'chat_feed' => 'discussion'];

/** What a user with a temporary password may still do. */
const TEMP_PASSWORD_ALLOWED = ['change_password', 'logout', 'set_theme'];

send_security_headers();
start_session();

try {
    $user = current_user();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $action = (string) (input('action') ?? ''); // from a normal form, or from app.js (JSON)
        if (!isset(ACTIONS[$action])) {
            fail('Unknown action.', 404);
        }
        if ($user && $user['must_change_password'] && !in_array($action, TEMP_PASSWORD_ALLOWED, true)) {
            fail('Choose a new password on your profile before continuing.', 403);
        }
        require ROOT . '/actions/' . ACTIONS[$action] . '.php';
        ('action_' . $action)();
        exit;
    }

    $getAction = (string) ($_GET['action'] ?? '');
    if ($getAction !== '') {
        if (!isset(GET_ACTIONS[$getAction])) {
            fail('Unknown action.', 404);
        }
        require ROOT . '/actions/' . GET_ACTIONS[$getAction] . '.php';
        ('action_' . $getAction)();
        exit;
    }

    $page = (string) ($_GET['page'] ?? ($user ? 'dashboard' : 'home'));
    if (!in_array($page, PAGES, true)) {
        $page = 'not-found';
    }
    if ($user && $user['must_change_password'] && $page !== 'profile') {
        redirect(url('profile'));
    }
    require ROOT . "/pages/$page.php";
} catch (AppError $err) {
    if (wants_json()) {
        json_out(['ok' => false, 'error' => $err->getMessage()], $err->status);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        remember_input();
        flash('error', $err->getMessage());
        redirect(back_url());
    }
    http_response_code($err->status === 403 ? 403 : 404);
    require ROOT . '/pages/not-found.php';
} catch (Throwable $err) {
    error_log('[aetheris] ' . $err); // full details go to the server log only
    if (wants_json()) {
        json_out(['ok' => false, 'error' => 'Something went wrong. Please try again.'], 500);
    }
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Error</title><p style="font-family:sans-serif;padding:2rem">Something went wrong. Please go back and try again.</p>';
}
