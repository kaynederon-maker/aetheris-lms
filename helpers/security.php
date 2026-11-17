<?php
/**
 * security.php
 *
 * Security: escaping output, CSRF tokens, security headers, reading form input safely,
 * flash messages and redirects, rate limits and the audit log.
 */

/** Thrown for anything the user should be told about (bad input, no permission, ...). */
class AppError extends Exception
{
    public function __construct(string $message, public int $status = 400)
    {
        parent::__construct($message);
    }
}

function fail(string $message, int $status = 400): never
{
    throw new AppError($message, $status);
}

/** Escape text before putting it in HTML. Use it for every value that came from a user or the database. */
function e(mixed $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

function send_security_headers(bool $allowFramingBySelf = false, bool $strictPdfSandbox = false): void
{
    if (headers_sent()) {
        return;
    }
    $frame = $allowFramingBySelf ? "'self'" : "'none'";
    $scriptSrc = $strictPdfSandbox ? "'none'" : "'self'";
    header("Content-Security-Policy: default-src 'self'; script-src $scriptSrc; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-src 'self'; "
        . "frame-ancestors $frame; object-src 'none'; base-uri 'self'; form-action 'self'");
    header('X-Frame-Options: ' . ($allowFramingBySelf ? 'SAMEORIGIN' : 'DENY'));
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=63072000; includeSubDomains');
    }
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

/** Every form and every fetch() must send the token, and must come from our own site. */
function check_csrf(): void
{
    $sent = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        fail('Your session expired. Reload the page and try again.', 403);
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if ($origin !== null) {
        $o = parse_url($origin);
        $hostPort = ($o['host'] ?? '') . (isset($o['port']) ? ':' . $o['port'] : '');
        if ($hostPort !== ($_SERVER['HTTP_HOST'] ?? '')) {
            fail('Cross-site request blocked.', 403);
        }
    }
}

/* ---------- reading input ---------- */

/** A value from the submitted form, or from a JSON body sent by app.js. */
function input(string $key): mixed
{
    static $json = null;
    if ($json === null) {
        $json = [];
        if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            $json = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
        }
    }
    return $_POST[$key] ?? $json[$key] ?? null;
}

/**
 * Plain text: invisible control characters are removed. HTML is NOT stripped here (that would eat
 * text like "a<b"); instead every value is escaped with e() when it is shown, so tags display as text.
 */
function clean_text(string $text): string
{
    return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '');
}

function text_input(string $key, string $label, int $min, int $max, bool $plain = true): string
{
    $value = input($key);
    $value = is_string($value) ? ($plain ? clean_text($value) : trim($value)) : '';
    $length = mb_strlen($value);
    if ($length < $min) {
        fail($min <= 1 ? "$label is required." : "$label needs at least $min characters.", 422);
    }
    if ($length > $max) {
        fail("$label can be at most $max characters.", 422);
    }
    return $value;
}

function int_input(string $key, string $label, int $min, int $max): int
{
    $value = input($key);
    if (!is_numeric($value) || (int) $value != $value || $value < $min || $value > $max) {
        fail("$label must be a whole number from $min to $max.", 422);
    }
    return (int) $value;
}

function choice_input(string $key, string $label, array $allowed): string
{
    $value = input($key);
    if (!is_string($value) || !in_array($value, $allowed, true)) {
        fail("Choose a valid $label.", 422);
    }
    return $value;
}

/** A date from <input type="datetime-local"> (Jakarta time), returned in UTC for storage. */
function datetime_input(string $key, string $label, bool $required = true): ?string
{
    $value = input($key);
    if (!is_string($value) || trim($value) === '') {
        if ($required) {
            fail("$label is required.", 422);
        }
        return null;
    }
    $date = DateTime::createFromFormat('Y-m-d\TH:i', $value, new DateTimeZone(APP_TIMEZONE));
    if (!$date) {
        fail("$label is not a valid date and time.", 422);
    }
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/* ---------- responses ---------- */

/** Requests made by app.js (fetch) get JSON back; normal form posts get a redirect. */
function wants_json(): bool
{
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
}

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

/** Where a form came from. Only our own query strings are accepted (no redirects to other sites). */
function back_url(): string
{
    $back = $_POST['back'] ?? '';
    return is_string($back) && $back !== '' && preg_match('/^[\w\-=&%.]+$/', $back) ? 'index.php?' . $back : 'index.php';
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [$type, $message];
}

function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

/** Finish an action: show a message and go back (or return JSON to app.js). */
function done(string $message, ?string $to = null, array $data = []): never
{
    if (wants_json()) {
        json_out(['ok' => true, 'message' => $message] + $data);
    }
    flash('ok', $message);
    redirect($to ?? back_url());
}

/** Keep what the user typed (never passwords) so a form with an error is not emptied. */
function remember_input(): void
{
    $_SESSION['old'] = array_diff_key($_POST, array_flip(['csrf', 'password', 'current_password', 'new_password']));
}

function old(string $key, string $default = ''): string
{
    static $old = null;
    if ($old === null) {
        $old = $_SESSION['old'] ?? [];
        unset($_SESSION['old']);
    }
    return is_string($old[$key] ?? null) ? $old[$key] : $default;
}

/* ---------- rate limits & audit log ---------- */

/** True while under the limit. Counters live in the database so they work across PHP processes. */
function rate_limit(string $key, int $limit, int $windowSeconds): bool
{
    $now = time();
    $row = one('SELECT count, reset_at FROM rate_limits WHERE key = ?', [$key]);
    if (!$row || $row['reset_at'] <= $now) {
        query('INSERT INTO rate_limits (key, count, reset_at) VALUES (?, 1, ?)
               ON CONFLICT (key) DO UPDATE SET count = 1, reset_at = excluded.reset_at', [$key, $now + $windowSeconds]);
        return true;
    }
    query('UPDATE rate_limits SET count = count + 1 WHERE key = ?', [$key]);
    return $row['count'] + 1 <= $limit;
}

function limit_or_fail(string $key, int $limit, int $windowSeconds, string $message): void
{
    if (!rate_limit($key, $limit, $windowSeconds)) {
        audit('rate_limited', $key);
        fail($message, 429);
    }
}

function clear_rate_limit(string $key): void
{
    query('DELETE FROM rate_limits WHERE key = ?', [$key]);
}

/** Behind your own reverse proxy set TRUST_PROXY=1; otherwise the visitor's real address is used. */
function client_ip(): string
{
    if (getenv('TRUST_PROXY') === '1' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $hops = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim(end($hops));
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function audit(string $action, ?string $target = null, ?int $actorId = null): void
{
    try {
        insert('audit_log', [
            'actor_id' => $actorId ?? (current_user()['id'] ?? null),
            'action' => $action,
            'target' => $target === null ? null : mb_substr($target, 0, 200),
            'ip' => client_ip(),
            'created_at' => now(),
        ]);
    } catch (Throwable $err) {
        error_log('[audit] could not write ' . $action . ': ' . $err->getMessage());
    }
}
