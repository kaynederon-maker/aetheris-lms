<?php
/**
 * ACCOUNT ACTIONS: sign in, sign out, register, profile, theme and password.
 */

// A real bcrypt hash of a throwaway string. When an email does not exist we still check the
// password against it, so a wrong email and a wrong password take the same time to answer.
const DUMMY_HASH = '$2y$12$mHk3PYMV47iPO57hhDy5H.5ivTsPQcHgwwKXttoXhz.tx.0eKUvxW';
const BCRYPT_COST = ['cost' => 12];

function action_login(): void
{
    $email = strtolower(text_input('email', 'Email', 3, 254));
    $password = (string) (input('password') ?? '');
    $ip = client_ip();
    $pairKey = "login:$email:$ip";
    // 5 tries per account from one place, 30 per account overall, 100 per network (a campus shares one IP).
    $wait = 'Too many sign-in attempts. Wait 15 minutes and try again.';
    limit_or_fail($pairKey, 5, 900, $wait);
    limit_or_fail("login-account:$email", 30, 900, $wait);
    limit_or_fail("login-ip:$ip", 100, 900, $wait);

    $user = one('SELECT * FROM users WHERE email = ?', [$email]);
    $valid = password_verify($password, $user['password_hash'] ?? DUMMY_HASH);
    if (!$user || !$valid) {
        audit('login_failed', $email);
        fail('Email or password is incorrect.', 401);
    }
    if (!$user['active']) {
        audit('login_blocked_inactive', $email, (int) $user['id']);
        fail('This account has been deactivated. Contact the academic administrator.', 403);
    }
    clear_rate_limit($pairKey);
    log_in($user);
    audit('login', null, (int) $user['id']);
    done('Welcome back, ' . explode(' ', $user['name'])[0] . '.', url('dashboard'), ['role' => $user['role']]);
}

/** Public sign-up always creates a student. Lecturer accounts come from an admin. */
function action_register(): void
{
    limit_or_fail('register:' . client_ip(), 20, 3600, 'Too many sign-ups from this network. Try again later.');
    $name = text_input('name', 'Full name', 2, 80);
    $email = strtolower(text_input('email', 'Email', 3, 254));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('Enter a valid email address.', 422);
    }
    $studentNumber = text_input('student_number', 'Student number', 10, 10);
    if (!ctype_digit($studentNumber)) {
        fail('The student number is 10 digits.', 422);
    }
    $password = (string) (input('password') ?? '');
    if ($problem = password_problem($password)) {
        fail($problem, 422);
    }
    if (value('SELECT 1 FROM users WHERE email = ?', [$email])) {
        fail('An account with this email already exists. Try signing in.', 409);
    }
    if (value('SELECT 1 FROM users WHERE student_number = ?', [$studentNumber])) {
        fail('This student number is already registered.', 409);
    }
    $id = insert('users', [
        'email' => $email, 'name' => $name, 'student_number' => $studentNumber, 'role' => 'STUDENT',
        'password_hash' => password_hash($password, PASSWORD_BCRYPT, BCRYPT_COST), 'created_at' => now(),
    ]);
    log_in(one('SELECT * FROM users WHERE id = ?', [$id]));
    audit('register', $email, $id);
    done('Your account is ready. Join your first class with the code from your lecturer.', url('courses'), ['role' => 'STUDENT']);
}

function action_logout(): void
{
    if ($user = current_user()) {
        audit('logout', null, (int) $user['id']);
    }
    log_out();
    redirect('index.php');
}

function action_update_profile(): void
{
    $user = action_user();
    $name = text_input('name', 'Display name', 2, 80);
    $avatar = choice_input('avatar', 'avatar', [...AVATARS, 'none']);
    update('users', (int) $user['id'], ['name' => $name, 'avatar' => $avatar === 'none' ? null : $avatar]);
    done('Profile saved.');
}

/** The theme is saved to the profile when signed in, and to a cookie for the sign-in pages. */
function action_set_theme(): void
{
    $theme = choice_input('theme', 'theme', array_keys(THEMES));
    setcookie('aetheris_theme', $theme, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Strict', 'secure' => is_https()]);
    if ($user = current_user()) {
        update('users', (int) $user['id'], ['theme' => $theme]);
    }
    done('Theme saved.');
}

/** Changing the password signs the account out everywhere else. */
function action_change_password(): void
{
    $user = action_user();
    limit_or_fail('password:' . $user['id'], 5, 900, 'Too many attempts. Wait a few minutes and try again.');
    $current = (string) (input('current_password') ?? '');
    $new = (string) (input('new_password') ?? '');
    if (!password_verify($current, $user['password_hash'])) {
        audit('password_change_failed');
        fail('Your current password is incorrect.', 400);
    }
    if ($problem = password_problem($new)) {
        fail($problem, 422);
    }
    if ($new === $current) {
        fail('Choose a password you have not used here before.', 422);
    }
    update('users', (int) $user['id'], ['password_hash' => password_hash($new, PASSWORD_BCRYPT, BCRYPT_COST), 'must_change_password' => 0]);
    end_all_sessions((int) $user['id']);
    log_in(one('SELECT * FROM users WHERE id = ?', [$user['id']]));
    audit('password_changed');
    done('Password changed. You have been signed out on your other devices.', url('profile'));
}
