-- ===========================================================================
-- L-SIAMS · 008 · Fingerprint enrolment requests
-- ===========================================================================
-- The enrolment capture happens on the R307 attached to a terminal, but the
-- decision to enrol is taken in the browser. This table is the hand-off: an
-- administrator opens a request against a terminal, the terminal picks it up on
-- its next poll, runs the sensor's capture cycle, and reports back.
--
-- Still no biometric template reaches this database. The request carries the
-- slot number the template must be written to and nothing else; the template
-- itself lives and dies in the sensor's own flash.
--
-- Rows are kept after they finish. A failed enrolment is a support question
-- ("it would not read her thumb") and the stage it failed at is the answer.
-- ===========================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS fingerprint_enrollment_requests (
  request_id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id         INT UNSIGNED NOT NULL,
  device_row_id      INT UNSIGNED NOT NULL,
  sensor_template_id SMALLINT UNSIGNED NOT NULL COMMENT 'Slot the sensor must write to; allocated here, not by the device',

  status ENUM('pending','scanning','completed','failed','cancelled','expired')
         NOT NULL DEFAULT 'pending',

  -- Free-form because the sensor's capture cycle is the sensor's business; the
  -- firmware names each step and the browser renders whatever it is told.
  stage   VARCHAR(40)  NOT NULL DEFAULT 'waiting_for_device',
  message VARCHAR(255) NULL COMMENT 'Human-readable status shown in the enrolment wizard',

  quality_score TINYINT UNSIGNED NULL,
  sample_count  TINYINT UNSIGNED NOT NULL DEFAULT 0,

  requested_by INT UNSIGNED NULL,
  claimed_at   DATETIME NULL COMMENT 'When the terminal picked the request up',
  completed_at DATETIME NULL,

  -- A request nobody acts on must not sit open forever holding a slot: an
  -- administrator who walks away from the screen would otherwise block that
  -- terminal from enrolling anyone else.
  expires_at DATETIME NOT NULL,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_fer_device_status (device_row_id, status),
  KEY idx_fer_teacher (teacher_id, created_at),
  KEY idx_fer_open (status, expires_at),

  CONSTRAINT fk_fer_teacher FOREIGN KEY (teacher_id)    REFERENCES teachers(teacher_id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_fer_device  FOREIGN KEY (device_row_id) REFERENCES devices(id)          ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_fer_user    FOREIGN KEY (requested_by)  REFERENCES users(user_id)       ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 'cancelled' completes the set of outcomes the wizard can produce. The enum
-- already carried 'enrolled' and 'deleted' for the administrative actions.
ALTER TABLE fingerprint_logs
  MODIFY COLUMN result ENUM('verified','failed','unknown','not_assigned','no_schedule','locked',
                            'session_exists','teacher_inactive','enrolled','deleted','cancelled') NOT NULL;
