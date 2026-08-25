-- ===========================================================================
-- L-SIAMS · 021 · A teacher may release a student from the room
-- ===========================================================================
-- A student is sick. A parent is at the gate. Something has happened at home.
-- The student leaves, and until now the system had no way to say so.
--
-- The card reader could not help. Tapping out before the tap-out window opens
-- is refused until the minimum dwell has elapsed, which is exactly right for a
-- student trying to tap in and walk out — and exactly wrong for a child who
-- has been unwell for ten minutes. The class then ended with that student
-- recorded as present for the whole period, or auto-stamped at the bell for a
-- room they had left an hour earlier.
--
-- So the teacher releases them: one button on their own session roster, and a
-- reason the system insists on. The record becomes a real departure at the
-- real time, marked Left Early, which is what actually happened.
--
-- Three columns:
--
--   early_release_reason  why, from a fixed list, so the reason is countable.
--                         A free-text-only field produces "sick", "Sick",
--                         "feeling unwell", "unwell" and "SICK" in one term
--                         and answers no question anybody asks of it.
--   early_release_note    the teacher's own words, for what the list cannot
--                         say. Required when the reason is 'other', because
--                         'other' with no note records nothing at all.
--   early_released_by     the account that authorised it. RESTRICT, so that
--                         account cannot be deleted out from under the record
--                         — the same rule the session's own opened_by_user_id
--                         follows, and for the same reason.
--
-- NULL in all three is the ordinary case: the student tapped out, or is still
-- in the room, or never arrived. A release is never inferred from the status
-- alone, because 'left_early' is also what a genuine early tap-out produces
-- and the two are not the same event.
--
-- auto_generated_time_out stays 0 on a release. That flag means the system
-- stamped a departure nobody witnessed; here a named person recorded one they
-- did, and every screen that reads the flag would otherwise show the robot
-- icon over a decision a teacher made deliberately.
-- ===========================================================================

SET NAMES utf8mb4;

ALTER TABLE attendance_records
    ADD COLUMN early_release_reason
        ENUM('sickness','headache','injury','emergency','called_away','other') NULL DEFAULT NULL
        COMMENT 'Why the teacher released this student before the end of the period'
        AFTER departure_status,
    ADD COLUMN early_release_note VARCHAR(255) NULL DEFAULT NULL
        COMMENT "The teacher's own words; required when the reason is 'other'"
        AFTER early_release_reason,
    ADD COLUMN early_released_by INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'The account that authorised the release; NULL for an ordinary tap-out'
        AFTER early_release_note,
    ADD KEY idx_att_early_release (early_release_reason, time_out),
    ADD CONSTRAINT fk_att_released_by FOREIGN KEY (early_released_by)
        REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE RESTRICT;

-- ---------------------------------------------------------------------------
-- A release must carry a reason
-- ---------------------------------------------------------------------------
-- The service validates this before it writes, and the form will not submit
-- without it. The trigger is there for the third path — a console command, a
-- future import, a hand-typed UPDATE during a support call — because "every
-- release has a reason" is a property of the data, not a property of one
-- code path that happens to be careful today. Same argument as
-- trg_modification_requires_reason, which is why it reads the same way.
-- ---------------------------------------------------------------------------

DROP TRIGGER IF EXISTS trg_release_requires_reason;

DELIMITER //

CREATE TRIGGER trg_release_requires_reason
BEFORE UPDATE ON attendance_records
FOR EACH ROW
BEGIN
  IF NEW.early_released_by IS NOT NULL AND NEW.early_release_reason IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'An early release requires a reason.';
  END IF;

  IF NEW.early_release_reason = 'other'
     AND (NEW.early_release_note IS NULL OR TRIM(NEW.early_release_note) = '') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'An early release recorded as Other requires a note saying what happened.';
  END IF;
END//

DELIMITER ;

-- ---------------------------------------------------------------------------
-- v_attendance_detail
-- ---------------------------------------------------------------------------
-- Reports and exports read this view. A departure the teacher authorised and
-- one the student tapped are different facts, and a register that cannot tell
-- them apart cannot answer "how many students went home sick this month" —
-- which is the first question anybody will ask of this feature.
-- ---------------------------------------------------------------------------

CREATE OR REPLACE VIEW v_attendance_detail AS
SELECT
  ar.attendance_id,
  ar.session_id,
  ases.session_code,
  ar.student_id,
  s.student_number,
  CONCAT(s.last_name, ', ', s.first_name,
         IFNULL(CONCAT(' ', LEFT(s.middle_name, 1), '.'), '')) AS student_name,
  s.photo_path AS student_photo,
  ar.section_id,
  sec.section_code,
  sec.section_name,
  ar.grade_level_id,
  gl.grade_level_code,
  gl.grade_level_name,
  gl.numeric_level,
  ar.subject_id,
  sub.subject_code,
  sub.subject_name,
  sub.department_id,
  d.department_code,
  ar.teacher_id,
  CONCAT(t.last_name, ', ', t.first_name) AS teacher_name,
  ar.classroom_id,
  c.room_number,
  ar.rfid_uid,
  ar.time_in,
  ar.time_out,
  ar.duration_minutes,
  ar.time_in_device_id,
  ar.time_out_device_id,
  ar.auto_generated_time_out,
  ar.arrival_status,
  ar.departure_status,
  ar.final_status,
  ar.carried_from_session_id,
  prev.session_code AS carried_from_code,
  prevsub.subject_code AS carried_from_subject,
  ar.early_release_reason,
  ar.early_release_note,
  ar.early_released_by,
  ru.full_name AS early_released_by_name,
  ar.synced_offline,
  DATE(COALESCE(ar.time_in, ases.session_date)) AS attendance_date,
  ar.created_at
FROM attendance_records ar
JOIN attendance_sessions ases ON ases.session_id = ar.session_id
JOIN students s      ON s.student_id     = ar.student_id
JOIN sections sec    ON sec.section_id   = ar.section_id
JOIN grade_levels gl ON gl.grade_level_id = ar.grade_level_id
JOIN subjects sub    ON sub.subject_id   = ar.subject_id
JOIN departments d   ON d.department_id  = sub.department_id
JOIN teachers t      ON t.teacher_id     = ar.teacher_id
JOIN classrooms c    ON c.classroom_id   = ar.classroom_id
LEFT JOIN attendance_sessions prev ON prev.session_id = ar.carried_from_session_id
LEFT JOIN subjects prevsub         ON prevsub.subject_id = prev.subject_id
LEFT JOIN users ru                 ON ru.user_id = ar.early_released_by;
