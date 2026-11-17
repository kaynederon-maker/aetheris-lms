<?php
/*
 * Consultation actions
 * ====================
 * Students ask for a session; lecturers and support staff move the
 * request through its steps. Which step may follow which is decided in helpers/rules.php.
 */

function action_request_consultation(): void
{
    $user = action_user('STUDENT');
    limit_or_fail('consultation:' . $user['id'], 10, 3600, 'You have sent a lot of requests in the last hour. Try again later.');
    $recipientType = choice_input('recipient_type', 'recipient', ['LECTURER', 'SUPPORT']);
    $courseId = (int) input('course_id') ?: null;
    $recipientId = null;
    if ($recipientType === 'LECTURER') {
        // Students can only reach lecturers of their own classes.
        if (!$courseId || course_role($user, $courseId) !== 'student') {
            fail('Choose one of your courses to reach its lecturer.', 422);
        }
        $recipientId = value('SELECT lecturer_id FROM courses WHERE id = ?', [$courseId]) ?: fail('This course has no lecturer yet. Send it to Support instead.', 422);
    } elseif ($courseId && course_role($user, $courseId) !== 'student') {
        $courseId = null;
    }
    $id = insert('consultations', [
        'student_id' => $user['id'], 'recipient_type' => $recipientType, 'recipient_id' => $recipientId, 'course_id' => $courseId,
        'category' => choice_input('category', 'request type', array_keys(CATEGORY_LABELS)),
        'medium' => choice_input('medium', 'medium', array_keys(MEDIUM_LABELS)),
        'subject' => text_input('subject', 'Subject', 5, 160),
        'details' => text_input('details', 'Details', 20, 5000),
        'preferred_at' => datetime_input('preferred_at', 'Preferred time', false),
        'status' => 'DELIVERED', 'created_at' => now(), 'updated_at' => now(),
    ]);
    insert('consultation_events', ['consultation_id' => $id, 'status' => 'DELIVERED', 'actor_id' => $user['id'], 'created_at' => now()]);
    audit('consultation_requested', "SOP-$id");
    done('Request sent. You can follow its progress here.', url('consultation', ['id' => $id]), ['id' => $id]);
}

function action_update_consultation(): void
{
    $user = action_user();
    [$c, $side] = consultation_access((int) input('consultation_id'), $user);
    $step = choice_input('step', 'step', ['review', 'request_revision', 'respond', 'approve', 'decline', 'complete']);
    try {
        $next = sop_next($c['status'], $step, $side);
    } catch (InvalidArgumentException $err) {
        fail($err->getMessage(), 409);
    }

    $changes = ['status' => $next, 'updated_at' => now()];
    $note = null;
    switch ($step) {
        case 'approve':
            $changes['scheduled_at'] = datetime_input('scheduled_at', 'Session time');
            if (ts($changes['scheduled_at']) < time() - 60) {
                fail('Pick a session time in the future.', 422);
            }
            $changes['session_link'] = text_input('session_link', 'Meeting link or room', 3, 300);
            $note = 'Scheduled: ' . $changes['session_link'];
            break;
        case 'decline':
            $changes['decline_reason'] = $note = text_input('reason', 'A reason', 10, 2000);
            break;
        case 'request_revision':
            $note = text_input('note', 'Your request', 10, 2000);
            break;
        case 'respond':
            $note = text_input('note', 'Your reply', 5, 5000);
            break;
        case 'complete':
            $changes['summary'] = $note = text_input('summary', 'Summary', 0, 5000) ?: null;
            break;
    }
    update('consultations', (int) $c['id'], $changes);
    insert('consultation_events', ['consultation_id' => $c['id'], 'status' => $next, 'note' => $note, 'actor_id' => $user['id'], 'created_at' => now()]);
    audit('consultation_step', "SOP-{$c['id']}: {$c['status']} to $next");
    done('Updated: ' . strtolower(SOP_LABELS[$next]) . '.', null, ['status' => $next]);
}
