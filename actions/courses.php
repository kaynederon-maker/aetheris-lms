<?php
/**
 * Course actions: joining a class, creating classes (admin), and lecturer tools for
 * materials and announcements.
 */

function action_join_course(): void
{
    $user = action_user('STUDENT');
    limit_or_fail('join:' . $user['id'], 10, 600, 'Too many join attempts. Wait a few minutes.');
    $code = normalize_join_code((string) (input('code') ?? ''));
    if (strlen($code) !== 8) {
        fail('Join codes have 8 characters, like DSTR-7Q4K.', 422);
    }
    $course = one('SELECT * FROM courses WHERE join_code = ?', [$code]);
    if (!$course) {
        audit('join_bad_code');
        fail('No class matches that code. Check it with your lecturer.', 404);
    }
    $already = value('SELECT 1 FROM enrollments WHERE user_id = ? AND course_id = ?', [$user['id'], $course['id']]);
    if (!$already) {
        insert('enrollments', ['user_id' => $user['id'], 'course_id' => $course['id'], 'created_at' => now()]);
        audit('joined_course', $course['code']);
    }
    done($already ? "You are already in {$course['code']}." : "You joined {$course['code']}.", url('course', ['id' => $course['id']]), ['id' => (int) $course['id']]);
}

function check_lecturer(?int $id): void
{
    if ($id && !value("SELECT 1 FROM users WHERE id = ? AND role = 'LECTURER' AND active = 1", [$id])) {
        fail('Choose an active lecturer account.', 422);
    }
}

function new_join_code(): string
{
    do {
        $code = generate_join_code();
    } while (value('SELECT 1 FROM courses WHERE join_code = ?', [$code]));
    return $code;
}

function action_create_course(): void
{
    action_user('ADMIN');
    $code = strtoupper(text_input('code', 'Course code', 8, 8));
    if (!preg_match('/^[A-Z]{4}\d{4}$/', $code)) {
        fail('Course codes look like COMP6100.', 422);
    }
    $title = text_input('title', 'Title', 3, 120);
    $description = text_input('description', 'Description', 0, 2000);
    $lecturerId = (int) (input('lecturer_id') ?: 0) ?: null;
    check_lecturer($lecturerId);
    if (value('SELECT 1 FROM courses WHERE code = ?', [$code])) {
        fail("$code already exists.", 409);
    }
    $id = insert('courses', ['code' => $code, 'title' => $title, 'description' => $description, 'lecturer_id' => $lecturerId, 'join_code' => new_join_code(), 'created_at' => now()]);
    audit('course_created', $code);
    done("$code created.", null, ['id' => $id]);
}

/** Admin: assign a lecturer, or generate a new join code. */
function action_update_course(): void
{
    action_user('ADMIN');
    $course = one('SELECT * FROM courses WHERE id = ?', [(int) input('course_id')]) ?? fail('Course not found.', 404);
    if (input('regenerate_code')) {
        update('courses', (int) $course['id'], ['join_code' => new_join_code()]);
        audit('join_code_regenerated', $course['code']);
        done("New join code for {$course['code']}.");
    }
    $lecturerId = (int) input('lecturer_id');
    check_lecturer($lecturerId);
    if (!$lecturerId) {
        fail('Choose a lecturer.', 422);
    }
    update('courses', (int) $course['id'], ['lecturer_id' => $lecturerId]);
    // Open consultation requests move to the new lecturer along with the course.
    query("UPDATE consultations SET recipient_id = ? WHERE course_id = ? AND recipient_type = 'LECTURER' AND status NOT IN ('COMPLETED', 'DECLINED')", [$lecturerId, $course['id']]);
    audit('lecturer_assigned', $course['code']);
    done("Lecturer updated for {$course['code']}.");
}

function action_add_material(): void
{
    $user = action_user('LECTURER', 'ADMIN');
    $courseId = (int) input('course_id');
    require_course($user, $courseId, true);
    $title = text_input('title', 'Title', 2, 160);
    $body = text_input('body', 'Notes', 0, 50000, false);
    $fileId = save_upload('file', ['pdf', 'pptx', 'docx', 'zip', 'code'], (int) $user['id']);
    if (!$fileId && $body === '') {
        fail('Add some notes or attach a file.', 422);
    }
    $position = (int) value('SELECT COALESCE(MAX(position), 0) + 1 FROM materials WHERE course_id = ?', [$courseId]);
    insert('materials', ['course_id' => $courseId, 'title' => $title, 'body' => $body ?: null, 'file_id' => $fileId, 'position' => $position, 'created_at' => now()]);
    audit('material_added', $title);
    done('Material published.');
}

function material_for_manager(array $user): array
{
    $material = one('SELECT * FROM materials WHERE id = ?', [(int) input('material_id')]) ?? fail('Material not found.', 404);
    require_course($user, (int) $material['course_id'], true);
    return $material;
}

function action_move_material(): void
{
    $material = material_for_manager(action_user('LECTURER', 'ADMIN'));
    $up = input('direction') === 'up';
    $neighbour = one(
        'SELECT * FROM materials WHERE course_id = ? AND position ' . ($up ? '<' : '>') . ' ? ORDER BY position ' . ($up ? 'DESC' : 'ASC') . ' LIMIT 1',
        [$material['course_id'], $material['position']]
    );
    if ($neighbour) {
        update('materials', (int) $material['id'], ['position' => $neighbour['position']]);
        update('materials', (int) $neighbour['id'], ['position' => $material['position']]);
    }
    done('Order updated.');
}

function action_delete_material(): void
{
    $material = material_for_manager(action_user('LECTURER', 'ADMIN'));
    query('DELETE FROM materials WHERE id = ?', [$material['id']]);
    if ($material['file_id']) {
        delete_file((int) $material['file_id']);
    }
    audit('material_deleted', $material['title']);
    done('Material deleted.');
}

function action_post_announcement(): void
{
    $user = action_user('LECTURER', 'ADMIN');
    $courseId = (int) input('course_id');
    require_course($user, $courseId, true);
    insert('announcements', [
        'course_id' => $courseId,
        'title' => text_input('title', 'Title', 3, 160),
        'body' => text_input('body', 'Message', 1, 5000, false),
        'created_at' => now(),
    ]);
    done('Announcement posted.');
}
