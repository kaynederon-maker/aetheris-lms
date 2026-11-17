<?php
/**
 * unit-tests.php
 *
 * Unit tests for the rules, upload checks and Markdown renderer. No server or database needed.
 * Run:  php tests/unit-tests.php
 */

define('ROOT', dirname(__DIR__));
date_default_timezone_set('UTC');
foreach (['database', 'security', 'rules', 'uploads', 'markdown'] as $helper) {
    require ROOT . "/helpers/$helper.php";
}

$passed = 0;
$failed = 0;
function check(string $name, bool $ok): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? 'PASS' : 'FAIL') . "  $name\n";
}
function throws(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (Throwable) {
        return true;
    }
}

// join codes and passwords
check('join codes accept spaces, dashes and lowercase', normalize_join_code('  dstr-7q4k ') === 'DSTR7Q4K');
check('generated join codes are 8 unambiguous characters', (bool) preg_match('/^[A-HJ-NP-Z2-9]{8}$/', generate_join_code()));
check('short passwords are rejected', password_problem('Short1') !== null);
check('passwords need upper, lower and a digit', password_problem('alllowercase123') !== null);
check('a strong password passes', password_problem('Str0ngEnough!') === null);

// consultation steps
check('happy path: delivered to read', sop_next('DELIVERED', 'read', 'recipient') === 'READ');
check('read to under review', sop_next('READ', 'review', 'recipient') === 'UNDER_REVIEW');
check('under review to approved', sop_next('UNDER_REVIEW', 'approve', 'recipient') === 'APPROVED_SCHEDULED');
check('approved to completed', sop_next('APPROVED_SCHEDULED', 'complete', 'recipient') === 'COMPLETED');
check('revision branch goes back to review', sop_next('REVISION_REQUESTED', 'respond', 'student') === 'UNDER_REVIEW');
check('a declined request cannot be approved', throws(fn () => sop_next('DECLINED', 'approve', 'recipient')));
check('a student cannot approve', throws(fn () => sop_next('UNDER_REVIEW', 'approve', 'student')));
check('students only get the reply step', sop_actions('REVISION_REQUESTED', 'student') === ['respond']);
check('finished requests have no steps left', sop_actions('COMPLETED', 'recipient') === []);
$flow = sop_flow('DECLINED', [
    ['status' => 'DELIVERED', 'created_at' => '2026-01-01 10:00:00', 'note' => null],
    ['status' => 'READ', 'created_at' => '2026-01-01 11:00:00', 'note' => null],
    ['status' => 'DECLINED', 'created_at' => '2026-01-01 12:00:00', 'note' => 'Outside office hours'],
]);
check('declined flow marks skipped steps', array_column($flow, 'state') === ['done', 'done', 'skipped', 'skipped', 'current']);
check('declined flow shows the reason', end($flow)['note'] === 'Outside office hours');

// exams and rubrics
check('shuffle keeps every item', (function () { $s = shuffle_secure([1, 2, 3, 4, 5]); sort($s); return $s === [1, 2, 3, 4, 5]; })());
$questions = [
    ['id' => 1, 'type' => 'MCQ', 'correct_index' => 1, 'points' => 10],
    ['id' => 2, 'type' => 'MCQ', 'correct_index' => 0, 'points' => 10],
    ['id' => 3, 'type' => 'ESSAY', 'correct_index' => null, 'points' => 20],
];
$score = score_attempt($questions, [1 => 1, 2 => 2, 3 => 'text']);
check('multiple choice is scored automatically', $score === ['mcq' => 10, 'total' => 40, 'has_essay' => true]);
check('the final score waits for essay marks', final_score(10, null, 40, true) === null);
check('the final score is a percentage', final_score(10, 15, 40, true) === 63);
check('the timer never runs past the exam window', attempt_deadline(1000, 60, 2800) === 2800);
check('a rubric that adds up to 100 is accepted', count(parse_rubric("Correctness | 60\nStyle | 40")) === 2);
check('a rubric that does not add up to 100 is rejected', throws(fn () => parse_rubric('Correctness | 60')));

// uploads
$zip = fn ($entry) => "PK\x03\x04....{$entry}document.xml";
check('PDFs are recognised by their first bytes', sniff_file('%PDF-1.7 ...') === 'pdf');
check('Word files are recognised', sniff_file($zip('word/')) === 'docx');
check('source code is recognised as text', sniff_file("print('hi')") === 'code');
check('a Windows program is not recognised', sniff_file("MZ\x00\x90") === null);
check('a renamed program is rejected', !check_upload('essay.pdf', "MZ\x00\x01", ['pdf'])['ok']);
check('a .exe file is rejected', !check_upload('tool.exe', 'text', ['pdf', 'code'])['ok']);
check('a real Word file is accepted', check_upload('report.docx', $zip('word/'), ['docx'])['ok']);
check('path tricks in stored names are blocked', throws(fn () => stored_path('../../.env')));
check('only 32-character hex names are allowed', str_ends_with(stored_path(str_repeat('a', 32)), str_repeat('a', 32)));
check('file names cannot add headers', !preg_match('/[\r\n]/', content_disposition("a\"\r\nSet-Cookie: x.pdf", false)));

// table and column names (database.php)
check('normal table and column names are allowed', sql_identifier('forum_votes') === 'forum_votes');
check('SQL in a table or column name is refused', throws(fn () => sql_identifier('users; DROP TABLE users')) && throws(fn () => sql_identifier('name = 1 --')));

// markdown
$html = markdown("**bold** <script>alert(1)</script> [x](javascript:alert(1)) [ok](https://example.com)");
check('markdown keeps formatting', str_contains($html, '<strong>bold</strong>'));
check('markdown shows HTML as text', !str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'));
check('markdown refuses javascript: links', !str_contains($html, 'href="javascript'));
check('markdown allows normal links', str_contains($html, 'href="https://example.com"'));
check('markdown escapes code', str_contains(markdown('`<b>`'), '<code>&lt;b&gt;</code>'));

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
