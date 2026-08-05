-- ===========================================================================
-- L-SIAMS · 003 · Students, RFID cards and card history
-- ===========================================================================
-- Part 13.5: a student's grade level is DERIVED from their section. There is no
-- students.grade_level_id column, which structurally eliminates the possibility
-- of a Grade 10 student holding a Grade 12 grade-level record.
-- ===========================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS students (
  student_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_number  VARCHAR(30)  NOT NULL,
  -- Required: "a student without a section cannot be issued an RFID card and
  -- cannot be marked present" (Part 13.4).
  section_id      INT UNSIGNED NOT NULL,
  first_name      VARCHAR(60)  NOT NULL,
  middle_name     VARCHAR(60)  NULL,
  last_name       VARCHAR(60)  NOT NULL,
  suffix          VARCHAR(10)  NULL,
  gender          ENUM('Male','Female','Other','Prefer not to say') NOT NULL DEFAULT 'Prefer not to say',
  birthdate       DATE         NULL,
  email           VARCHAR(190) NULL,
  address         VARCHAR(255) NULL,
  guardian_name   VARCHAR(150) NOT NULL,
  guardian_contact VARCHAR(20) NOT NULL,
  guardian_email  VARCHAR(190) NULL,
  photo_path      VARCHAR(255) NULL,
  status          ENUM('active','inactive','transferred','graduated','archived') NOT NULL DEFAULT 'active',
  enrolled_at     DATE         NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      TIMESTAMP NULL COMMENT 'Archive marker — students are never physically deleted',
  UNIQUE KEY uq_student_number (student_number),
  KEY idx_student_section (section_id),
  KEY idx_student_status (status, deleted_at),
  KEY idx_student_name (last_name, first_name),
  CONSTRAINT fk_student_section FOREIGN KEY (section_id) REFERENCES sections(section_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------- RFID cards ----
CREATE TABLE IF NOT EXISTS rfid_cards (
  rfid_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id    INT UNSIGNED NULL COMMENT 'NULL for stock cards not yet issued',
  card_uid      VARCHAR(32)  NOT NULL COMMENT 'Normalised uppercase hex',
  issue_date    DATE         NOT NULL,
  replacement_date DATE      NULL,
  replaced_rfid_id INT UNSIGNED NULL COMMENT 'Chains replacement history without losing attendance',
  status        ENUM('active','inactive','lost','blacklisted','replaced') NOT NULL DEFAULT 'active',
  notes         VARCHAR(255) NULL,
  issued_by     INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- One card UID exists exactly once in the whole system.
  UNIQUE KEY uq_rfid_uid (card_uid),
  KEY idx_rfid_student (student_id, status),
  KEY idx_rfid_status (status),
  -- ON UPDATE RESTRICT, not CASCADE. student_id is an AUTO_INCREMENT surrogate
  -- key that is never updated, so the cascade would never fire — but MariaDB
  -- refuses to build a generated column over a foreign-key child column that
  -- carries a write-back referential action, and active_student_id below is
  -- exactly that. Trading a no-op cascade for the partial-unique index is the
  -- obvious way round, and it costs nothing.
  CONSTRAINT fk_rfid_student  FOREIGN KEY (student_id)       REFERENCES students(student_id)  ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_rfid_previous FOREIGN KEY (replaced_rfid_id) REFERENCES rfid_cards(rfid_id)   ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_rfid_issuer   FOREIGN KEY (issued_by)        REFERENCES users(user_id)        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "One active RFID per student" is enforced by a partial-unique trick: the
-- generated column is the student_id only while the card is active, and NULL
-- otherwise. NULLs do not collide in a MySQL unique index, so any number of
-- retired cards may coexist while a second active card is impossible.
ALTER TABLE rfid_cards
  ADD COLUMN active_student_id INT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN student_id ELSE NULL END) STORED,
  ADD UNIQUE KEY uq_one_active_card_per_student (active_student_id);

-- --------------------------------------------------- section transfers ----
-- Part 13.4: transfers are explicit and audited. Historical attendance keeps
-- the section it was recorded under; nothing is retro-rewritten.
CREATE TABLE IF NOT EXISTS student_section_history (
  history_id      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id      INT UNSIGNED NOT NULL,
  from_section_id INT UNSIGNED NULL COMMENT 'NULL on initial enrolment',
  to_section_id   INT UNSIGNED NOT NULL,
  reason          VARCHAR(500) NOT NULL,
  effective_date  DATE         NOT NULL,
  performed_by    INT UNSIGNED NOT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ssh_student (student_id, effective_date),
  KEY idx_ssh_to (to_section_id),
  CONSTRAINT fk_ssh_student FOREIGN KEY (student_id)      REFERENCES students(student_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_ssh_from    FOREIGN KEY (from_section_id) REFERENCES sections(section_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_ssh_to      FOREIGN KEY (to_section_id)   REFERENCES sections(section_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_ssh_user    FOREIGN KEY (performed_by)    REFERENCES users(user_id)       ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------- subject enrolments ----
-- Validation step 13 ("student is enrolled in the session's subject"). A row
-- per section+subject covers the normal case; per-student rows support the
-- student who legitimately takes one subject with a different section.
CREATE TABLE IF NOT EXISTS student_subject_enrolments (
  enrolment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id   INT UNSIGNED NOT NULL,
  subject_id   INT UNSIGNED NOT NULL,
  section_id   INT UNSIGNED NOT NULL COMMENT 'The section this student attends the subject with',
  school_year_id INT UNSIGNED NOT NULL,
  status       ENUM('enrolled','dropped','completed') NOT NULL DEFAULT 'enrolled',
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_enrolment (student_id, subject_id, school_year_id),
  KEY idx_enrol_subject (subject_id, status),
  KEY idx_enrol_section (section_id, subject_id),
  CONSTRAINT fk_enrol_student FOREIGN KEY (student_id)     REFERENCES students(student_id)         ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_enrol_subject FOREIGN KEY (subject_id)     REFERENCES subjects(subject_id)         ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_enrol_section FOREIGN KEY (section_id)     REFERENCES sections(section_id)         ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_enrol_year    FOREIGN KEY (school_year_id) REFERENCES school_years(school_year_id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
