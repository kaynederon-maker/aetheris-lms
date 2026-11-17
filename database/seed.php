<?php
/**
 * Demo data: wipes the database and uploads, then fills them with a small example campus.
 * Run it with:  php database/seed.php
 * The demo passwords are public (see README), so this refuses to run when APP_ENV=production.
 */

define('ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');
foreach (['database', 'rules', 'uploads'] as $helper) {
    require ROOT . "/helpers/$helper.php";
}

if (getenv('APP_ENV') === 'production' && getenv('ALLOW_DEMO_SEED') !== '1') {
    fwrite(STDERR, "Refusing to load demo accounts with APP_ENV=production (set ALLOW_DEMO_SEED=1 to override).\n");
    exit(1);
}

// Start from nothing.
@unlink(DB_FILE);
foreach (glob(UPLOAD_DIR . '/*') ?: [] as $file) {
    unlink($file);
}
db(); // creates the tables from schema.sql

const H = 3600;
const D = 86400;

/** A tiny but valid one-page PDF, so downloads and the in-page viewer work offline. */
function make_pdf(string $title, array $lines): string
{
    $text = '';
    foreach (array_merge([$title, ''], $lines) as $i => $line) {
        $size = $i === 0 ? 20 : 12;
        $text .= "BT /F1 $size Tf 72 " . (740 - $i * 22) . ' Td (' . addcslashes($line, '()\\') . ") Tj ET\n";
    }
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length ' . strlen($text) . " >>\nstream\n$text\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n$object\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    return $pdf . 'trailer' . "\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
}

function user(string $email, string $name, string $role, string $password, array $extra = []): int
{
    return insert('users', ['email' => $email, 'name' => $name, 'role' => $role, 'created_at' => now(),
        'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12])] + $extra);
}

function event_rows(int $consultationId, array $events): void
{
    foreach ($events as [$status, $offset, $actor, $note]) {
        insert('consultation_events', ['consultation_id' => $consultationId, 'status' => $status, 'actor_id' => $actor, 'note' => $note, 'created_at' => now($offset)]);
    }
}

// ----- people -----
$admin = user('admin@aetheris.edu', 'Aetheris Admin', 'ADMIN', 'Admin#Aetheris2026', ['avatar' => 'a6']);
$rina = user('rina.anggraini@aetheris.edu', 'Dr. Rina Anggraini', 'LECTURER', 'Lecturer#2026', ['avatar' => 'a3']);
$budi = user('budi.santoso@aetheris.edu', 'Budi Santoso, M.Kom.', 'LECTURER', 'Lecturer#2026', ['avatar' => 'a2', 'theme' => 'earth']);
$kayne = user('kayne@student.aetheris.edu', 'Kayne Kusnadi', 'STUDENT', 'Student#2026', ['student_number' => '2802405150', 'avatar' => 'a1']);
$nadya = user('nadya@student.aetheris.edu', 'Nadya Roselani Bramanjaya', 'STUDENT', 'Student#2026', ['student_number' => '2802403605', 'avatar' => 'a3', 'theme' => 'burgundy']);
$shinji = user('shinji@student.aetheris.edu', 'Shinji Prudent Zhang', 'STUDENT', 'Student#2026', ['student_number' => '2802402104', 'avatar' => 'a4', 'theme' => 'nordic']);
$joshua = user('joshua@student.aetheris.edu', 'Joshua Nathanael Tanadi', 'STUDENT', 'Student#2026', ['student_number' => '2802396972', 'avatar' => 'a5', 'theme' => 'apple-dark']);

// ----- classes -----
$ds = insert('courses', ['code' => 'COMP6100', 'title' => 'Data Structures & Algorithms', 'join_code' => 'DSTR7Q4K', 'banner' => 'comp6100.webp', 'lecturer_id' => $rina,
    'description' => 'Linear and non-linear data structures, complexity analysis, and the algorithms that make them useful.']);
$dm = insert('courses', ['code' => 'MATH6025', 'title' => 'Discrete Mathematics', 'join_code' => 'DMTH3X9P', 'banner' => 'math6025.webp', 'lecturer_id' => $budi,
    'description' => 'Logic, proofs, sets, relations, combinatorics and graph theory for computer scientists.']);
$sec = insert('courses', ['code' => 'ISYS6197', 'title' => 'Information Systems Security', 'join_code' => 'SECU8R2M', 'banner' => 'isys6197.webp', 'lecturer_id' => $rina,
    'description' => 'Threat modelling, secure design, access control and incident response for information systems.']);

// Joshua is not in COMP6100, so the join code DSTR-7Q4K can be tried with his account.
foreach ([[$kayne, $ds], [$nadya, $ds], [$shinji, $ds], [$kayne, $dm], [$nadya, $dm], [$joshua, $dm], [$kayne, $sec], [$nadya, $sec], [$shinji, $sec], [$joshua, $sec]] as [$u, $c]) {
    insert('enrollments', ['user_id' => $u, 'course_id' => $c, 'created_at' => now(-10 * D)]);
}

// ----- materials and announcements -----
$slides = save_bytes('Week 01 - Complexity and Arrays.pdf', make_pdf('Week 01: Complexity and Arrays', ['Asymptotic notation: O, Omega, Theta', 'Amortised analysis of dynamic arrays', 'Practice: analyse three loops before Friday']), 'application/pdf', $rina);
$proofs = save_bytes('Proof Techniques Handout.pdf', make_pdf('Proof Techniques Handout', ['Direct proof, contrapositive, contradiction', 'Mathematical induction (weak and strong)', 'Worked examples 1-6']), 'application/pdf', $budi);
$stride = save_bytes('STRIDE Worksheet.pdf', make_pdf('STRIDE Threat Modelling Worksheet', ['Spoofing, Tampering, Repudiation', 'Information disclosure, Denial of service, Elevation of privilege']), 'application/pdf', $rina);
$materials = [
    [$ds, 'Course outline and grading', "## Grading\n\n| Part | Weight |\n|---|---|\n| Assignments | 40% |\n| Midterm quiz | 25% |\n| Final project | 35% |\n\nLate work is **not accepted**: uploads close at the deadline.", null],
    [$ds, 'Week 01: Complexity and arrays (slides)', null, $slides],
    [$ds, 'Week 02: Linked lists', "## Singly linked lists\n\nEach node stores a value and a pointer to the next node.\n\n```python\nclass Node:\n    def __init__(self, value):\n        self.value = value\n        self.next = None\n```\n\n- Insert at the head: **O(1)**\n- Search: **O(n)**\n\n> Practice: reverse a list in place without extra memory.", null],
    [$dm, 'Proof techniques handout', null, $proofs],
    [$dm, 'Week 03: Induction notes', "## Induction template\n\n1. **Base case:** show P(0).\n2. **Inductive step:** assume P(k), prove P(k + 1).\n3. Conclude P(n) for all n >= 0.", null],
    [$sec, 'STRIDE threat-modelling worksheet', null, $stride],
    [$sec, 'Reading: OWASP Top 10 overview', 'Read the OWASP Top 10 summary and note which risks apply to a student portal. Bring two examples to class.', null],
];
foreach ($materials as $i => [$course, $title, $body, $file]) {
    insert('materials', ['course_id' => $course, 'title' => $title, 'body' => $body, 'file_id' => $file, 'position' => $i + 1, 'created_at' => now(-9 * D)]);
}
insert('announcements', ['course_id' => $ds, 'title' => 'Midterm quiz is open', 'body' => 'The trees and graphs quiz is open this week. You have **20 minutes** once you start, and your answers save as you go.', 'created_at' => now(-3 * H)]);
insert('announcements', ['course_id' => $dm, 'title' => 'Office hours moved', 'body' => 'Office hours move to Thursday 13:00-15:00 this week. Book through Consultations if that time does not work for you.', 'created_at' => now(-D)]);
insert('announcements', ['course_id' => $sec, 'title' => 'Guest lecture on incident response', 'body' => 'Next Tuesday a SOC analyst is giving a guest lecture. Attendance counts toward participation.', 'created_at' => now(-2 * D)]);

// ----- assignments and submissions -----
$rubric = json_encode([['criterion' => 'Correctness', 'max' => 50], ['criterion' => 'Complexity analysis', 'max' => 30], ['criterion' => 'Code quality', 'max' => 20]]);
insert('assignments', ['course_id' => $ds, 'title' => 'Linked list implementation', 'description' => 'Implement a doubly linked list with insert, delete and reverse. Add a short complexity analysis in comments.', 'due_at' => now(5 * D), 'allowed_types' => 'zip,code,pdf', 'rubric' => $rubric]);
$bigO = insert('assignments', ['course_id' => $ds, 'title' => 'Big-O analysis worksheet', 'description' => 'Analyse the twelve snippets in the worksheet and justify each bound.', 'due_at' => now(-2 * D), 'allowed_types' => 'pdf,docx', 'rubric' => $rubric]);
insert('assignments', ['course_id' => $dm, 'title' => 'Proof set 3: induction', 'description' => 'Solve problems 1 to 6 from the handout. Typeset or scan clearly.', 'due_at' => now(36 * H), 'allowed_types' => 'pdf']);
insert('assignments', ['course_id' => $sec, 'title' => 'Threat model report', 'description' => 'Write a STRIDE threat model of this portal: a data-flow diagram, the threats, and how to reduce them.', 'due_at' => now(10 * D), 'allowed_types' => 'pdf,docx']);
$kFile = save_bytes('bigO-kayne.pdf', make_pdf('Big-O worksheet - Kayne', ['1. O(n)', '2. O(n log n)', '3. O(n^2)']), 'application/pdf', $kayne);
$nFile = save_bytes('bigO-nadya.pdf', make_pdf('Big-O worksheet - Nadya', ['1. O(n)', '2. O(log n)']), 'application/pdf', $nadya);
insert('submissions', ['assignment_id' => $bigO, 'student_id' => $kayne, 'file_id' => $kFile, 'submitted_at' => now(-3 * D), 'score' => 88, 'rubric_scores' => '[46,26,16]', 'graded_at' => now(-D),
    'feedback' => 'Clear reasoning throughout. Snippet 7 is O(n log n), not O(n), because the inner loop halves each time.']);
insert('submissions', ['assignment_id' => $bigO, 'student_id' => $nadya, 'file_id' => $nFile, 'submitted_at' => now((int) (-2.2 * D))]);

// ----- exam -----
$exam = insert('exams', ['course_id' => $ds, 'title' => 'Midterm quiz: Trees & graphs', 'description' => 'Four multiple-choice questions and one short essay. 20 minutes once you start.',
    'opens_at' => now(-3 * H), 'closes_at' => now(7 * D), 'duration_min' => 20]);
$questions = [
    ['MCQ', 'What is the worst-case height of a binary search tree with n nodes?', ['O(log n)', 'O(n)', 'O(n log n)', 'O(1)'], 1, 15],
    ['MCQ', 'Which traversal of a BST visits the keys in sorted order?', ['Pre-order', 'Post-order', 'In-order', 'Level-order'], 2, 15],
    ['MCQ', 'Breadth-first search on an unweighted graph finds...', ['A minimum spanning tree', 'Shortest paths by edge count', 'A topological order', 'Strongly connected components'], 1, 15],
    ['MCQ', "Dijkstra's algorithm needs edge weights that are...", ['Integers', 'Non-negative', 'Distinct', 'Sorted'], 1, 15],
    ['ESSAY', 'When would you choose an adjacency list over an adjacency matrix? Mention memory use and how long it takes to check for an edge.', [], null, 40],
];
foreach ($questions as $i => [$type, $prompt, $options, $correct, $points]) {
    insert('questions', ['exam_id' => $exam, 'type' => $type, 'prompt' => $prompt, 'options' => json_encode($options), 'correct_index' => $correct, 'points' => $points, 'position' => $i + 1]);
}

// ----- forum and chat -----
$thread = insert('forum_threads', ['course_id' => $ds, 'author_id' => $nadya, 'title' => 'Is reversing a doubly linked list O(n) or O(1)?',
    'body' => 'If we keep a tail pointer, can we just swap head and tail? Or do we still need to touch every node?', 'created_at' => now(-20 * H)]);
$p1 = insert('forum_posts', ['thread_id' => $thread, 'author_id' => $rina, 'verified' => 1, 'created_at' => now(-18 * H),
    'body' => "Swapping head and tail isn't enough: every node's `prev` and `next` have to be swapped, so reversal is **O(n)**. A 'reversed' flag gives you O(1) *logical* reversal if your code respects it."]);
$p2 = insert('forum_posts', ['thread_id' => $thread, 'author_id' => $kayne, 'created_at' => now(-16 * H), 'body' => 'Some deque implementations use exactly that flag trick to avoid the copy.']);
// Kayne (the account most people try first) starts with no likes, so his first like is easy to see.
foreach ([[$shinji, $p1], [$joshua, $p1], [$nadya, $p2]] as [$u, $p]) {
    insert('forum_votes', ['user_id' => $u, 'post_id' => $p]);
}
insert('forum_threads', ['course_id' => $dm, 'author_id' => $joshua, 'title' => 'Strong vs weak induction: when does it matter?',
    'body' => "Every strong-induction proof I write could be done with weak induction too. Is there a case where it can't?", 'created_at' => now(-6 * H)]);
foreach ([
    [$rina, 'Welcome to the COMP6100 channel. Use it for quick questions; put anything others might need later in the forum.', -30 * H],
    [$kayne, 'Is the linked list assignment individual or in pairs?', -5 * H],
    [$rina, 'Individual. Talking through ideas is fine, sharing code is not.', -4.8 * H],
    [$shinji, 'Thanks! Will the quiz cover AVL rotations?', -2 * H],
    [$rina, "Rotations aren't in this quiz. It only covers trees, traversals and graph search.", -1.9 * H],
] as [$author, $body, $offset]) {
    insert('chat_messages', ['course_id' => $ds, 'author_id' => $author, 'body' => $body, 'created_at' => now((int) $offset)]);
}

// ----- consultation requests in four different states -----
$c1 = insert('consultations', ['student_id' => $kayne, 'recipient_type' => 'LECTURER', 'recipient_id' => $rina, 'course_id' => $ds, 'category' => 'PROJECT_GUIDANCE', 'medium' => 'VIDEO',
    'subject' => 'Final project scope check', 'details' => 'Would a trie-based autocomplete count as a complex enough data structure for the final project?',
    'preferred_at' => now(3 * D), 'status' => 'DELIVERED', 'created_at' => now(-2 * H), 'updated_at' => now(-2 * H)]);
event_rows($c1, [['DELIVERED', -2 * H, $kayne, null]]);
$c2 = insert('consultations', ['student_id' => $nadya, 'recipient_type' => 'LECTURER', 'recipient_id' => $budi, 'course_id' => $dm, 'category' => 'GRADE_REVISION', 'medium' => 'CHAT',
    'subject' => 'Quiz 2 question 4 marking', 'details' => 'I think my answer to question 4 is a valid contrapositive argument, but it was marked wrong. Could you take another look?',
    'status' => 'UNDER_REVIEW', 'created_at' => now(-D), 'updated_at' => now(-19 * H)]);
event_rows($c2, [['DELIVERED', -D, $nadya, null], ['READ', -20 * H, $budi, null], ['UNDER_REVIEW', -19 * H, $budi, null]]);
$c3 = insert('consultations', ['student_id' => $kayne, 'recipient_type' => 'SUPPORT', 'category' => 'TECHNICAL_SUPPORT', 'medium' => 'VOICE',
    'subject' => 'Cannot open the lab VM from home', 'details' => 'The lab virtual machine has timed out on my home network since Monday. On campus Wi-Fi it works fine.',
    'status' => 'APPROVED_SCHEDULED', 'scheduled_at' => now(2 * D + 3 * H), 'session_link' => 'Helpdesk line 2 (room HD-02)', 'created_at' => now(-2 * D), 'updated_at' => now(-45 * H)]);
event_rows($c3, [['DELIVERED', -2 * D, $kayne, null], ['READ', -47 * H, $admin, null], ['UNDER_REVIEW', -46 * H, $admin, null], ['APPROVED_SCHEDULED', -45 * H, $admin, 'Please bring a screenshot of the error.']]);
$reason = 'I do not hold consultations on Sundays. Please pick a weekday slot during office hours (Tue/Thu 10:00-12:00).';
$c4 = insert('consultations', ['student_id' => $shinji, 'recipient_type' => 'LECTURER', 'recipient_id' => $rina, 'course_id' => $sec, 'category' => 'ACADEMIC_CONSULTATION', 'medium' => 'VIDEO',
    'subject' => 'Extra consultation before the report', 'details' => 'Could we meet on Sunday to go through my data-flow diagram before I hand in the threat model report?',
    'status' => 'DECLINED', 'decline_reason' => $reason, 'created_at' => now(-3 * D), 'updated_at' => now(-69 * H)]);
event_rows($c4, [['DELIVERED', -3 * D, $shinji, null], ['READ', -70 * H, $rina, null], ['DECLINED', -69 * H, $rina, $reason]]);

insert('audit_log', ['actor_id' => $admin, 'action' => 'demo_data_loaded', 'target' => 'database', 'created_at' => now()]);
echo "Demo data loaded: 1 admin, 2 lecturers, 4 students, 3 classes.\n";
