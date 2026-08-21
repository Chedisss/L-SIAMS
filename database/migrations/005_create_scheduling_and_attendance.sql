-- ===========================================================================
-- L-SIAMS · 005 · Schedules, attendance sessions and attendance records
-- ===========================================================================
-- This migration carries the correctness guarantees of the whole system.
-- Application logic alone is never sufficient (Part 17.2): duplicates, double
-- sessions and replayed requests are made structurally impossible here, by
-- unique keys, so that a race between two devices cannot produce a second row
-- no matter what the PHP layer does.
-- ===========================================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------ schedules ----
CREATE TABLE IF NOT EXISTS schedules (
  schedule_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id     INT UNSIGNED NOT NULL,
  subject_id     INT UNSIGNED NOT NULL,
  section_id     INT UNSIGNED NOT NULL,
  classroom_id   INT UNSIGNED NOT NULL,
  school_year_id INT UNSIGNED NOT NULL,
  day_of_week    ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  start_time     TIME NOT NULL,
  end_time       TIME NOT NULL,

  -- Part 16.3 — four windows, not two. Minutes relative to start/end.
  time_in_window_open    SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  late_threshold_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  time_in_window_close   SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  time_out_window_open   SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  time_out_window_close  SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  minimum_dwell_minutes  SMALLINT UNSIGNED NOT NULL DEFAULT 20,

  status      ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at  TIMESTAMP NULL,

  KEY idx_sched_teacher_day (teacher_id, day_of_week, start_time),
  KEY idx_sched_classroom_day (classroom_id, day_of_week, start_time),
  KEY idx_sched_section_day (section_id, day_of_week, start_time),
  KEY idx_sched_subject (subject_id),
  KEY idx_sched_status (status, deleted_at),
  CONSTRAINT fk_sched_teacher   FOREIGN KEY (teacher_id)     REFERENCES teachers(teacher_id)         ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_sched_subject   FOREIGN KEY (subject_id)     REFERENCES subjects(subject_id)         ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_sched_section   FOREIGN KEY (section_id)     REFERENCES sections(section_id)         ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_sched_classroom FOREIGN KEY (classroom_id)   REFERENCES classrooms(classroom_id)     ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_sched_year      FOREIGN KEY (school_year_id) REFERENCES school_years(school_year_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_sched_creator   FOREIGN KEY (created_by)     REFERENCES users(user_id)               ON UPDATE CASCADE ON DELETE SET NULL,

  -- Window sanity is asserted by the database as well as the form, because a
  -- schedule with overlapping in/out windows makes tap intent undecidable.
  CONSTRAINT chk_sched_times  CHECK (end_time > start_time),
  CONSTRAINT chk_sched_late   CHECK (late_threshold_minutes < time_in_window_close),
  CONSTRAINT chk_sched_windows CHECK (
    TIMESTAMPDIFF(MINUTE, start_time, end_time) - time_out_window_open > time_in_window_close
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------- attendance sessions ----
CREATE TABLE IF NOT EXISTS attendance_sessions (
  session_id     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_code   VARCHAR(30)  NOT NULL COMMENT 'Public handle: ATT-2026-000203',
  schedule_id    INT UNSIGNED NOT NULL,
  teacher_id     INT UNSIGNED NOT NULL,
  device_row_id  INT UNSIGNED NOT NULL,
  classroom_id   INT UNSIGNED NOT NULL,
  section_id     INT UNSIGNED NOT NULL,
  subject_id     INT UNSIGNED NOT NULL,

  session_date   DATE     NOT NULL,
  scheduled_start DATETIME NOT NULL,
  scheduled_end   DATETIME NOT NULL,
  opened_at      DATETIME NOT NULL,
  closed_at      DATETIME NULL,
  expires_at     DATETIME NOT NULL COMMENT 'scheduled_end + time_out_window_close',

  status         ENUM('open','closed','aborted') NOT NULL DEFAULT 'open',
  closed_by_type ENUM('teacher','system','administrator') NULL,
  closed_by      INT UNSIGNED NULL,

  -- Provenance of the fingerprint that authorised this session.
  fingerprint_log_id BIGINT UNSIGNED NULL,
  api_key_id     INT UNSIGNED NULL,
  open_ip        VARCHAR(45) NULL,
  open_mac       VARCHAR(17) NULL,

  -- Rollups computed once at close, inside the same transaction.
  roster_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  present_count     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  late_count        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  absent_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  incomplete_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  left_early_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  timed_out_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  rejected_tap_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  average_dwell_minutes SMALLINT UNSIGNED NULL,
  attendance_percentage DECIMAL(5,2) NULL,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_session_code (session_code),
  KEY idx_session_schedule_date (schedule_id, session_date),
  KEY idx_session_teacher (teacher_id, session_date),
  KEY idx_session_section (section_id, session_date),
  KEY idx_session_device (device_row_id, status),
  KEY idx_session_status (status, expires_at),
  CONSTRAINT fk_sess_schedule  FOREIGN KEY (schedule_id)   REFERENCES schedules(schedule_id)   ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_sess_teacher   FOREIGN KEY (teacher_id)    REFERENCES teachers(teacher_id)     ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_sess_device    FOREIGN KEY (device_row_id) REFERENCES devices(id)              ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_sess_classroom FOREIGN KEY (classroom_id)  REFERENCES classrooms(classroom_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_sess_section   FOREIGN KEY (section_id)    REFERENCES sections(section_id)     ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_sess_subject   FOREIGN KEY (subject_id)    REFERENCES subjects(subject_id)     ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_sess_closer    FOREIGN KEY (closed_by)     REFERENCES users(user_id)           ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_sess_apikey    FOREIGN KEY (api_key_id)    REFERENCES api_keys(api_key_id)     ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Part 17.2: "one open session per classroom" and "per device", enforced by the
-- database rather than by an application check that could lose a race.
-- active_flag is 1 only while status='open' and NULL otherwise; NULL values do
-- not collide in a unique index, so any number of closed sessions may coexist.
ALTER TABLE attendance_sessions
  ADD COLUMN active_flag TINYINT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN status = 'open' THEN 1 ELSE NULL END) STORED,
  ADD UNIQUE KEY uq_one_open_session_per_classroom (classroom_id, active_flag),
  ADD UNIQUE KEY uq_one_open_session_per_device (device_row_id, active_flag),
  -- A schedule may only produce one session per calendar day.
  ADD UNIQUE KEY uq_session_schedule_day (schedule_id, session_date);

-- --------------------------------------------------- attendance records ----
CREATE TABLE IF NOT EXISTS attendance_records (
  attendance_id  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id     BIGINT UNSIGNED NOT NULL,
  student_id     INT UNSIGNED NOT NULL,

  -- Deliberate, controlled denormalisation (Part 15.4). These are copied at
  -- write time and never rewritten: a student who transfers section in January
  -- must not have their October attendance retroactively reattributed. Reports
  -- must reflect where the student actually was.
  section_id     INT UNSIGNED NOT NULL,
  grade_level_id INT UNSIGNED NOT NULL,
  subject_id     INT UNSIGNED NOT NULL,
  teacher_id     INT UNSIGNED NOT NULL,
  classroom_id   INT UNSIGNED NOT NULL,
  rfid_uid       VARCHAR(32)  NULL,

  time_in            DATETIME     NULL,
  time_in_device_id  VARCHAR(32)  NULL,
  time_in_ip         VARCHAR(45)  NULL,
  time_in_mac        VARCHAR(17)  NULL,

  time_out           DATETIME     NULL,
  time_out_device_id VARCHAR(32)  NULL,
  time_out_ip        VARCHAR(45)  NULL,
  time_out_mac       VARCHAR(17)  NULL,
  auto_generated_time_out TINYINT(1) NOT NULL DEFAULT 0,

  duration_minutes   SMALLINT UNSIGNED NULL,

  -- Three separate fields, never collapsed into one column (Part 16.6).
  arrival_status   ENUM('present','late','absent','excused','official_business') NOT NULL,
  departure_status ENUM('pending','timed_out','left_early','no_time_out','auto_closed') NOT NULL DEFAULT 'pending',
  final_status     ENUM('Present','Late','Absent','Excused','Official Business','Incomplete','Left Early') NOT NULL,

  request_id  CHAR(36) NULL COMMENT 'Idempotency handle from the device',
  synced_offline TINYINT(1) NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  -- The single most important constraint in the schema: duplicates are
  -- structurally impossible, so 50 concurrent taps of one card can only ever
  -- yield one row. The application translates error 1062 on this key into
  -- DUPLICATE_TIME_IN rather than a 500.
  UNIQUE KEY uq_attendance_session_student (session_id, student_id),
  UNIQUE KEY uq_attendance_request_id (request_id),
  KEY idx_att_student_date (student_id, time_in),
  KEY idx_att_section_date (section_id, time_in),
  KEY idx_att_session (session_id),
  KEY idx_att_final_status (final_status, time_in),
  KEY idx_att_teacher_date (teacher_id, time_in),
  KEY idx_att_subject_date (subject_id, time_in),
  KEY idx_att_grade_date (grade_level_id, time_in),
  CONSTRAINT fk_att_session   FOREIGN KEY (session_id)     REFERENCES attendance_sessions(session_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_att_student   FOREIGN KEY (student_id)     REFERENCES students(student_id)            ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_att_section   FOREIGN KEY (section_id)     REFERENCES sections(section_id)            ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_att_grade     FOREIGN KEY (grade_level_id) REFERENCES grade_levels(grade_level_id)    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_att_subject   FOREIGN KEY (subject_id)     REFERENCES subjects(subject_id)            ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_att_teacher   FOREIGN KEY (teacher_id)     REFERENCES teachers(teacher_id)            ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_att_classroom FOREIGN KEY (classroom_id)   REFERENCES classrooms(classroom_id)        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------- attendance corrections ----
-- Part 3: teachers cannot modify attendance. Only administrators, always with
-- a reason, and the original value is preserved here permanently.
CREATE TABLE IF NOT EXISTS attendance_modifications (
  modification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attendance_id   BIGINT UNSIGNED NOT NULL,
  administrator_id INT UNSIGNED NOT NULL,
  field_changed   ENUM('arrival_status','departure_status','final_status','time_in','time_out') NOT NULL,
  old_value       VARCHAR(120) NULL,
  new_value       VARCHAR(120) NULL,
  reason          VARCHAR(500) NOT NULL,
  ip_address      VARCHAR(45)  NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mod_attendance (attendance_id, created_at),
  KEY idx_mod_admin (administrator_id, created_at),
  CONSTRAINT fk_mod_attendance FOREIGN KEY (attendance_id)    REFERENCES attendance_records(attendance_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_mod_admin      FOREIGN KEY (administrator_id) REFERENCES users(user_id)                    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reference table for the status vocabulary, so reports and dropdowns share
-- one source of truth with the ENUMs above.
CREATE TABLE IF NOT EXISTS attendance_status (
  status_key   VARCHAR(30) NOT NULL PRIMARY KEY,
  label        VARCHAR(40) NOT NULL,
  category     ENUM('arrival','departure','final') NOT NULL,
  badge_class  VARCHAR(30) NOT NULL DEFAULT 'badge-neutral',
  counts_as_present TINYINT(1) NOT NULL DEFAULT 0,
  sort_order   TINYINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------ RFID logs ----
-- Every scan, accepted or not. This is where SECTION_MISMATCH, unknown cards
-- and duplicate taps are recorded — attendance_records only ever holds
-- successful attendance.
CREATE TABLE IF NOT EXISTS rfid_logs (
  log_id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  card_uid      VARCHAR(32)  NOT NULL,
  student_id    INT UNSIGNED NULL,
  device_row_id INT UNSIGNED NULL,
  session_id    BIGINT UNSIGNED NULL,
  intent        ENUM('time_in','time_out','unknown') NOT NULL DEFAULT 'unknown',
  result        ENUM('accepted','duplicate_time_in','duplicate_time_out','already_complete',
                     'unknown_card','card_disabled','student_inactive','section_mismatch',
                     'not_enrolled_in_subject','session_not_open','session_closed',
                     'time_in_not_yet_open','time_in_closed','time_out_closed',
                     'minimum_dwell_not_met','device_classroom_mismatch','rejected') NOT NULL,
  -- Both sides of a mismatch are stored so an administrator can see the real
  -- cause at a glance from the live feed.
  student_section_id INT UNSIGNED NULL,
  session_section_id INT UNSIGNED NULL,
  message       VARCHAR(255) NULL,
  ip_address    VARCHAR(45)  NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rfidlog_uid (card_uid, created_at),
  KEY idx_rfidlog_student (student_id, created_at),
  KEY idx_rfidlog_session (session_id, created_at),
  KEY idx_rfidlog_result (result, created_at),
  KEY idx_rfidlog_device (device_row_id, created_at),
  CONSTRAINT fk_rfidlog_student FOREIGN KEY (student_id)    REFERENCES students(student_id)            ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_rfidlog_device  FOREIGN KEY (device_row_id) REFERENCES devices(id)                     ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_rfidlog_session FOREIGN KEY (session_id)    REFERENCES attendance_sessions(session_id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Unknown cards are held separately so an administrator can triage them
-- (assign / ignore / blacklist) without wading through the full scan log.
CREATE TABLE IF NOT EXISTS unknown_rfid_logs (
  unknown_id    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  card_uid      VARCHAR(32)  NOT NULL,
  device_row_id INT UNSIGNED NULL,
  session_id    BIGINT UNSIGNED NULL,
  seen_count    INT UNSIGNED NOT NULL DEFAULT 1,
  first_seen_at DATETIME NOT NULL,
  last_seen_at  DATETIME NOT NULL,
  resolution    ENUM('pending','assigned','ignored','blacklisted') NOT NULL DEFAULT 'pending',
  resolved_by   INT UNSIGNED NULL,
  resolved_at   DATETIME NULL,
  notes         VARCHAR(255) NULL,
  UNIQUE KEY uq_unknown_uid (card_uid),
  KEY idx_unknown_resolution (resolution, last_seen_at),
  CONSTRAINT fk_unknown_device FOREIGN KEY (device_row_id) REFERENCES devices(id)   ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_unknown_user   FOREIGN KEY (resolved_by)   REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------- fingerprints ----
CREATE TABLE IF NOT EXISTS fingerprint_templates (
  fingerprint_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id        INT UNSIGNED NOT NULL,
  -- The sensor stores the template in its own flash and the server keeps the
  -- slot number. This was once the whole story; migration 018 adds the
  -- encrypted template alongside it, so one enrolment can reach every terminal
  -- a teacher teaches at. Read 018 for what that costs.
  sensor_template_id SMALLINT UNSIGNED NOT NULL,
  enrolled_device_row_id INT UNSIGNED NULL,
  quality_score     TINYINT UNSIGNED NULL,
  sample_count      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  enrollment_date   DATETIME NOT NULL,
  enrolled_by       INT UNSIGNED NULL,
  last_verified_at  DATETIME NULL,
  verification_count INT UNSIGNED NOT NULL DEFAULT 0,
  failure_count     INT UNSIGNED NOT NULL DEFAULT 0,
  status            ENUM('active','disabled','pending') NOT NULL DEFAULT 'active',
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- One fingerprint per teacher (Part 5), and one sensor slot per device.
  UNIQUE KEY uq_fingerprint_teacher (teacher_id),
  UNIQUE KEY uq_fingerprint_slot (enrolled_device_row_id, sensor_template_id),
  KEY idx_fingerprint_status (status),
  CONSTRAINT fk_fp_teacher FOREIGN KEY (teacher_id)             REFERENCES teachers(teacher_id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_fp_device  FOREIGN KEY (enrolled_device_row_id) REFERENCES devices(id)          ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_fp_enroller FOREIGN KEY (enrolled_by)           REFERENCES users(user_id)       ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fingerprint_logs (
  log_id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id    INT UNSIGNED NULL COMMENT 'NULL when the presented template matched nobody',
  device_row_id INT UNSIGNED NULL,
  sensor_template_id SMALLINT UNSIGNED NULL,
  result        ENUM('verified','failed','unknown','not_assigned','no_schedule','locked',
                     'session_exists','teacher_inactive','enrolled','deleted') NOT NULL,
  confidence    SMALLINT UNSIGNED NULL,
  message       VARCHAR(255) NULL,
  ip_address    VARCHAR(45)  NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_fplog_teacher (teacher_id, created_at),
  KEY idx_fplog_device (device_row_id, created_at),
  KEY idx_fplog_result (result, created_at),
  CONSTRAINT fk_fplog_teacher FOREIGN KEY (teacher_id)    REFERENCES teachers(teacher_id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_fplog_device  FOREIGN KEY (device_row_id) REFERENCES devices(id)          ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE attendance_sessions
  ADD CONSTRAINT fk_sess_fingerprint FOREIGN KEY (fingerprint_log_id) REFERENCES fingerprint_logs(log_id)
    ON UPDATE CASCADE ON DELETE SET NULL;
