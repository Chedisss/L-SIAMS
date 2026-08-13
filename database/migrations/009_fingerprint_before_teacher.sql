-- ===========================================================================
-- L-SIAMS · 009 · Fingerprint capture before the teacher record exists
-- ===========================================================================
-- Enrolment used to run against a teacher who was already registered. It now
-- runs during registration instead: the finger is captured first and the
-- resulting slot is held until the teacher is saved, at which point the two are
-- bound together. A teacher therefore cannot exist without a fingerprint, which
-- is the point — an unenrolled teacher can open no session, so registering one
-- produced a record that could not yet do anything.
--
-- Two consequences to carry:
--
--   teacher_id becomes nullable, because during the capture there is nobody to
--   point at yet. It is filled in at the moment the teacher row is created.
--
--   A capture that is never bound — the form was abandoned, the browser closed —
--   leaves a template sitting in the sensor's flash occupying a slot that the
--   server would otherwise hand out again. 'abandoned' marks those so the
--   terminal can be told to delete them on its next poll, which is the only way
--   the slot is genuinely free again.
-- ===========================================================================

SET NAMES utf8mb4;

ALTER TABLE fingerprint_enrollment_requests
  MODIFY COLUMN teacher_id INT UNSIGNED NULL
    COMMENT 'NULL while capturing for a teacher who is not registered yet';

ALTER TABLE fingerprint_enrollment_requests
  ADD COLUMN subject_label VARCHAR(120) NULL
    COMMENT 'Name typed into the registration form, for the terminal display'
    AFTER teacher_id;

ALTER TABLE fingerprint_enrollment_requests
  ADD COLUMN bound_at DATETIME NULL
    COMMENT 'When the held slot was attached to a teacher record'
    AFTER completed_at;

ALTER TABLE fingerprint_enrollment_requests
  MODIFY COLUMN status ENUM('pending','scanning','completed','failed','cancelled','expired','abandoned')
    NOT NULL DEFAULT 'pending';

-- Answers "which slots on this terminal are spoken for", which is what slot
-- allocation and the terminal's delete list both need.
ALTER TABLE fingerprint_enrollment_requests
  ADD KEY idx_fer_slot_hold (device_row_id, sensor_template_id, status);
