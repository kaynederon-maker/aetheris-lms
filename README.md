# Aetheris LMS

Aetheris is a learning management system for a university setting. Students join classes, read course materials, hand in assignments and take timed exams. Lecturers run their courses and grade work, and admins manage accounts and classes. The visual style borrows from Apple's interface guidelines, and the server checks permissions on every request.

It's written in plain PHP with an SQLite database: no framework, no Composer packages and no JavaScript build step. If you have PHP 8.1 or newer, you can run it.

## Team

| Name | Student ID |
|---|---|
| KAYNE KUSNADI | 2802405150 |
| NADYA ROSELANI BRAMANJAYA | 2802403605 |
| SHINJI PRUDENT ZHANG | 2802402104 |
| JOSHUA NATHANAEL TANADI | 2802396972 |

Our names are also in the site footer and in the About & Creators window.

## Running it

You need PHP 8.1+ with the `pdo_sqlite` and `fileinfo` extensions. XAMPP includes both.

```bash
git clone https://github.com/kaynederon-maker/aetheris-lms.git
cd aetheris-lms
php database/seed.php              # creates the database with demo data
php -S 127.0.0.1:8000 -t public    # starts a local web server
```

Then open http://127.0.0.1:8000.

To use XAMPP instead, copy the folder into `htdocs` and open http://localhost/aetheris-lms/public/. The `.htaccess` file in the project root blocks everything outside `public/`, so the database and uploads can't be downloaded even though the whole folder is inside `htdocs`.

To start over with fresh demo data at any time, run `php database/seed.php` again.

## How the folders are organised

Each folder is named after what its files do:

| Folder | What's inside |
|---|---|
| `public/` | The only folder a browser can reach: `index.php`, `style.css`, `app.js` and the images. |
| `pages/` | One file per screen, such as `dashboard.php`, `course.php` and `exam.php`. Each one checks who is signed in and then prints the HTML. |
| `actions/` | What happens when a form is sent. For example, `assignments.php` has `action_submit_assignment()`, which saves an uploaded file. |
| `helpers/` | Shared building blocks: the database, login and permissions, security, uploads, the page layout, Markdown, icons and the app's rules. |
| `database/` | `schema.sql` (the tables) and `seed.php` (the demo data). |
| `tests/` | `unit-tests.php` and `e2e-tests.php`. |
| `storage/` | Created when the app first runs. It holds the SQLite file and uploaded files, and it isn't in Git. |

How a request flows: every visit goes to `public/index.php`. That file loads the helpers, then either shows a page from `pages/` (`index.php?page=courses`) or runs a function from `actions/` when a form is posted. Only names on the two lists at the top of `index.php` can be loaded.

Where to look for something:

- Changing how a screen looks: the file in `pages/`, plus `public/style.css`.
- Changing what a button does: search `actions/` for `action_` followed by the action name.
- Changing a rule (password policy, consultation steps, exam scoring, rubric format): `helpers/rules.php`.
- Changing who can see what: `helpers/auth.php`.

## Demo accounts

These passwords are public, so only use them for local testing. The seed script refuses to run when the `APP_ENV` environment variable is set to `production`.

| Role | Email | Password |
|---|---|---|
| Admin | admin@aetheris.edu | `Admin#Aetheris2026` |
| Lecturer | rina.anggraini@aetheris.edu | `Lecturer#2026` |
| Lecturer | budi.santoso@aetheris.edu | `Lecturer#2026` |
| Student | kayne@student.aetheris.edu | `Student#2026` |
| Student | nadya@student.aetheris.edu | `Student#2026` |
| Student | shinji@student.aetheris.edu | `Student#2026` |
| Student | joshua@student.aetheris.edu | `Student#2026` |

Join codes: COMP6100 is `DSTR-7Q4K`, MATH6025 is `DMTH-3X9P` and ISYS6197 is `SECU-8R2M`. Joshua isn't in COMP6100, so sign in as him and try joining with `dstr 7q4k`. The form accepts spaces, dashes and lowercase.

The demo data includes:

- Markdown notes and PDF slides
- one open assignment, and a past-due one that has already been graded with a rubric
- an open exam with multiple-choice and essay questions
- forum threads, one with a verified answer
- class chat messages
- consultation requests in four states: delivered, under review, approved and declined

## What it does

### Students

Students register themselves. The password needs 10 characters with an upper-case letter, a lower-case letter and a number. They join classes with a code from their lecturer.

Materials open in the browser: Markdown notes render on the page and PDFs show in a viewer, and every file can be downloaded. Assignments accept PDF, Word, ZIP or source files and show a countdown. Once the deadline passes, the server stops accepting uploads.

Exams start whenever the student is ready, within the exam window. Answers save as you type, every student gets the questions in a different order, switching tabs is counted, and the exam submits itself when the timer reaches `00:00`.

Students only ever see their own grades, rubric scores and feedback. On the profile page they can change their name, avatar, theme and password.

### Lecturers

Lecturer accounts come from the admin; lecturers can't sign themselves up. For each course, a lecturer can see the roster and join code, add, reorder and delete materials, and post announcements.

They create assignments with allowed file types and an optional rubric, and write exams with multiple-choice and essay questions. The grading page lists who has handed in and who hasn't, with downloads, rubric scoring and feedback. Lecturers also mark essays, mark forum answers as verified, and reply to consultation requests.

### Admins

Admins create lecturer accounts. Each one gets a temporary password that is shown once and has to be changed at first login. Admins can deactivate any account, which signs it out everywhere straight away. They also create classes, assign or swap lecturers, and generate new join codes.

The overview page shows totals for classes, lecturers and students, the submission rate, open consultation requests and the latest security events.

### Consultations

A student can ask their course lecturer, or the academic and technical support desk, for a video call, voice call or chat. Each request has a progress chart with a time for every step:

```
DELIVERED → READ → UNDER_REVIEW → (REVISION_REQUESTED ⇄ student reply) → APPROVED_SCHEDULED or DECLINED → COMPLETED
```

A request is marked as read when the lecturer or support staff opens it. Approving one means giving a date, time and meeting link. Declining one requires a written reason, and the student sees it on the chart. The allowed steps live in `helpers/rules.php`, and the server refuses anything else.

### Forum, chat and calendar

Each course has a forum where answers can be upvoted and the lecturer can mark one as verified. There's also a group chat: your message shows up right away, and the page checks for new messages every 3 seconds. The calendar puts deadlines, exam windows and confirmed consultations together. It shows a month grid on a computer and a list on a phone.

## Design

There are five themes: Apple Light, Apple Dark, Earth Tone, Academic Burgundy and Nordic Slate. You can switch from the top bar and the choice is saved to your profile. Each theme has one accent colour, and everything else is neutral.

All colours, spacing and corner sizes are CSS variables at the top of `public/style.css`. Nested corners follow inner radius = outer radius minus the gap, so a 20px card with an 8px gap holds a 12px panel.

Headings use Geist and all other text uses Geist Mono, both loaded from Google Fonts. Every changing number uses fixed-width digits.

Buttons and inputs are at least 44px tall, inputs use 16px text on phones so iOS doesn't zoom in, and animations switch off for people who have reduced motion turned on. Without the banner and avatar images the app falls back to simple shapes and initials. Icons are Lucide icons, copied into `helpers/icons.php`.

## Security

[SECURITY_ARCHITECTURE.md](SECURITY_ARCHITECTURE.md) has the details. In short:

- bcrypt password hashes (cost 12)
- session cookies set to `HttpOnly` and `SameSite=Strict`
- a CSRF token on every form
- rate limits stored in the database
- role and ownership checks on every page and action
- prepared statements for every query
- every value escaped when shown
- a strict Content Security Policy
- uploads checked by their actual bytes and stored outside `public/` under random names

## Tests

```bash
php tests/unit-tests.php                 # 38 checks, no server needed
php database/seed.php
php -S 127.0.0.1:8000 -t public          # in a second terminal
php tests/e2e-tests.php                  # 63 checks against the running site
php database/seed.php                    # put the demo data back afterwards
```

The unit tests cover the consultation steps, file type detection, path tricks, passwords, exam scoring, rubrics and the Markdown renderer.

The end-to-end tests sign in as each role and try the things that should fail:

- reading another student's file or request
- uploading after the deadline, or uploading a renamed program
- forms sent from another site or without the CSRF token
- making yourself an admin through the sign-up form
- skipping consultation steps
- changing exam answers after submitting
- HTML in the forum or chat
- using a deactivated account
- using a temporary password without changing it first

## License

MIT, see [LICENSE](LICENSE).
