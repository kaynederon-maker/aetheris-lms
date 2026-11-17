<?php
/**
 * UPLOADS: checking, saving and sending files.
 * Files are stored outside the public folder under random names, and are only sent out
 * after a permission check (see actions/files.php).
 */

const UPLOAD_DIR = ROOT . '/storage/uploads';
const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

const FILE_KINDS = [
    'pdf' => ['label' => 'PDF', 'exts' => ['pdf'], 'mime' => 'application/pdf'],
    'docx' => ['label' => 'Word', 'exts' => ['docx'], 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    'pptx' => ['label' => 'Slides', 'exts' => ['pptx'], 'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    'zip' => ['label' => 'ZIP', 'exts' => ['zip'], 'mime' => 'application/zip'],
    'code' => [
        'label' => 'Source / text',
        'exts' => ['py', 'js', 'ts', 'java', 'c', 'cpp', 'h', 'cs', 'go', 'rs', 'sql', 'kt', 'swift', 'rb', 'php', 'html', 'css', 'json', 'md', 'txt', 'ipynb'],
        'mime' => 'text/plain; charset=utf-8',
    ],
];

/** What the file really is, judged by its first bytes ("magic bytes"), not by its name. */
function sniff_file(string $bytes): ?string
{
    if (str_starts_with($bytes, '%PDF-')) {
        return 'pdf';
    }
    if (str_starts_with($bytes, "PK\x03\x04")) {
        return str_contains($bytes, 'word/') ? 'docx' : (str_contains($bytes, 'ppt/') ? 'pptx' : 'zip');
    }
    $head = substr($bytes, 0, 65536);
    if ($bytes !== '' && !str_contains($head, "\0")) {
        // Do not reject a valid file because the 64 KB window cut a multi-byte character in half.
        for ($trim = 0; $trim <= 3; $trim++) {
            if (mb_check_encoding(substr($head, 0, strlen($head) - $trim), 'UTF-8')) {
                return 'code';
            }
        }
    }
    return null;
}

/** ['ok' => true, 'kind' => ..., 'mime' => ...] or ['ok' => false, 'error' => ...] */
function check_upload(string $name, string $bytes, array $allowedKinds): array
{
    if ($bytes === '') {
        return ['ok' => false, 'error' => 'The file is empty.'];
    }
    if (strlen($bytes) > MAX_UPLOAD_BYTES) {
        return ['ok' => false, 'error' => 'Files are limited to 10 MB.'];
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $declared = null;
    foreach ($allowedKinds as $kind) {
        if (in_array($ext, FILE_KINDS[$kind]['exts'] ?? [], true)) {
            $declared = $kind;
            break;
        }
    }
    if (!$declared) {
        $list = implode(', ', array_map(fn ($k) => '.' . FILE_KINDS[$k]['exts'][0], $allowedKinds));
        return ['ok' => false, 'error' => "This upload accepts $list files."];
    }
    $actual = sniff_file($bytes);
    // .docx and .pptx files are zip files inside, so a "zip" upload accepts them too.
    $matches = $actual === $declared || ($declared === 'zip' && in_array($actual, ['docx', 'pptx'], true));
    if (!$matches) {
        return ['ok' => false, 'error' => "The file content does not match a .$ext file."];
    }
    return ['ok' => true, 'kind' => $declared, 'mime' => FILE_KINDS[$declared]['mime']];
}

/** Save the uploaded form field. Returns the new file id, or null when no file was chosen. */
function save_upload(string $field, array $allowedKinds, int $userId): ?int
{
    $upload = $_FILES[$field] ?? null;
    if (!$upload || $upload['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE || $upload['size'] > MAX_UPLOAD_BYTES) {
        fail('Files are limited to 10 MB.', 413);
    }
    if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        fail('The upload did not finish. Please try again.', 400);
    }
    $bytes = file_get_contents($upload['tmp_name']);
    $name = mb_substr(basename((string) $upload['name']), 0, 200);
    $check = check_upload($name, $bytes, $allowedKinds);
    if (!$check['ok']) {
        audit('upload_rejected', $name . ': ' . $check['error']);
        fail($check['error'], 422);
    }
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0775, true);
    }
    $stored = bin2hex(random_bytes(16));
    file_put_contents(stored_path($stored), $bytes, LOCK_EX);
    return insert('files', [
        'stored_name' => $stored, 'original_name' => $name, 'mime' => $check['mime'],
        'size' => strlen($bytes), 'uploaded_by' => $userId, 'created_at' => now(),
    ]);
}

/** Store bytes that did not come from a form (used by the demo data). */
function save_bytes(string $name, string $bytes, string $mime, int $userId): int
{
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0775, true);
    }
    $stored = bin2hex(random_bytes(16));
    file_put_contents(stored_path($stored), $bytes);
    return insert('files', ['stored_name' => $stored, 'original_name' => $name, 'mime' => $mime, 'size' => strlen($bytes), 'uploaded_by' => $userId, 'created_at' => now()]);
}

/** Only 32-character hex names inside the upload folder are allowed (blocks "../" path tricks). */
function stored_path(string $storedName): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $storedName)) {
        throw new InvalidArgumentException('Invalid stored file name');
    }
    return UPLOAD_DIR . DIRECTORY_SEPARATOR . $storedName;
}

function delete_file(int $fileId): void
{
    $file = one('SELECT * FROM files WHERE id = ?', [$fileId]);
    if ($file) {
        query('DELETE FROM files WHERE id = ?', [$fileId]);
        @unlink(stored_path($file['stored_name']));
    }
}

/** A Content-Disposition header that a crafted file name cannot break out of. */
function content_disposition(string $name, bool $inline): string
{
    $ascii = substr(preg_replace('/[^\w.\- ]+/', '_', $name), 0, 120) ?: 'download';
    return ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . "\"; filename*=UTF-8''" . rawurlencode($name);
}

function send_file(array $file, bool $inline): never
{
    $path = stored_path($file['stored_name']);
    $inline = $inline && $file['mime'] === 'application/pdf';
    send_security_headers($inline, $inline);
    header('Content-Type: ' . $file['mime']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . content_disposition($file['original_name'], $inline));
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}
