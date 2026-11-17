<?php
/*
 * Discussion actions
 * ==================
 * The course forum (threads, replies, votes, verified answers)
 * and the class group chat.
 */

function action_new_thread(): void
{
    $user = action_user();
    $courseId = (int) input('course_id');
    require_course($user, $courseId);
    limit_or_fail('thread:' . $user['id'], 10, 3600, 'You are posting very quickly. Try again later.');
    $id = insert('forum_threads', [
        'course_id' => $courseId, 'author_id' => $user['id'], 'created_at' => now(),
        'title' => text_input('title', 'Question', 5, 160),
        'body' => text_input('body', 'Details', 1, 10000, false),
    ]);
    done('Question posted.', url('thread', ['id' => $id]), ['id' => $id]);
}

function thread_for(array $user, int $threadId): array
{
    $thread = one('SELECT * FROM forum_threads WHERE id = ?', [$threadId]) ?? fail('Discussion not found.', 404);
    require_course($user, (int) $thread['course_id']);
    return $thread;
}

function action_reply(): void
{
    $user = action_user();
    $thread = thread_for($user, (int) input('thread_id'));
    limit_or_fail('reply:' . $user['id'], 30, 3600, 'You are posting very quickly. Try again later.');
    insert('forum_posts', ['thread_id' => $thread['id'], 'author_id' => $user['id'], 'body' => text_input('body', 'Answer', 1, 10000, false), 'created_at' => now()]);
    done('Answer posted.');
}

function post_for(array $user, bool $manage): array
{
    $post = one('SELECT p.*, t.course_id FROM forum_posts p JOIN forum_threads t ON t.id = p.thread_id WHERE p.id = ?', [(int) input('post_id')]) ?? fail('Post not found.', 404);
    require_course($user, (int) $post['course_id'], $manage);
    return $post;
}

function action_vote(): void
{
    $user = action_user();
    $post = post_for($user, false);
    if (value('SELECT 1 FROM forum_votes WHERE user_id = ? AND post_id = ?', [$user['id'], $post['id']])) {
        query('DELETE FROM forum_votes WHERE user_id = ? AND post_id = ?', [$user['id'], $post['id']]);
        done('Vote removed.');
    }
    insert('forum_votes', ['user_id' => $user['id'], 'post_id' => $post['id']]);
    done('Upvoted.');
}

/** Only the course lecturer (or an admin) can mark an answer as verified. */
function action_verify_answer(): void
{
    $post = post_for(action_user('LECTURER', 'ADMIN'), true);
    update('forum_posts', (int) $post['id'], ['verified' => $post['verified'] ? 0 : 1]);
    done($post['verified'] ? 'Verification removed.' : 'Marked as a verified answer.');
}

/* ---------- group chat (used by app.js) ---------- */

function chat_shape(array $m): array
{
    return ['id' => (int) $m['id'], 'body' => $m['body'], 'time' => fmt_time($m['created_at']), 'author_id' => (int) $m['author_id'],
        'author' => $m['name'], 'lecturer' => $m['role'] === 'LECTURER'];
}

function action_send_chat(): void
{
    $user = action_user();
    $courseId = (int) input('course_id');
    require_course($user, $courseId);
    limit_or_fail('chat:' . $user['id'], 30, 60, 'Too many messages in one minute. Wait a moment and try again.');
    $id = insert('chat_messages', ['course_id' => $courseId, 'author_id' => $user['id'], 'body' => text_input('body', 'Message', 1, 2000), 'created_at' => now()]);
    $message = one('SELECT m.*, u.name, u.role FROM chat_messages m JOIN users u ON u.id = m.author_id WHERE m.id = ?', [$id]);
    json_out(['ok' => true, 'message' => chat_shape($message)]);
}

/** New messages after a given id. app.js asks every few seconds. */
function action_chat_feed(): void
{
    $user = action_user();
    $courseId = (int) ($_GET['course_id'] ?? 0);
    require_course($user, $courseId);
    $rows = all('SELECT m.*, u.name, u.role FROM chat_messages m JOIN users u ON u.id = m.author_id WHERE m.course_id = ? AND m.id > ? ORDER BY m.id LIMIT 100',
        [$courseId, (int) ($_GET['after'] ?? 0)]);
    json_out(['ok' => true, 'messages' => array_map('chat_shape', $rows)]);
}
