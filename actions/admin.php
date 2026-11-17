<?php
/**
 * Admin actions: create lecturer accounts and switch accounts on or off.
 * (Creating classes and assigning lecturers lives in actions/courses.php.)
 */

/** A temporary password that always meets the password rules. */
function temporary_password(): string
{
    $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789', '#$%&*+=?'];
    $chars = array_map(fn ($set) => $set[random_int(0, strlen($set) - 1)], $sets);
    $all = implode('', $sets);
    while (count($chars) < 14) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }
    return implode('', shuffle_secure($chars));
}

function action_create_lecturer(): void
{
    action_user('ADMIN');
    $name = text_input('name', 'Full name', 2, 80);
    $email = strtolower(text_input('email', 'Email', 3, 254));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('Enter a valid email address.', 422);
    }
    if (value('SELECT 1 FROM users WHERE email = ?', [$email])) {
        fail('An account with this email already exists.', 409);
    }
    $password = temporary_password();
    $id = insert('users', [
        'email' => $email, 'name' => $name, 'role' => 'LECTURER', 'must_change_password' => 1, 'created_at' => now(),
        'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
    ]);
    audit('lecturer_created', $email);
    // Shown once on the admin page, then gone. It is never stored in plain text.
    $_SESSION['new_lecturer'] = ['email' => $email, 'password' => $password];
    done("Account created for $name.", url('admin'), ['id' => $id, 'email' => $email, 'temp_password' => $password]);
}

/** Deactivating ends every session of that account straight away. */
function action_set_user_active(): void
{
    $admin = action_user('ADMIN');
    $target = one('SELECT * FROM users WHERE id = ?', [(int) input('user_id')]) ?? fail('User not found.', 404);
    if ((int) $target['id'] === (int) $admin['id']) {
        fail('You cannot deactivate your own account.', 422);
    }
    $active = input('active') ? 1 : 0;
    update('users', (int) $target['id'], ['active' => $active]);
    if (!$active) {
        end_all_sessions((int) $target['id']);
    }
    audit($active ? 'user_activated' : 'user_deactivated', $target['email']);
    done($active ? "{$target['name']} can sign in again." : "{$target['name']} has been deactivated and signed out.");
}
