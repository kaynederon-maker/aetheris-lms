-- Aetheris database structure (SQLite).
-- Runs automatically the first time the app opens the database (see helpers/database.php).
-- All dates are stored in UTC as 'YYYY-MM-DD HH:MM:SS' and shown in Asia/Jakarta time.

PRAGMA foreign_keys = ON;

CREATE TABLE users (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    email                TEXT NOT NULL UNIQUE,
    name                 TEXT NOT NULL,
    password_hash        TEXT NOT NULL,
    role                 TEXT NOT NULL CHECK (role IN ('STUDENT', 'LECTURER', 'ADMIN')),
    student_number       TEXT,
    active               INTEGER NOT NULL DEFAULT 1,
    must_change_password INTEGER NOT NULL DEFAULT 0,
    theme                TEXT NOT NULL DEFAULT 'apple-light',
    avatar               TEXT,                       -- a1..a6, or NULL for initials
    session_version      INTEGER NOT NULL DEFAULT 1, -- bumping it logs the user out everywhere
    created_at           TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE courses (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    code        TEXT NOT NULL UNIQUE,                 -- e.g. COMP6100
    title       TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    join_code   TEXT NOT NULL UNIQUE,                 -- 8 characters, shown as ABCD-2345
    banner      TEXT,                                 -- file name in public/images/banners
    lecturer_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE enrollments (
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    course_id  INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, course_id)
);

-- Every uploaded file. The real file lives in storage/uploads/<stored_name>.
CREATE TABLE files (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    stored_name   TEXT NOT NULL UNIQUE,               -- 32 random hex characters
    original_name TEXT NOT NULL,
    mime          TEXT NOT NULL,
    size          INTEGER NOT NULL,
    uploaded_by   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE materials (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    course_id  INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
    title      TEXT NOT NULL,
    body       TEXT,                                  -- Markdown notes
    file_id    INTEGER REFERENCES files(id) ON DELETE SET NULL,
    position   INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE announcements (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    course_id  INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE assignments (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    course_id     INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
    title         TEXT NOT NULL,
    description   TEXT NOT NULL,
    due_at        TEXT NOT NULL,
    allowed_types TEXT NOT NULL,                      -- e.g. 'pdf,docx,zip,code'
    rubric        TEXT NOT NULL DEFAULT '[]',         -- JSON: [{"criterion":"Correctness","max":50}, ...]
    created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE submissions (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    assignment_id INTEGER NOT NULL REFERENCES assignments(id) ON DELETE CASCADE,
    student_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    file_id       INTEGER NOT NULL REFERENCES files(id),
    submitted_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    score         INTEGER,                            -- 0-100, NULL until graded
    rubric_scores TEXT,                               -- JSON list matching the rubric
    feedback      TEXT,
    graded_at     TEXT,
    UNIQUE (assignment_id, student_id)
);

CREATE TABLE exams (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    course_id    INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
    title        TEXT NOT NULL,
    description  TEXT NOT NULL DEFAULT '',
    opens_at     TEXT NOT NULL,
    closes_at    TEXT NOT NULL,
    duration_min INTEGER NOT NULL,
    created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE questions (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    exam_id       INTEGER NOT NULL REFERENCES exams(id) ON DELETE CASCADE,
    type          TEXT NOT NULL CHECK (type IN ('MCQ', 'ESSAY')),
    prompt        TEXT NOT NULL,
    options       TEXT NOT NULL DEFAULT '[]',         -- JSON list of choices (MCQ only)
    correct_index INTEGER,                            -- never sent to students
    points        INTEGER NOT NULL,
    position      INTEGER NOT NULL
);

CREATE TABLE exam_attempts (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    exam_id        INTEGER NOT NULL REFERENCES exams(id) ON DELETE CASCADE,
    student_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    question_order TEXT NOT NULL,                     -- JSON list of question ids, shuffled per student
    answers        TEXT NOT NULL DEFAULT '{}',        -- JSON: {"questionId": answer}
    started_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deadline_at    TEXT NOT NULL,
    submitted_at   TEXT,
    auto_submitted INTEGER NOT NULL DEFAULT 0,
    tab_switches   INTEGER NOT NULL DEFAULT 0,
    mcq_points     INTEGER,
    essay_points   INTEGER,
    score          INTEGER,                           -- 0-100, NULL while essays wait for grading
    feedback       TEXT,
    UNIQUE (exam_id, student_id)
);

CREATE TABLE forum_threads (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    course_id  INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
    author_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE forum_posts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    thread_id  INTEGER NOT NULL REFERENCES forum_threads(id) ON DELETE CASCADE,
    author_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body       TEXT NOT NULL,
    verified   INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE forum_votes (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    post_id INTEGER NOT NULL REFERENCES forum_posts(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, post_id)
);

CREATE TABLE chat_messages (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    course_id  INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
    author_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    body       TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX chat_by_course ON chat_messages (course_id, id);

CREATE TABLE consultations (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    recipient_type TEXT NOT NULL CHECK (recipient_type IN ('LECTURER', 'SUPPORT')),
    recipient_id   INTEGER REFERENCES users(id) ON DELETE SET NULL, -- NULL = support desk (admins)
    course_id      INTEGER REFERENCES courses(id) ON DELETE SET NULL,
    category       TEXT NOT NULL,
    medium         TEXT NOT NULL CHECK (medium IN ('VIDEO', 'VOICE', 'CHAT')),
    subject        TEXT NOT NULL,
    details        TEXT NOT NULL,
    preferred_at   TEXT,
    status         TEXT NOT NULL DEFAULT 'DELIVERED',
    scheduled_at   TEXT,
    session_link   TEXT,
    decline_reason TEXT,
    summary        TEXT,
    created_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- One row per step a consultation goes through, so the progress chart can show timestamps.
CREATE TABLE consultation_events (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    consultation_id INTEGER NOT NULL REFERENCES consultations(id) ON DELETE CASCADE,
    status          TEXT NOT NULL,
    note            TEXT,
    actor_id        INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE audit_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id   INTEGER REFERENCES users(id) ON DELETE SET NULL,
    action     TEXT NOT NULL,
    target     TEXT,
    ip         TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Counters for rate limiting (login attempts, uploads, chat, ...).
CREATE TABLE rate_limits (
    key      TEXT PRIMARY KEY,
    count    INTEGER NOT NULL,
    reset_at INTEGER NOT NULL                          -- Unix timestamp
);
