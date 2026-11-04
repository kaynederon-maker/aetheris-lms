<?php
/**
 * RULES: the app's business rules in plain functions with no database access,
 * so they are easy to read and to test (see tests/unit-tests.php).
 * - join codes and the password policy
 * - the consultation (SOP) steps
 * - exam shuffling and scoring
 * - rubric parsing
 */

/* ---------- join codes ---------- */

const JOIN_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O or 1/I, they get confused

/** " dstr-7q4k " becomes "DSTR7Q4K". People can paste codes however they like. */
function normalize_join_code(string $raw): string
{
    return preg_replace('/[^A-Z0-9]/', '', strtoupper($raw));
}

function format_join_code(string $code): string
{
    return substr($code, 0, 4) . '-' . substr($code, 4);
}

function generate_join_code(): string
{
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= JOIN_ALPHABET[random_int(0, strlen(JOIN_ALPHABET) - 1)];
    }
    return $code;
}

/* ---------- passwords ---------- */

const PASSWORD_HINT = 'At least 10 characters with an uppercase letter, a lowercase letter and a number.';

/** Returns null when the password is acceptable, otherwise the reason. */
function password_problem(string $password): ?string
{
    $ok = strlen($password) >= 10 && strlen($password) <= 128
        && preg_match('/[a-z]/', $password) && preg_match('/[A-Z]/', $password) && preg_match('/\d/', $password);
    return $ok ? null : PASSWORD_HINT;
}

/* ---------- consultation steps (SOP) ---------- */

const SOP_LABELS = [
    'DELIVERED' => 'Delivered',
    'READ' => 'Read',
    'UNDER_REVIEW' => 'Under review',
    'REVISION_REQUESTED' => 'Revision requested',
    'APPROVED_SCHEDULED' => 'Approved & scheduled',
    'DECLINED' => 'Declined',
    'COMPLETED' => 'Completed',
];

/** action => which statuses it can start from, where it leads, and who may do it. */
const SOP_RULES = [
    'read' => ['from' => ['DELIVERED'], 'to' => 'READ', 'by' => 'recipient'],
    'review' => ['from' => ['DELIVERED', 'READ'], 'to' => 'UNDER_REVIEW', 'by' => 'recipient'],
    'request_revision' => ['from' => ['READ', 'UNDER_REVIEW'], 'to' => 'REVISION_REQUESTED', 'by' => 'recipient'],
    'respond' => ['from' => ['REVISION_REQUESTED'], 'to' => 'UNDER_REVIEW', 'by' => 'student'],
    'approve' => ['from' => ['READ', 'UNDER_REVIEW'], 'to' => 'APPROVED_SCHEDULED', 'by' => 'recipient'],
    'decline' => ['from' => ['READ', 'UNDER_REVIEW', 'REVISION_REQUESTED'], 'to' => 'DECLINED', 'by' => 'recipient'],
    'complete' => ['from' => ['APPROVED_SCHEDULED'], 'to' => 'COMPLETED', 'by' => 'recipient'],
];

/** The next status, or an exception explaining why the step is not allowed. */
function sop_next(string $status, string $action, string $side): string
{
    $rule = SOP_RULES[$action] ?? null;
    if (!$rule) {
        throw new InvalidArgumentException('Unknown step.');
    }
    if ($rule['by'] !== $side) {
        throw new InvalidArgumentException('You are not allowed to take this step.');
    }
    if (!in_array($status, $rule['from'], true)) {
        throw new InvalidArgumentException('This request is ' . strtolower(SOP_LABELS[$status]) . ', so that step is not possible.');
    }
    return $rule['to'];
}

/** Steps a side can take right now (the automatic "read" step is left out). */
function sop_actions(string $status, string $side): array
{
    $out = [];
    foreach (SOP_RULES as $action => $rule) {
        if ($action !== 'read' && $rule['by'] === $side && in_array($status, $rule['from'], true)) {
            $out[] = $action;
        }
    }
    return $out;
}

function sop_is_open(string $status): bool
{
    return $status !== 'COMPLETED' && $status !== 'DECLINED';
}

/**
 * The steps to draw on the progress chart. Each step is done, current, skipped or upcoming.
 * $events: list of ['status' => ..., 'created_at' => ..., 'note' => ...] in time order.
 */
function sop_flow(string $status, array $events): array
{
    $last = [];
    foreach ($events as $e) {
        $last[$e['status']] = $e;
    }
    $end = $status === 'DECLINED' ? ['DECLINED'] : ['APPROVED_SCHEDULED', 'COMPLETED'];
    $path = ['DELIVERED', 'READ', 'UNDER_REVIEW', 'REVISION_REQUESTED', ...$end];
    $currentIndex = array_search($status, $path, true);
    $steps = [];
    foreach ($path as $i => $key) {
        $event = $last[$key] ?? null;
        $state = $key === $status ? 'current' : ($event ? 'done' : ($i < $currentIndex ? 'skipped' : 'upcoming'));
        $steps[] = [
            'key' => $key,
            'label' => SOP_LABELS[$key],
            'state' => $state,
            'at' => $event['created_at'] ?? null,
            'note' => $event['note'] ?? null,
            'branch' => $key === 'REVISION_REQUESTED',
        ];
    }
    return $steps;
}

/* ---------- exams ---------- */

const EXAM_GRACE_SECONDS = 5; // allowance for the last autosave arriving a moment late

/** Fisher-Yates shuffle using a cryptographically secure random source. */
function shuffle_secure(array $items): array
{
    for ($i = count($items) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
    }
    return $items;
}

/** Auto-scores multiple choice; essays are left for the lecturer. */
function score_attempt(array $questions, array $answers): array
{
    $mcq = 0;
    $total = 0;
    $hasEssay = false;
    foreach ($questions as $q) {
        $total += (int) $q['points'];
        if ($q['type'] === 'ESSAY') {
            $hasEssay = true;
        } elseif (isset($answers[$q['id']]) && (int) $answers[$q['id']] === (int) $q['correct_index'] && is_numeric($answers[$q['id']])) {
            $mcq += (int) $q['points'];
        }
    }
    return ['mcq' => $mcq, 'total' => $total, 'has_essay' => $hasEssay];
}

/** Final 0-100 score, or null while essays still need grading. */
function final_score(int $mcq, ?int $essay, int $total, bool $hasEssay): ?int
{
    if ($total === 0) {
        return 0;
    }
    if ($hasEssay && $essay === null) {
        return null;
    }
    return min(100, (int) round(100 * ($mcq + ($essay ?? 0)) / $total));
}

/** The timer ends after the exam's duration, but never later than the exam window. */
function attempt_deadline(int $startTs, int $durationMin, int $closesTs): int
{
    return min($startTs + $durationMin * 60, $closesTs);
}

function attempt_expired(array $attempt): bool
{
    return time() > ts($attempt['deadline_at']) + EXAM_GRACE_SECONDS;
}

/**
 * Lock an exam attempt: score the multiple-choice part now, leave the final score empty
 * while essays wait for the lecturer. (This one reads and writes the database.)
 */
function finalize_attempt(array $attempt, bool $automatic): array
{
    if ($attempt['submitted_at']) {
        return $attempt;
    }
    $questions = all('SELECT id, type, correct_index, points FROM questions WHERE exam_id = ?', [$attempt['exam_id']]);
    $score = score_attempt($questions, json_decode($attempt['answers'], true) ?: []);
    $submittedAt = gmdate('Y-m-d H:i:s', min(time(), ts($attempt['deadline_at']) + EXAM_GRACE_SECONDS));
    update('exam_attempts', (int) $attempt['id'], [
        'submitted_at' => $submittedAt,
        'auto_submitted' => $automatic ? 1 : 0,
        'mcq_points' => $score['mcq'],
        'score' => final_score($score['mcq'], null, $score['total'], $score['has_essay']),
    ]);
    return one('SELECT * FROM exam_attempts WHERE id = ?', [$attempt['id']]);
}

/* ---------- rubrics ---------- */

/** "Correctness | 60" per line. The points must add up to 100. */
function parse_rubric(string $text): array
{
    $rows = [];
    foreach (preg_split('/\R/', trim($text)) as $line) {
        if (trim($line) === '') {
            continue;
        }
        [$criterion, $points] = array_map('trim', explode('|', $line, 2) + [1 => '']);
        if ($criterion === '' || !ctype_digit($points) || (int) $points <= 0) {
            throw new InvalidArgumentException("Write each rubric line as 'Criterion | points'.");
        }
        $rows[] = ['criterion' => mb_substr($criterion, 0, 80), 'max' => (int) $points];
    }
    if ($rows && array_sum(array_column($rows, 'max')) !== 100) {
        throw new InvalidArgumentException('Rubric points must add up to 100.');
    }
    return $rows;
}
