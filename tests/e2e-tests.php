<?php
/**
 * e2e-tests.php
 *
 * End-to-end tests: signs in as each role against a running server and tries both the normal
 * flows and the things that must be refused (other people's files, late uploads, fake requests...).
 *
 * Run:  php database/seed.php
 *       php -S 127.0.0.1:8000 -t public      (in another terminal)
 *       php tests/e2e-tests.php
 * The tests change the demo data, so run the seed again afterwards.
 */

define('ROOT', dirname(__DIR__));
require ROOT . '/helpers/database.php';
const BASE = 'http://127.0.0.1:8000';

$passed = 0;
$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS' : 'FAIL') . "  $name" . (!$ok && $detail ? "  -> $detail" : '') . "\n";
}

/** A browser-like client with its own cookies and CSRF token. */
final class Client
{
    public string $jar;
    public string $csrf = '';

    public function __construct()
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'e2e');
        $this->refreshToken();
    }

    public function copy(): Client
    {
        $c = clone $this;
        $c->jar = tempnam(sys_get_temp_dir(), 'e2e');
        copy($this->jar, $c->jar);
        return $c;
    }

    public function refreshToken(): void
    {
        $page = $this->get('index.php?page=not-found', true);
        preg_match('/name="csrf-token" content="([^"]+)"/', $page['body'], $m);
        $this->csrf = $m[1] ?? '';
    }

    /** @return array{status:int, body:string, headers:string} */
    public function request(string $path, array $options = []): array
    {
        $ch = curl_init(BASE . '/' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_FOLLOWLOCATION => $options['follow'] ?? false, CURLOPT_HTTPHEADER => $options['headers'] ?? [],
        ] + ($options['curl'] ?? []));
        $raw = curl_exec($ch);
        $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    }

    public function get(string $path, bool $follow = false): array
    {
        return $this->request($path, ['follow' => $follow]);
    }

    /** Post an action the way app.js does, and get JSON back. */
    public function act(string $action, array $data = [], ?string $origin = BASE, ?string $token = null): array
    {
        $headers = ['Content-Type: application/json', 'X-Requested-With: fetch', 'X-CSRF-Token: ' . ($token ?? $this->csrf)];
        if ($origin) {
            $headers[] = "Origin: $origin";
        }
        $res = $this->request('index.php', ['headers' => $headers, 'curl' => [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['action' => $action] + $data)]]);
        return $res + ['json' => json_decode($res['body'], true) ?? []];
    }

    public function upload(string $action, array $fields, string $name, string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, $bytes);
        $post = ['action' => $action, 'csrf' => $this->csrf] + $fields + ['file' => new CURLFile($tmp, 'application/octet-stream', $name)];
        $res = $this->request('index.php', ['headers' => ['X-Requested-With: fetch', 'Origin: ' . BASE], 'curl' => [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post]]);
        unlink($tmp);
        return $res + ['json' => json_decode($res['body'], true) ?? []];
    }

    public function login(string $email, string $password): array
    {
        $res = $this->act('login', ['email' => $email, 'password' => $password]);
        $this->refreshToken(); // a new session (and token) starts after signing in
        return $res;
    }
}

function signed_in(string $email, string $password): Client
{
    $c = new Client();
    $c->login($email, $password);
    return $c;
}

$pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
$local = fn (int $offset) => (new DateTime('@' . (time() + $offset)))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('Y-m-d\TH:i');

// ---------- headers and fake requests ----------
$guest = new Client();
$home = $guest->get('');
check('Content-Security-Policy header is sent', str_contains($home['headers'], "default-src 'self'"));
check('pages cannot be framed (X-Frame-Options DENY)', str_contains($home['headers'], 'X-Frame-Options: DENY'));
check('X-Content-Type-Options nosniff is sent', str_contains($home['headers'], 'nosniff'));
check('Referrer-Policy is sent', str_contains($home['headers'], 'Referrer-Policy'));
check('a request from another site is refused', $guest->act('login', ['email' => 'a@b.co', 'password' => 'x'], 'https://evil.example')['status'] === 403);
check('a request without the CSRF token is refused', $guest->act('login', ['email' => 'a@b.co', 'password' => 'x'], BASE, 'wrong-token')['status'] === 403);

// ---------- signing in and registering ----------
$wrong = (new Client())->login('kayne@student.aetheris.edu', 'wrong-password');
check('a wrong password is refused', $wrong['status'] === 401, (string) $wrong['status']);
$k = new Client();
$res = $k->act('login', ['email' => 'kayne@student.aetheris.edu', 'password' => 'Student#2026']);
check('a student can sign in', $res['status'] === 200 && ($res['json']['role'] ?? '') === 'STUDENT');
check('the session cookie is HttpOnly and SameSite=Strict', (bool) preg_match('/Set-Cookie: aetheris=[^\r\n]*HttpOnly[^\r\n]*/i', $res['headers'])
    && (bool) preg_match('/Set-Cookie: aetheris=[^\r\n]*SameSite=Strict/i', $res['headers']), $res['headers']);
$k->refreshToken();
$victim = 'nobody' . time() . '@aetheris.edu';
$attacker = new Client();
for ($i = 0; $i < 6; $i++) {
    $last = $attacker->act('login', ['email' => $victim, 'password' => 'Wrong-pass-123']);
}
check('repeated wrong passwords are slowed down', $last['status'] === 429, (string) $last['status']);

$stranger = new Client();
check('a weak password is refused at sign-up', $stranger->act('register', ['name' => 'Test', 'email' => 't' . time() . '@x.edu', 'student_number' => '2800000001', 'password' => 'weak'])['status'] === 422);
$number = (string) random_int(2800000000, 2899999999);
$made = $stranger->act('register', ['name' => 'Outsider', 'email' => 'outsider' . time() . '@x.edu', 'student_number' => $number, 'password' => 'Str0ngPassword', 'role' => 'ADMIN']);
check('sign-up ignores an injected role=ADMIN and creates a student', $made['status'] === 200 && ($made['json']['role'] ?? '') === 'STUDENT', $made['body']);
$stranger->refreshToken();
$outsider = $stranger;

$nadya = signed_in('nadya@student.aetheris.edu', 'Student#2026');
$shinji = signed_in('shinji@student.aetheris.edu', 'Student#2026');
$joshua = signed_in('joshua@student.aetheris.edu', 'Student#2026');
$rina = signed_in('rina.anggraini@aetheris.edu', 'Lecturer#2026');
$budi = signed_in('budi.santoso@aetheris.edu', 'Lecturer#2026');
$admin = signed_in('admin@aetheris.edu', 'Admin#Aetheris2026');

// ---------- roles ----------
check('a student cannot create lecturer accounts', $k->act('create_lecturer', ['name' => 'X', 'email' => 'x@x.edu'])['status'] === 403);
check('a lecturer cannot create classes', $rina->act('create_course', ['code' => 'COMP9999', 'title' => 'Nope', 'description' => ''])['status'] === 403);
check('downloads need a sign-in', (new Client())->request('index.php?action=download&id=1', ['headers' => ['X-Requested-With: fetch']])['status'] === 401);
check('a student cannot open the admin page', $k->get('index.php?page=admin')['status'] === 303);

// ---------- joining a class ----------
$join = $joshua->act('join_course', ['code' => '  dstr-7q4k ']);
check('join codes work with spaces, dashes and lowercase', $join['status'] === 200, $join['body']);

// ---------- other people's files ----------
$nadyaFile = value("SELECT s.file_id FROM submissions s JOIN users u ON u.id = s.student_id WHERE u.email = 'nadya@student.aetheris.edu'");
$path = "index.php?action=download&id=$nadyaFile";
check("a student cannot download another student's work", $k->get($path)['status'] === 404);
check("a lecturer of another course cannot download it", $budi->get($path)['status'] === 404);
$own = $nadya->get($path);
check('the owner can download their own work', $own['status'] === 200);
check('downloads are sent as attachments with nosniff', str_contains($own['headers'], 'attachment') && str_contains($own['headers'], 'nosniff'));
check('the course lecturer can download it', $rina->get($path)['status'] === 200);
$inlinePdf = $rina->get("$path&inline=1");
check('a PDF shown inside the page may not run scripts', str_contains($inlinePdf['headers'], "script-src 'none'") && str_contains($inlinePdf['headers'], 'inline'));

// ---------- deadlines and uploads ----------
$bigO = value("SELECT id FROM assignments WHERE title = 'Big-O analysis worksheet'");
$linked = value("SELECT id FROM assignments WHERE title = 'Linked list implementation'");
check('uploads after the deadline are refused', $shinji->upload('submit_assignment', ['assignment_id' => $bigO], 'late.pdf', $pdf)['status'] === 403);
check('a renamed program is refused', $k->upload('submit_assignment', ['assignment_id' => $linked], 'essay.pdf', "MZ\x90\x00\x03")['status'] === 422);
check('a .exe file is refused', $k->upload('submit_assignment', ['assignment_id' => $linked], 'tool.exe', 'plain text')['status'] === 422);
check('someone outside the course cannot hand in', $outsider->upload('submit_assignment', ['assignment_id' => $linked], 'x.pdf', $pdf)['status'] === 404);
$good = $k->upload('submit_assignment', ['assignment_id' => $linked], '../../../etc/passwd.pdf', $pdf);
check('a real PDF is accepted', $good['status'] === 200, $good['body']);
$stored = one("SELECT s.id, f.id AS file_id, f.stored_name FROM submissions s JOIN files f ON f.id = s.file_id JOIN users u ON u.id = s.student_id WHERE s.assignment_id = ? AND u.email = 'kayne@student.aetheris.edu'", [$linked]);
check('the file is stored under a random name', (bool) preg_match('/^[a-f0-9]{32}$/', $stored['stored_name'] ?? ''));
$dl = $k->get('index.php?action=download&id=' . $stored['file_id']);
check('the download name cannot contain ../', !str_contains($dl['headers'], '../'));

// ---------- grading ----------
check('a student cannot grade', $k->act('grade_submission', ['submission_id' => $stored['id'], 'score' => 100])['status'] === 403);
check('a lecturer of another course cannot grade', $budi->act('grade_submission', ['submission_id' => $stored['id'], 'rubric_scores' => [50, 30, 20]])['status'] === 404);
$graded = $rina->act('grade_submission', ['submission_id' => $stored['id'], 'rubric_scores' => [45, 25, 18], 'feedback' => '<b>Nice</b> work']);
check('the lecturer grades with the rubric (45 + 25 + 18 = 88)', ($graded['json']['score'] ?? null) === 88, $graded['body']);

// ---------- consultation steps ----------
$sop = value("SELECT c.id FROM consultations c JOIN users u ON u.id = c.student_id WHERE c.status = 'DELIVERED' AND u.email = 'kayne@student.aetheris.edu'");
check("a student cannot open someone else's request", $shinji->get("index.php?page=consultation&id=$sop")['status'] === 404);
$rina->get("index.php?page=consultation&id=$sop");
check('opening the request marks it as read', value('SELECT status FROM consultations WHERE id = ?', [$sop]) === 'READ');
$step = fn (Client $c, array $data) => $c->act('update_consultation', ['consultation_id' => $sop] + $data);
check('read to under review', ($step($rina, ['step' => 'review'])['json']['status'] ?? '') === 'UNDER_REVIEW');
check('declining without a reason is refused', $step($rina, ['step' => 'decline', 'reason' => ''])['status'] === 422);
check('a student cannot approve their own request', $step($k, ['step' => 'approve', 'scheduled_at' => $local(86400), 'session_link' => 'Room 4'])['status'] === 409);
check('under review to revision requested', ($step($rina, ['step' => 'request_revision', 'note' => 'Please attach your draft outline.'])['json']['status'] ?? '') === 'REVISION_REQUESTED');
check('the student replies and it goes back to review', ($step($k, ['step' => 'respond', 'note' => 'Outline is in the forum thread.'])['json']['status'] ?? '') === 'UNDER_REVIEW');
check('under review to approved and scheduled', ($step($rina, ['step' => 'approve', 'scheduled_at' => $local(2 * 86400), 'session_link' => 'https://meet.example.edu/abc'])['json']['status'] ?? '') === 'APPROVED_SCHEDULED');
check('approving twice is refused', $step($rina, ['step' => 'approve', 'scheduled_at' => $local(86400), 'session_link' => 'Room 4'])['status'] === 409);
check('approved to completed', ($step($rina, ['step' => 'complete', 'summary' => 'Scope agreed.'])['json']['status'] ?? '') === 'COMPLETED');

// ---------- exam ----------
$exam = value('SELECT id FROM exams LIMIT 1');
check('someone outside the course cannot start the exam', $outsider->act('start_exam', ['exam_id' => $exam])['status'] === 404);
check('a student can start the exam', $shinji->act('start_exam', ['exam_id' => $exam])['status'] === 200);
$answers = [];
foreach (all('SELECT id, type, correct_index FROM questions WHERE exam_id = ?', [$exam]) as $q) {
    $answers[$q['id']] = $q['type'] === 'MCQ' ? (int) $q['correct_index'] : 'Adjacency lists use O(V+E) memory.';
}
check('answers save automatically', $shinji->act('save_exam', ['exam_id' => $exam, 'answers' => $answers, 'tab_switch' => true])['status'] === 200);
$examPage = $shinji->get("index.php?page=exam&id=$exam")['body'];
check('the correct answers are never sent to the student', !str_contains($examPage, 'correct_index') && str_contains($examPage, 'data-deadline'));
check('the exam can be submitted', $shinji->act('submit_exam', ['exam_id' => $exam])['status'] === 200);
$attempt = one("SELECT x.* FROM exam_attempts x JOIN users u ON u.id = x.student_id WHERE x.exam_id = ? AND u.email = 'shinji@student.aetheris.edu'", [$exam]);
check('multiple choice is scored, the essay waits, the tab switch is counted', (int) $attempt['mcq_points'] === 60 && $attempt['score'] === null && (int) $attempt['tab_switches'] === 1, json_encode($attempt));
check('answers cannot change after submitting', $shinji->act('save_exam', ['exam_id' => $exam, 'answers' => []])['status'] === 409);

// ---------- HTML in user text ----------
$thread = one("SELECT t.id, t.course_id FROM forum_threads t JOIN courses c ON c.id = t.course_id WHERE c.code = 'COMP6100'");
$k->act('reply', ['thread_id' => $thread['id'], 'body' => '<img src=x onerror="alert(1)"> **bold** <script>alert(2)</script>']);
$page = $k->get("index.php?page=thread&id={$thread['id']}")['body'];
check('HTML in forum posts is shown as text, not run', !str_contains($page, '<img src=x') && !str_contains($page, '<script>alert(2)'));

// Liking one answer must only mark that one answer as liked by you.
$firstPost = value('SELECT id FROM forum_posts WHERE thread_id = ? ORDER BY id LIMIT 1', [$thread['id']]);
$votesBefore = (int) value('SELECT COUNT(*) FROM forum_votes WHERE post_id = ?', [$firstPost]);
$k->act('vote', ['post_id' => $firstPost]);
$page = $k->get("index.php?page=thread&id={$thread['id']}")['body'];
check('liking one answer marks only that answer as liked', substr_count($page, 'title="Remove your upvote"') === 1
    && (int) value('SELECT COUNT(*) FROM forum_votes WHERE post_id = ?', [$firstPost]) === $votesBefore + 1, (string) substr_count($page, 'title="Remove your upvote"'));
$k->act('vote', ['post_id' => $firstPost]);
check('liking again removes the like', (int) value('SELECT COUNT(*) FROM forum_votes WHERE post_id = ?', [$firstPost]) === $votesBefore);
$k->act('send_chat', ['course_id' => $thread['course_id'], 'body' => '<script>alert(1)</script>hello']);
$chatPage = $k->get("index.php?page=course&id={$thread['course_id']}&tab=chat")['body'];
check('HTML in chat messages is shown as text, not run', !str_contains($chatPage, '<script>alert(1)') && str_contains($chatPage, '&lt;script&gt;alert(1)'));

// ---------- switching an account off ----------
$joshuaId = value("SELECT id FROM users WHERE email = 'joshua@student.aetheris.edu'");
check('an admin can deactivate an account', $admin->act('set_user_active', ['user_id' => $joshuaId, 'active' => 0])['status'] === 200);
check("the deactivated user's session stops working at once", $joshua->act('set_theme', ['theme' => 'nordic'])['status'] !== 200 || $joshua->get('index.php?page=dashboard')['status'] === 303);
check('a deactivated user cannot sign in', (new Client())->login('joshua@student.aetheris.edu', 'Student#2026')['status'] === 403);
$admin->act('set_user_active', ['user_id' => $joshuaId, 'active' => 1]);

// ---------- temporary passwords for new lecturers ----------
$created = $admin->act('create_lecturer', ['name' => 'Temp Lecturer', 'email' => 'temp' . time() . '@aetheris.edu']);
$temp = new Client();
$temp->login($created['json']['email'], $created['json']['temp_password']);
check('a temporary password only allows changing the password', $temp->act('update_profile', ['name' => 'Temp', 'avatar' => 'none'])['status'] === 403);
$before = $temp->copy();
$changed = $temp->act('change_password', ['current_password' => $created['json']['temp_password'], 'new_password' => 'Brand-New-Pass-42']);
$temp->refreshToken();
check('after changing it the account works normally', $changed['status'] === 200 && $temp->act('update_profile', ['name' => 'Temp Lecturer', 'avatar' => 'none'])['status'] === 200);
check('the old session ends when the password changes', $before->get('index.php?page=dashboard')['status'] === 303);

// ---------- every role's main pages load ----------
foreach ([[$k, 'dashboard'], [$k, 'calendar'], [$k, 'grades'], [$rina, 'assignments'], [$admin, 'admin'], [$admin, 'admin-log']] as [$client, $pageName]) {
    $r = $client->get("index.php?page=$pageName");
    check("page $pageName loads", $r['status'] === 200 && !preg_match('/(Fatal error|Warning:|Deprecated:|Notice:)/', $r['body']));
}

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
