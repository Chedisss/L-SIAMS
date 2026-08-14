-- ===========================================================================
-- L-SIAMS · 013 · RFID card enrolment requests
-- ===========================================================================
-- Issuing a card meant typing its UID, or tapping it at a terminal and fishing
-- it out of the unknown-card log afterwards. The second only works while an
-- attendance session is open, because a tap is refused for having no session
-- long before anything looks at the card — so the practical way to issue two
-- hundred cards on a registration day was to type two hundred UIDs.
--
-- This is the same hand-off the fingerprint enrolment uses: the browser opens a
-- request against a terminal, the terminal picks it up on its next poll, waits
-- for a card with the reader to itself, and reports the UID back. No session,
-- no teacher, no typing.
--
-- The captured UID is held here rather than written straight to rfid_cards: the
-- administrator still has to confirm which student it belongs to, and a card
-- read is not an authorisation to issue one.
-- ===========================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS rfid_enrollment_requests (
  request_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  student_id    INT UNSIGNED NULL COMMENT 'NULL while capturing before the student is chosen',
  subject_label VARCHAR(120) NULL COMMENT 'Name shown on the terminal display while it waits',
  device_row_id INT UNSIGNED NOT NULL,

  -- Filled in by the terminal when the card is presented. Nullable for the
  -- whole of the pending and waiting stages, which is most of a request's life.
  card_uid VARCHAR(32) NULL,

  status ENUM('pending','waiting','captured','assigned','failed','cancelled','expired')
         NOT NULL DEFAULT 'pending',

  -- Named by the firmware and rendered verbatim by the browser, so the wording
  -- of a capture step lives in one place rather than in both.
  stage   VARCHAR(40)  NOT NULL DEFAULT 'waiting_for_device',
  message VARCHAR(255) NULL,

  requested_by INT UNSIGNED NULL,
  claimed_at   DATETIME NULL COMMENT 'When the terminal picked the request up',
  captured_at  DATETIME NULL COMMENT 'When a card was actually read',
  assigned_at  DATETIME NULL COMMENT 'When the UID was issued to a student',

  -- A request nobody finishes must not hold the terminal open forever: an
  -- administrator who walks away would otherwise stop anyone else enrolling
  -- on that reader.
  expires_at DATETIME NOT NULL,

  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_rer_device_status (device_row_id, status),
  KEY idx_rer_student (student_id, created_at),
  KEY idx_rer_open (status, expires_at),
  KEY idx_rer_uid (card_uid),

  CONSTRAINT fk_rer_student FOREIGN KEY (student_id)    REFERENCES students(student_id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_rer_device  FOREIGN KEY (device_row_id) REFERENCES devices(id)          ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_rer_user    FOREIGN KEY (requested_by)  REFERENCES users(user_id)       ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
