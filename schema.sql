-- ============================================================
-- Alumni Tracing System – SQL Schema
-- Database: MySQL 8.x / MariaDB 10.6+
-- Generated from Doctrine ORM entities
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. USER (Admin / Staff / Student accounts)
-- ------------------------------------------------------------
-- Roles are stored as integers: 1 = Admin, 2 = Staff, 3 = Student.

CREATE TABLE IF NOT EXISTS `user` (
    id                INT           AUTO_INCREMENT PRIMARY KEY,
    email             VARCHAR(180)  NOT NULL,
    first_name        VARCHAR(255)  NOT NULL,
    last_name         VARCHAR(255)  NOT NULL,
    username          VARCHAR(80)   NULL,
    phone_number      VARCHAR(50)   NULL,
    bio               LONGTEXT      NULL,
    school_id         VARCHAR(50)   NULL,
    role              SMALLINT      NOT NULL  DEFAULT 3 COMMENT '1=Admin | 2=Staff | 3=Student',
    password          VARCHAR(255)  NOT NULL,
    account_status    VARCHAR(50)   NOT NULL  DEFAULT 'pending' COMMENT 'pending | active | inactive',
    date_registered   DATETIME      NOT NULL  DEFAULT CURRENT_TIMESTAMP,
    last_login        DATETIME      NULL,
    last_activity     DATETIME      NULL,
    profile_image     VARCHAR(255)  NULL,

    CONSTRAINT UNIQ_IDENTIFIER_EMAIL UNIQUE (email),
    CONSTRAINT UNIQ_IDENTIFIER_USERNAME UNIQUE (username),
    CONSTRAINT UNIQ_USER_SCHOOL_ID UNIQUE (school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 1B. SYSTEM SETTINGS
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS system_setting (
    id        INT          AUTO_INCREMENT PRIMARY KEY,
    key_name  VARCHAR(120) NOT NULL,
    value     LONGTEXT     NULL,

    CONSTRAINT UNIQ_SYSTEM_SETTING_KEY_NAME UNIQUE (key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_setting (key_name, value)
VALUES ('public_signup_enabled', '1')
ON DUPLICATE KEY UPDATE value = value;


-- ------------------------------------------------------------
-- 2. ALUMNI (Profile & academic information)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS alumni (
    id                       INT           AUTO_INCREMENT PRIMARY KEY,

    -- ── Personal Information ──
    student_number           VARCHAR(100)  NOT NULL,
    first_name               VARCHAR(255)  NOT NULL,
    middle_name              VARCHAR(255)  NULL,
    last_name                VARCHAR(255)  NOT NULL,
    suffix                   VARCHAR(20)   NULL,
    sex                      VARCHAR(10)   NULL,
    date_of_birth            DATE          NULL,
    civil_status             VARCHAR(50)   NULL,
    contact_number           VARCHAR(50)   NULL,
    email_address            VARCHAR(180)  NOT NULL,
    home_address             VARCHAR(500)  NULL,
    province                 VARCHAR(255)  NULL,

    -- ── Batch / Graduation ──
    year_graduated           INT           NULL     COMMENT 'Batch year, e.g. 2024',
    course                   VARCHAR(255)  NULL     COMMENT 'Short course code, e.g. BSIT',
    college                  VARCHAR(255)  NULL     COMMENT 'College / department',
    honors_received          VARCHAR(255)  NULL,

    -- ── Academic / Degree Information ──
    degree_program           VARCHAR(255)  NULL     COMMENT 'Full degree name, e.g. BS Information Technology',
    major                    VARCHAR(255)  NULL,
    date_graduated           DATE          NULL,
    latin_honor              VARCHAR(100)  NULL     COMMENT 'Summa / Magna / Cum Laude',
    gwa                      VARCHAR(20)   NULL,
    scholarship_granted      VARCHAR(255)  NULL,

    -- ── Current Employment Snapshot ──
    employment_status        VARCHAR(100)  NULL     COMMENT 'Employed | Unemployed | Self-employed | Freelance',
    employment_type          VARCHAR(100)  NULL     COMMENT 'Full-time | Part-time | Contractual',
    company_name             VARCHAR(255)  NULL,
    job_title                VARCHAR(255)  NULL,
    job_level                VARCHAR(100)  NULL     COMMENT 'Entry | Mid | Senior | Managerial',
    industry                 VARCHAR(255)  NULL,
    company_address          VARCHAR(500)  NULL,
    date_hired               DATE          NULL,
    monthly_salary           VARCHAR(100)  NULL     COMMENT 'Salary range string, e.g. 20000-30000',
    is_first_job             TINYINT(1)    NULL,
    years_in_company         INT           NULL,
    work_abroad              TINYINT(1)    NULL,
    country_of_employment    VARCHAR(255)  NULL,

    -- ── Career Tracking ──
    job_related_to_course    TINYINT(1)    NULL     COMMENT 'Is current job relevant to degree?',
    promotion_received       TINYINT(1)    NULL,
    date_promoted            DATE          NULL,
    skills_used_in_job       TEXT          NULL,
    trainings_attended       TEXT          NULL,
    licenses_obtained        TEXT          NULL,
    certifications           TEXT          NULL,
    career_achievements      TEXT          NULL,

    -- ── Further Studies ──
    further_studies           TINYINT(1)   NULL,
    postgraduate_degree       VARCHAR(255) NULL,
    school_for_further_studies VARCHAR(255) NULL,

    -- ── Feedback & University Contribution ──
    recommend_norsu          TINYINT(1)    NULL,
    suggestions_for_university TEXT        NULL,
    willing_for_seminar      TINYINT(1)    NULL,
    willing_for_donation     TINYINT(1)    NULL,
    willing_for_mentorship   TINYINT(1)    NULL,

    -- ── FK to User ──
    user_id                  INT           NULL,

    CONSTRAINT UNIQ_STUDENT_NUMBER  UNIQUE (student_number),
    CONSTRAINT UNIQ_EMAIL_ADDRESS   UNIQUE (email_address),
    CONSTRAINT FK_ALUMNI_USER       FOREIGN KEY (user_id)
                                    REFERENCES `user` (id)
                                    ON DELETE SET NULL
                                    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IDX_ALUMNI_YEAR       ON alumni (year_graduated);
CREATE INDEX IDX_ALUMNI_COURSE     ON alumni (course);
CREATE INDEX IDX_ALUMNI_COLLEGE    ON alumni (college);
CREATE INDEX IDX_ALUMNI_EMP_STATUS ON alumni (employment_status);


-- ------------------------------------------------------------
-- 3. EMPLOYMENT (Employment History – multiple per alumni)
-- ------------------------------------------------------------
-- Each row is one job held by an alumnus.
-- The FK alumni_id links back to the alumni table.

CREATE TABLE IF NOT EXISTS employment (
    id                   INT           AUTO_INCREMENT PRIMARY KEY,

    -- ── FK to Alumni ──
    alumni_id            INT           NOT NULL,

    -- ── Job Details ──
    company_name         VARCHAR(255)  NULL,
    job_title            VARCHAR(255)  NULL,
    employment_status    VARCHAR(100)  NULL     COMMENT 'Employed | Self-employed | Freelance',
    employment_type      VARCHAR(100)  NULL     COMMENT 'Full-time | Part-time | Contractual',
    monthly_salary       VARCHAR(100)  NULL     COMMENT 'Salary range, e.g. 15000-25000',
    date_hired           DATE          NULL,
    date_ended           DATE          NULL     COMMENT 'NULL = still employed',
    work_location        VARCHAR(500)  NULL,
    industry             VARCHAR(255)  NULL,
    job_level            VARCHAR(100)  NULL     COMMENT 'Entry | Mid | Senior | Managerial',

    -- ── Degree Relevance ──
    job_related_to_course TINYINT(1)   NULL     COMMENT 'Is this job relevant to the alumni degree?',

    -- ── Abroad Employment ──
    is_abroad            TINYINT(1)    NULL,
    country              VARCHAR(255)  NULL,

    CONSTRAINT FK_EMPLOYMENT_ALUMNI  FOREIGN KEY (alumni_id)
                                     REFERENCES alumni (id)
                                     ON DELETE CASCADE
                                     ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IDX_EMP_ALUMNI     ON employment (alumni_id);
CREATE INDEX IDX_EMP_COMPANY    ON employment (company_name);
CREATE INDEX IDX_EMP_INDUSTRY   ON employment (industry);
CREATE INDEX IDX_EMP_RELATED    ON employment (job_related_to_course);


-- ------------------------------------------------------------
-- 4. EDUCATION (Additional education records per alumni)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS education (
    id                INT           AUTO_INCREMENT PRIMARY KEY,
    alumni_id         INT           NOT NULL,
    school_name       VARCHAR(255)  NULL,
    degree            VARCHAR(255)  NULL,
    field_of_study    VARCHAR(255)  NULL,
    date_started      DATE          NULL,
    date_ended        DATE          NULL,
    is_completed      TINYINT(1)    NULL,

    CONSTRAINT FK_EDUCATION_ALUMNI   FOREIGN KEY (alumni_id)
                                     REFERENCES alumni (id)
                                     ON DELETE CASCADE
                                     ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IDX_EDU_ALUMNI ON education (alumni_id);


-- ------------------------------------------------------------
-- 5. FEEDBACK (Tracer survey responses per alumni)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS feedback (
    id                INT           AUTO_INCREMENT PRIMARY KEY,
    alumni_id         INT           NOT NULL,
    submitted_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    feedback_text     TEXT          NULL,
    rating            INT           NULL,

    CONSTRAINT FK_FEEDBACK_ALUMNI    FOREIGN KEY (alumni_id)
                                     REFERENCES alumni (id)
                                     ON DELETE CASCADE
                                     ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IDX_FB_ALUMNI ON feedback (alumni_id);


-- ------------------------------------------------------------
-- 6. DOCUMENT (Uploaded files per alumni)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS document (
    id                INT           AUTO_INCREMENT PRIMARY KEY,
    alumni_id         INT           NOT NULL,
    file_name         VARCHAR(255)  NOT NULL,
    file_path         VARCHAR(500)  NOT NULL,
    file_type         VARCHAR(100)  NULL,
    uploaded_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT FK_DOCUMENT_ALUMNI    FOREIGN KEY (alumni_id)
                                     REFERENCES alumni (id)
                                     ON DELETE CASCADE
                                     ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IDX_DOC_ALUMNI ON document (alumni_id);


-- ------------------------------------------------------------
-- 7. ANNOUNCEMENT
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS announcement (
    id                INT           AUTO_INCREMENT PRIMARY KEY,
    title             VARCHAR(255)  NOT NULL,
    content           TEXT          NOT NULL,
    created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME      NULL,
    is_published      TINYINT(1)    NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 8. JOB_POSTING (Job board)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS job_posting (
    id                INT           AUTO_INCREMENT PRIMARY KEY,
    title             VARCHAR(255)  NOT NULL,
    company           VARCHAR(255)  NOT NULL,
    description       TEXT          NULL,
    location          VARCHAR(500)  NULL,
    salary_range      VARCHAR(100)  NULL,
    posted_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at        DATE          NULL,
    is_active         TINYINT(1)    NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 9. AUDIT_LOG (Admin action tracking)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS audit_log (
    id                INT           AUTO_INCREMENT PRIMARY KEY,
    user_id           INT           NULL,
    action            VARCHAR(255)  NOT NULL,
    entity_type       VARCHAR(100)  NULL,
    entity_id         INT           NULL,
    details           TEXT          NULL,
    performed_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT FK_AUDIT_USER         FOREIGN KEY (user_id)
                                     REFERENCES `user` (id)
                                     ON DELETE SET NULL
                                     ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IDX_AUDIT_USER ON audit_log (user_id);


SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- RELATIONSHIP SUMMARY
-- ============================================================
--
--   ┌────────┐  1 ──── 1  ┌─────────┐
--   │  user  │◄───────────│ alumni  │
--   └────────┘  (user_id) └────┬────┘
--       │                      │
--       │ (role: 1=Admin,      │ 1
--       │  2=Staff,            │
--       │  3=Student)          ├──── N  ┌────────────┐
--       │                      │        │ employment │  (Employment History)
--       │                      │        └────────────┘
--       │                      │
--       │                      ├──── N  ┌───────────┐
--       │                      │        │ education │
--       │                      │        └───────────┘
--       │                      │
--       │                      ├──── N  ┌──────────┐
--       │                      │        │ feedback │
--       │                      │        └──────────┘
--       │                      │
--       │                      └──── N  ┌──────────┐
--       │                               │ document │
--       │                               └──────────┘
--       │
--       └──── N  ┌───────────┐
--                │ audit_log │
--                └───────────┘
--
-- KEY FOREIGN KEYS:
--   • alumni.user_id         → user.id       (1:1, nullable – alumni may not have login)
--   • employment.alumni_id   → alumni.id     (N:1, CASCADE delete)
--   • education.alumni_id    → alumni.id     (N:1, CASCADE delete)
--   • feedback.alumni_id     → alumni.id     (N:1, CASCADE delete)
--   • document.alumni_id     → alumni.id     (N:1, CASCADE delete)
--   • audit_log.user_id      → user.id       (N:1, SET NULL on delete)
-- ============================================================
