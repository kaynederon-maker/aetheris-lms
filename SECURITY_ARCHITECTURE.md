# Security architecture

This document explains how Aetheris handles logins, permissions, input, uploads and logging. The tests in `tests/` check most of it: `php tests/unit-tests.php`, and `php tests/e2e-tests.php` while the site is running.

## Logins and sessions

Passwords are stored with `password_hash()` using bcrypt at cost 12 (`actions/account.php`). A password needs at least 10 characters, with an upper-case letter, a lower-case letter and a number (`password_problem()` in `helpers/rules.php`).

Sessions are normal PHP sessions (`helpers/auth.php`). The session cookie is `HttpOnly`, `SameSite=Strict` and `Secure` when the site runs over HTTPS. PHP's strict mode is on, so the server ignores any session id it didn't create itself. After 8 idle hours a session is cleared. Signing in issues a new session id, which rules out session fixation.

Every request loads the user again. A session stops working straight away if the account was deactivated or the password was changed. Each user has a `session_version` number that is stored in the session; deactivating an account or changing its password raises the number, which makes every older session invalid.

Login attempts are limited three ways:

- 5 per 15 minutes for each account and IP address pair, so someone guessing from another network can't lock out the real owner
- 30 per 15 minutes for one account
- 100 per 15 minutes from one IP address, which is high on purpose because a whole campus can share one address

When the email doesn't exist, the server still checks the password against a dummy bcrypt hash. An unknown email and a wrong password therefore look the same, both in the message and in how long the answer takes.

The IP address comes from `REMOTE_ADDR`. `X-Forwarded-For` is only trusted when `TRUST_PROXY=1` is set, meaning the app runs behind your own reverse proxy, and then only the last hop (the one your proxy added) is used.

Lecturers can't sign themselves up. An admin creates the account, and the server generates a random 14-character temporary password that is shown once. Until the lecturer chooses their own password, every page redirects to the profile and every action except changing the password and signing out is refused.

The demo data script refuses to run when `APP_ENV=production`, because the demo passwords are in the README. In production the sign-in page also stops listing the demo accounts.

## Permissions

Every page starts with `require_login()` or `require_role(...)`, and every action starts with `action_user(...)`. Public sign-up always creates a student, whatever else is sent with the form.

`course_role()` in `helpers/auth.php` works out whether the user is enrolled in a course, teaches it, or is an admin. Every course page and action uses it. A user with no link to the course gets "not found" rather than "forbidden", so nobody can probe which ids exist.

Other records follow from that:

- Grades: the grades page only reads rows for the signed-in student and takes no id from the address bar.
- Submissions: a submitted file can be downloaded by the student who handed it in, the course lecturer or an admin (`actions/files.php`).
- Consultations: a request is visible to the student who asked, to the lecturer it was sent to while they still teach that course, and to admins for support-desk requests (`consultation_access()`). When an admin moves a course to a new lecturer, its open requests move too. The allowed steps are in `helpers/rules.php`, which checks the current status and who is acting.
- Exams: students never receive the correct answers. The server only accepts answers to questions in the student's own attempt, only until 5 seconds after their deadline, and a submitted attempt can't change.

## Input

Each action reads exactly the fields it needs through `text_input()`, `int_input()`, `choice_input()` and `datetime_input()` in `helpers/security.php`. These check the length, range or allowed values. Anything else in the request is ignored, so fields like `role` or `active` can't be slipped in.

Every database query uses prepared statements (`query()` in `helpers/database.php`), so a value can never change the SQL. Some inputs are forgiving where that's safe: join codes are trimmed, have dashes removed and are upper-cased before they are checked.

## HTML from users

Every value shown on a page goes through `e()`, which is `htmlspecialchars()`. Any HTML a user types in a chat message, a consultation request, feedback or a name appears as plain text. Chat messages added live by `app.js` are inserted with `textContent`, never `innerHTML`.

Markdown (materials, forum posts, announcements) is rendered by `helpers/markdown.php`. It escapes the whole text first and only then adds a few tags of its own. Links are only allowed to `https:`, `mailto:` or pages on this site, so `javascript:` links don't work.

## Headers and CSRF

`send_security_headers()` sends:

- a Content Security Policy that only allows scripts from this site (`script-src 'self'`, no inline scripts), plus `object-src 'none'`, `base-uri 'self'`, `form-action 'self'` and `frame-ancestors 'none'`
- `X-Frame-Options: DENY`, except for PDFs opened inside the site's own viewer, which use `SAMEORIGIN`. Those PDF responses also get `script-src 'none'`, so a PDF with JavaScript in it can't run anything.
- `insert()` and `update()` in `helpers/database.php` only accept plain table and column names (letters, digits and underscores), as a second line of defence behind prepared statements.
- `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, a `Permissions-Policy` that switches off the camera, microphone, location and payments, `Cross-Origin-Opener-Policy: same-origin`, and HSTS over HTTPS

Every form carries a CSRF token from the session, and `app.js` sends the same token in a header. `check_csrf()` compares them with `hash_equals()`. When the browser sends an `Origin` header, it also has to match the site, otherwise the request gets a 403.

## Uploads and downloads

The code is in `helpers/uploads.php` and `actions/files.php`.

1. Files are limited to 10 MB.
2. Each assignment lists the file types it accepts: PDF, DOCX, PPTX, ZIP, or source and text files.
3. The first bytes of the file have to match its extension:
   - a PDF starts with `%PDF-`
   - Office files and ZIPs start with a ZIP header, and Office files also need their `word/` or `ppt/` folder inside
   - source files have to be valid UTF-8 with no null bytes

   A renamed `.exe` is rejected and the attempt goes into the audit log.
4. The stored file type comes from what the bytes turned out to be, not from what the browser said.
5. Files are saved in `storage/uploads/` under 32 random hex characters, outside `public/`. The original name only lives in the database.
6. A download needs a signed-in user and passes the ownership checks above. The stored name has to be exactly 32 hex characters, which blocks `../` tricks. The download name is encoded so it can't add headers, and responses aren't cached. Everything downloads as an attachment, except checked PDFs, which can open in the page.
7. The deadline is checked with the server's clock when the upload arrives. The locked upload box on the page is only a convenience.
8. On Apache, the `.htaccess` file in the project root blocks every path outside `public/`. We tested this with Apache 2.4: `storage/`, `helpers/` and `database/` all return 403.

## Rate limits

The counters live in the `rate_limits` table, so they work across PHP processes.

| What | Limit |
|---|---|
| Sign-in | see above |
| Sign-up | 20 per hour per IP |
| Password change | 5 per 15 minutes |
| Join codes | 10 tries per 10 minutes |
| Assignment uploads | 20 per 10 minutes |
| Exam autosave | 150 per minute |
| Chat messages | 30 per minute |
| Forum | 10 new questions and 30 answers per hour |
| Consultation requests | 10 per hour |

If two first uploads for the same assignment arrive at the same moment, the database's unique key stops the second one and its file is deleted.

## Audit log

The `audit_log` table records each of the following, with who did it, what it was about, and their IP address:

- sign-ins, failed sign-ins and rate-limit hits
- sign-ups and password changes
- joining a class, and wrong join codes
- uploads, refused uploads and late upload attempts
- grading
- exam starts and submissions, including the tab-switch count
- every consultation step
- refused downloads
- every admin action

Admins read it on the Audit log page. If an entry can't be written, the error goes to PHP's error log and the user's action still goes through.

## Errors

Users see a short message, such as "Course not found." or "The deadline has passed." Unexpected errors are written to PHP's error log, and the user only sees "Something went wrong", with no file paths or stack traces.

## Known limits

- The chat checks for new messages every few seconds instead of using WebSockets.
- A student's reply to a request for more detail can only be text, which keeps file permissions simple.
- Uploads aren't scanned for viruses.
- Sign-up says whether the email or the student number is already taken. That's easier to use but reveals which ones exist; both checks are rate limited.
