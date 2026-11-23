-- Aetheris database structure (MySQL 8 / MariaDB 10.4+).
-- Loaded automatically: by the MySQL container on its first start (docker-compose.yml), or by
-- helpers/database.php the first time the app finds no tables. database/seed.php also reloads it.
-- All dates are stored in UTC as 'YYYY-MM-DD HH:MM:SS' and shown in Asia/Jakarta time.

CREATE TABLE users (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    email                VARCHAR(255) NOT NULL UNIQUE,
    name                 VARCHAR(255) NOT NULL,
    password_hash        VARCHAR(255) NOT NULL,
    role                 VARCHAR(10) NOT NULL CHECK (role IN ('STUDENT', 'LECTURER', 'ADMIN')),
    student_number       VARCHAR(20),
    active               TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    theme                VARCHAR(20) NOT NULL DEFAULT 'apple-light',
    avatar               VARCHAR(10),                -- a1..a6, or NULL for initials
    session_version      INT NOT NULL DEFAULT 1,     -- raising it logs the user out everywhere
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE courses (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(8) NOT NULL UNIQUE,          -- e.g. COMP6100
    title       VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    join_code   VARCHAR(8) NOT NULL UNIQUE,          -- 8 characters, shown as ABCD-2345
    banner      VARCHAR(100),                        -- file name in public/images/banners
    lecturer_id INT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lecturer_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE enrollments (
    user_id    INT NOT NULL,
    course_id  INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, course_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every uploaded file. The real file lives in storage/uploads/<stored_name>.
CREATE TABLE files (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    stored_name   VARCHAR(32) NOT NULL UNIQUE,       -- 32 random hex characters
    original_name VARCHAR(255) NOT NULL,
    mime          VARCHAR(120) NOT NULL,
    size          INT NOT NULL,
    uploaded_by   INT NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE materials (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    course_id  INT NOT NULL,
    title      VARCHAR(255) NOT NULL,
    body       MEDIUMTEXT,                           -- Markdown notes
    file_id    INT NULL,
    position   INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE announcements (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    course_id  INT NOT NULL,
    title      VARCHAR(255) NOT NULL,
    body       TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE assignments (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    course_id     INT NOT NULL,
    title         VARCHAR(255) NOT NULL,
    description   TEXT NOT NULL,
    due_at        DATETIME NOT NULL,
    allowed_types VARCHAR(100) NOT NULL,             -- e.g. 'pdf,docx,zip,code'
    rubric        TEXT NOT NULL DEFAULT ('[]'),      -- JSON: [{"criterion":"Correctness","max":50}, ...]
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE submissions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT NOT NULL,
    student_id    INT NOT NULL,
    file_id       INT NOT NULL,
    submitted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    score         INT NULL,                          -- 0-100, NULL until graded
    rubric_scores TEXT,                              -- JSON list matching the rubric
    feedback      TEXT,
    graded_at     DATETIME NULL,
    UNIQUE KEY one_per_student (assignment_id, student_id),
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (file_id) REFERENCES files(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE exams (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    course_id    INT NOT NULL,
    title        VARCHAR(255) NOT NULL,
    description  TEXT NOT NULL,
    opens_at     DATETIME NOT NULL,
    closes_at    DATETIME NOT NULL,
    duration_min INT NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE questions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    exam_id       INT NOT NULL,
    type          VARCHAR(5) NOT NULL CHECK (type IN ('MCQ', 'ESSAY')),
    prompt        TEXT NOT NULL,
    options       TEXT NOT NULL DEFAULT ('[]'),      -- JSON list of choices (MCQ only)
    correct_index INT NULL,                          -- never sent to students
    points        INT NOT NULL,
    position      INT NOT NULL,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE exam_attempts (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    exam_id        INT NOT NULL,
    student_id     INT NOT NULL,
    question_order TEXT NOT NULL,                    -- JSON list of question ids, shuffled per student
    answers        MEDIUMTEXT NOT NULL DEFAULT ('{}'), -- JSON: {"questionId": answer}
    started_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deadline_at    DATETIME NOT NULL,
    submitted_at   DATETIME NULL,
    auto_submitted TINYINT(1) NOT NULL DEFAULT 0,
    tab_switches   INT NOT NULL DEFAULT 0,
    mcq_points     INT NULL,
    essay_points   INT NULL,
    score          INT NULL,                         -- 0-100, NULL while essays wait for marking
    feedback       TEXT,
    UNIQUE KEY one_attempt (exam_id, student_id),
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE forum_threads (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    course_id  INT NOT NULL,
    author_id  INT NOT NULL,
    title      VARCHAR(255) NOT NULL,
    body       TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE forum_posts (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    thread_id  INT NOT NULL,
    author_id  INT NOT NULL,
    body       TEXT NOT NULL,
    verified   TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (thread_id) REFERENCES forum_threads(id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE forum_votes (
    user_id INT NOT NULL,
    post_id INT NOT NULL,
    PRIMARY KEY (user_id, post_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (post_id) REFERENCES forum_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE chat_messages (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    course_id  INT NOT NULL,
    author_id  INT NOT NULL,
    body       TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY chat_by_course (course_id, id),
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE consultations (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    student_id     INT NOT NULL,
    recipient_type VARCHAR(10) NOT NULL CHECK (recipient_type IN ('LECTURER', 'SUPPORT')),
    recipient_id   INT NULL,                         -- NULL = support desk (admins)
    course_id      INT NULL,
    category       VARCHAR(40) NOT NULL,
    medium         VARCHAR(10) NOT NULL CHECK (medium IN ('VIDEO', 'VOICE', 'CHAT')),
    subject        VARCHAR(255) NOT NULL,
    details        TEXT NOT NULL,
    preferred_at   DATETIME NULL,
    status         VARCHAR(30) NOT NULL DEFAULT 'DELIVERED',
    scheduled_at   DATETIME NULL,
    session_link   VARCHAR(300),
    decline_reason TEXT,
    summary        TEXT,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per step a consultation goes through, so the progress chart can show times.
CREATE TABLE consultation_events (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    consultation_id INT NOT NULL,
    status          VARCHAR(30) NOT NULL,
    note            TEXT,
    actor_id        INT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE CASCADE,
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    actor_id   INT NULL,
    action     VARCHAR(60) NOT NULL,
    target     VARCHAR(255),
    ip         VARCHAR(45),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Counters for rate limiting (sign-in attempts, uploads, chat, ...).
CREATE TABLE rate_limits (
    rate_key VARCHAR(500) PRIMARY KEY,               -- e.g. "login:email:ip"
    count    INT NOT NULL,
    reset_at INT NOT NULL                            -- Unix timestamp
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
