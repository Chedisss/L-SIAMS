-- ===========================================================================
-- L-SIAMS · 002 · Academic structure (Part 13)
-- ===========================================================================
-- Departments, grade levels and sections are first-class, referentially
-- enforced entities. Every teacher, subject, student, schedule and attendance
-- record resolves to a real row in these tables — `department` is never a
-- free-text field and `section` is never a loose label.
-- ===========================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------- school years ----
CREATE TABLE IF NOT EXISTS school_years (
  school_year_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  year_label     VARCHAR(20)  NOT NULL COMMENT 'e.g. 2026-2027',
  start_date     DATE         NOT NULL,
  end_date       DATE         NOT NULL,
  is_current     TINYINT(1)   NOT NULL DEFAULT 0,
  status         ENUM('active','closed','archived') NOT NULL DEFAULT 'active',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_school_year (year_label),
  KEY idx_school_year_current (is_current)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------- departments ----
CREATE TABLE IF NOT EXISTS departments (
  department_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  department_code VARCHAR(20)  NOT NULL COMMENT 'Unique and immutable after creation (Part 13.2)',
  department_name VARCHAR(120) NOT NULL,
  description     VARCHAR(500) NULL,
  head_teacher_id INT UNSIGNED NULL,
  status          ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      TIMESTAMP NULL,
  UNIQUE KEY uq_department_code (department_code),
  UNIQUE KEY uq_department_name (department_name),
  KEY idx_department_status (status, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- grade levels ----
CREATE TABLE IF NOT EXISTS grade_levels (
  grade_level_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  grade_level_code VARCHAR(10)  NOT NULL COMMENT 'G7 … G12',
  grade_level_name VARCHAR(50)  NOT NULL,
  -- All programmatic comparisons use numeric_level. Never compare grade levels
  -- as strings ("G10" < "G7" lexically, which would be a silent disaster).
  numeric_level    TINYINT UNSIGNED NOT NULL,
  track            ENUM('Academic','TVL','Sports','Arts and Design') NULL,
  status           ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_grade_code (grade_level_code),
  UNIQUE KEY uq_grade_numeric (numeric_level),
  KEY idx_grade_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------- subjects ----
CREATE TABLE IF NOT EXISTS subjects (
  subject_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject_code  VARCHAR(30)  NOT NULL,
  subject_name  VARCHAR(150) NOT NULL,
  description   VARCHAR(500) NULL,
  -- Part 13.2: every subject belongs to exactly one department. This column is
  -- what makes the Part 14.1 cross-department constraint enforceable at all.
  department_id INT UNSIGNED NOT NULL,
  units         DECIMAL(4,2) NOT NULL DEFAULT 1.00,
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at    TIMESTAMP NULL,
  UNIQUE KEY uq_subject_code (subject_code),
  KEY idx_subject_department (department_id),
  KEY idx_subject_status (status, deleted_at),
  CONSTRAINT fk_subject_department FOREIGN KEY (department_id) REFERENCES departments(department_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which grade levels a subject is offered to (schedule validation rule 7).
CREATE TABLE IF NOT EXISTS subject_grade_levels (
  subject_id     INT UNSIGNED NOT NULL,
  grade_level_id INT UNSIGNED NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (subject_id, grade_level_id),
  KEY idx_sgl_grade (grade_level_id),
  CONSTRAINT fk_sgl_subject FOREIGN KEY (subject_id)     REFERENCES subjects(subject_id)         ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_sgl_grade   FOREIGN KEY (grade_level_id) REFERENCES grade_levels(grade_level_id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------- teachers ----
CREATE TABLE IF NOT EXISTS teachers (
  teacher_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_number   VARCHAR(30)  NOT NULL COMMENT 'Unique and immutable after creation',
  user_id           INT UNSIGNED NULL COMMENT 'Web account; NULL until credentials are issued',
  department_id     INT UNSIGNED NOT NULL COMMENT 'Primary department (Part 13.2)',
  first_name        VARCHAR(60)  NOT NULL,
  middle_name       VARCHAR(60)  NULL,
  last_name         VARCHAR(60)  NOT NULL,
  suffix            VARCHAR(10)  NULL,
  email             VARCHAR(190) NOT NULL,
  phone             VARCHAR(20)  NULL,
  position          VARCHAR(80)  NULL,
  photo_path        VARCHAR(255) NULL,
  fingerprint_status ENUM('not_enrolled','enrolled','disabled') NOT NULL DEFAULT 'not_enrolled',
  status            ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
  hired_at          DATE         NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at        TIMESTAMP NULL,
  UNIQUE KEY uq_teacher_employee (employee_number),
  UNIQUE KEY uq_teacher_user (user_id),
  UNIQUE KEY uq_teacher_email (email),
  KEY idx_teacher_department (department_id),
  KEY idx_teacher_status (status, deleted_at),
  KEY idx_teacher_name (last_name, first_name),
  CONSTRAINT fk_teacher_user       FOREIGN KEY (user_id)       REFERENCES users(user_id)             ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_teacher_department FOREIGN KEY (department_id) REFERENCES departments(department_id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE departments
  ADD CONSTRAINT fk_department_head FOREIGN KEY (head_teacher_id) REFERENCES teachers(teacher_id)
    ON UPDATE CASCADE ON DELETE SET NULL;

-- Secondary departments (Part 14.6). The primary lives on teachers.department_id;
-- rows here are always the result of an explicit, audited administrator action.
CREATE TABLE IF NOT EXISTS teacher_departments (
  teacher_id    INT UNSIGNED NOT NULL,
  department_id INT UNSIGNED NOT NULL,
  is_primary    TINYINT(1)   NOT NULL DEFAULT 0,
  assigned_by   INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (teacher_id, department_id),
  KEY idx_td_department (department_id),
  CONSTRAINT fk_td_teacher    FOREIGN KEY (teacher_id)    REFERENCES teachers(teacher_id)       ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_td_department FOREIGN KEY (department_id) REFERENCES departments(department_id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_td_assigner   FOREIGN KEY (assigned_by)   REFERENCES users(user_id)             ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Part 14.2: most teachers handle one grade level; the pivot supports several
-- without any redesign.
CREATE TABLE IF NOT EXISTS teacher_grade_levels (
  teacher_id     INT UNSIGNED NOT NULL,
  grade_level_id INT UNSIGNED NOT NULL,
  assigned_by    INT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (teacher_id, grade_level_id),
  KEY idx_tgl_grade (grade_level_id),
  CONSTRAINT fk_tgl_teacher  FOREIGN KEY (teacher_id)     REFERENCES teachers(teacher_id)         ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_tgl_grade    FOREIGN KEY (grade_level_id) REFERENCES grade_levels(grade_level_id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_tgl_assigner FOREIGN KEY (assigned_by)    REFERENCES users(user_id)               ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teacher_subjects (
  teacher_id  INT UNSIGNED NOT NULL,
  subject_id  INT UNSIGNED NOT NULL,
  assigned_by INT UNSIGNED NULL,
  is_exception TINYINT(1)  NOT NULL DEFAULT 0 COMMENT 'Granted through a cross-department exception',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (teacher_id, subject_id),
  KEY idx_ts_subject (subject_id),
  CONSTRAINT fk_ts_teacher  FOREIGN KEY (teacher_id)  REFERENCES teachers(teacher_id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ts_subject  FOREIGN KEY (subject_id)  REFERENCES subjects(subject_id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ts_assigner FOREIGN KEY (assigned_by) REFERENCES users(user_id)       ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------- sections ----
CREATE TABLE IF NOT EXISTS sections (
  section_id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  section_code         VARCHAR(30)  NOT NULL,
  section_name         VARCHAR(80)  NOT NULL,
  grade_level_id       INT UNSIGNED NOT NULL,
  adviser_id           INT UNSIGNED NULL,
  strand               VARCHAR(30)  NULL COMMENT 'STEM, ABM, HUMSS, GAS, ICT …',
  capacity             SMALLINT UNSIGNED NOT NULL DEFAULT 45,
  -- Maintained by trigger on students INSERT/UPDATE/DELETE so it is never a
  -- stale copy that capacity checks could be fooled by.
  enrolled_count       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  school_year_id       INT UNSIGNED NOT NULL,
  default_classroom_id INT UNSIGNED NULL,
  status               ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at           TIMESTAMP NULL,
  UNIQUE KEY uq_section_code_year (section_code, school_year_id),
  KEY idx_section_grade (grade_level_id),
  KEY idx_section_adviser (adviser_id),
  KEY idx_section_status (status, deleted_at),
  CONSTRAINT fk_section_grade   FOREIGN KEY (grade_level_id) REFERENCES grade_levels(grade_level_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_section_adviser FOREIGN KEY (adviser_id)     REFERENCES teachers(teacher_id)         ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_section_year    FOREIGN KEY (school_year_id) REFERENCES school_years(school_year_id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teacher_sections (
  teacher_id  INT UNSIGNED NOT NULL,
  section_id  INT UNSIGNED NOT NULL,
  assigned_by INT UNSIGNED NULL,
  is_exception TINYINT(1)  NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (teacher_id, section_id),
  KEY idx_tsec_section (section_id),
  CONSTRAINT fk_tsec_teacher  FOREIGN KEY (teacher_id)  REFERENCES teachers(teacher_id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_tsec_section  FOREIGN KEY (section_id)  REFERENCES sections(section_id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_tsec_assigner FOREIGN KEY (assigned_by) REFERENCES users(user_id)       ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------ controlled overrides (Part 14.6) ----
-- Exceptions never weaken the default rule; they are named, time-boxed and
-- audited grants that the validators consult explicitly.
CREATE TABLE IF NOT EXISTS teacher_department_exceptions (
  exception_id  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id    INT UNSIGNED NOT NULL,
  department_id INT UNSIGNED NOT NULL,
  reason        VARCHAR(500) NOT NULL,
  effective_from DATE        NOT NULL,
  effective_to   DATE        NOT NULL,
  granted_by    INT UNSIGNED NOT NULL,
  revoked_at    DATETIME     NULL,
  revoked_by    INT UNSIGNED NULL,
  status        ENUM('active','expired','revoked') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tde_lookup (teacher_id, department_id, status, effective_from, effective_to),
  CONSTRAINT fk_tde_teacher    FOREIGN KEY (teacher_id)    REFERENCES teachers(teacher_id)       ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_tde_department FOREIGN KEY (department_id) REFERENCES departments(department_id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_tde_granter    FOREIGN KEY (granted_by)    REFERENCES users(user_id)             ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_tde_revoker    FOREIGN KEY (revoked_by)    REFERENCES users(user_id)             ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teacher_grade_level_exceptions (
  exception_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id     INT UNSIGNED NOT NULL,
  grade_level_id INT UNSIGNED NOT NULL,
  reason         VARCHAR(500) NOT NULL,
  effective_from DATE         NOT NULL,
  effective_to   DATE         NOT NULL,
  granted_by     INT UNSIGNED NOT NULL,
  revoked_at     DATETIME     NULL,
  revoked_by     INT UNSIGNED NULL,
  status         ENUM('active','expired','revoked') NOT NULL DEFAULT 'active',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tgle_lookup (teacher_id, grade_level_id, status, effective_from, effective_to),
  CONSTRAINT fk_tgle_teacher FOREIGN KEY (teacher_id)     REFERENCES teachers(teacher_id)         ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_tgle_grade   FOREIGN KEY (grade_level_id) REFERENCES grade_levels(grade_level_id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_tgle_granter FOREIGN KEY (granted_by)     REFERENCES users(user_id)               ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_tgle_revoker FOREIGN KEY (revoked_by)     REFERENCES users(user_id)               ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
