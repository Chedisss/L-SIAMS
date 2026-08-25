-- ===========================================================================
-- L-SIAMS · 020 · Attendance carries to the next subject
-- ===========================================================================
-- A section sits in one room for most of the day. The bell rings, one teacher
-- leaves, the next walks in, and the same forty students are in the same
-- chairs. Making every one of them tap again for every period is forty taps of
-- queueing per subject, six or seven times a day, for a fact the system
-- already knows: they were here five minutes ago and they have not moved.
--
-- So a session that follows another one for the same section carries the
-- previous register forward. A student who was Present or Late in the period
-- that just ended starts the new period Present, without tapping.
--
-- What does NOT carry is anyone who was not in the room at the end:
--
--   Absent      never arrived                    → may still tap in
--   Left Early  tapped out before the bell       → may still tap in
--   Excused     excused from that class          → may still tap in
--   Incomplete  arrived, no tap-out on record    → may still tap in
--
-- That is the second half of the rule and the more important one. Missing the
-- first period must not condemn a student to being marked absent all day, and
-- leaving early must not either — they get the same chance to tap in for the
-- next subject as anyone else.
--
-- Two columns, so a carried row is never mistaken for a tap:
--
--   attendance_records.carried_from_session_id
--       the session this presence was inherited from. NULL means the student
--       tapped, which is what every existing row did. The time_in_device_id,
--       time_in_ip and time_in_mac of a carried row are NULL for the same
--       reason — no card was presented to any reader, and recording one would
--       be a lie that reports and audits would repeat.
--
--   attendance_sessions.carried_in_count
--       how many of this session's records arrived by carry rather than by
--       tap. A teacher looking at a full register can see at a glance how much
--       of it was actually scanned in front of them.
--
-- ON DELETE SET NULL, not CASCADE: attendance is never physically deleted, and
-- if a session row ever did go, the student's presence in the NEXT period is
-- still true — it would just no longer know where it came from.
-- ===========================================================================

SET NAMES utf8mb4;

ALTER TABLE attendance_records
    ADD COLUMN carried_from_session_id BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Session this presence was carried from; NULL means the student tapped'
        AFTER request_id,
    ADD KEY idx_att_carried_from (carried_from_session_id),
    ADD CONSTRAINT fk_att_carried_from FOREIGN KEY (carried_from_session_id)
        REFERENCES attendance_sessions(session_id) ON UPDATE CASCADE ON DELETE SET NULL;

ALTER TABLE attendance_sessions
    ADD COLUMN carried_in_count SMALLINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Records that arrived by carry-over from the previous period rather than by tap'
        AFTER roster_count;

-- ---------------------------------------------------------------------------
-- v_attendance_detail
-- ---------------------------------------------------------------------------
-- Every report, export and attendance search reads this view. If the carry
-- does not reach it, a carried record is indistinguishable from a scanned one
-- everywhere anybody actually looks — which is precisely the confusion the
-- column exists to prevent. Re-declared in full below because CREATE OR
-- REPLACE VIEW replaces the whole definition; the only additions are the two
-- carry columns and the LEFT JOIN that names the period they came from.
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
LEFT JOIN subjects prevsub         ON prevsub.subject_id = prev.subject_id;

-- ---------------------------------------------------------------------------
-- Settings
-- ---------------------------------------------------------------------------
-- Carrying is on by default because it is what the school asked for, but it is
-- a policy, not a law, and a school that wants every period scanned separately
-- must be able to say so without editing code.
--
-- The gap limit is what keeps "the next period" meaning the next period. Two
-- classes an hour apart are not a handover — the students went somewhere in
-- between — so beyond the limit the new register starts empty and everyone
-- taps.
-- ---------------------------------------------------------------------------

INSERT INTO settings
    (setting_key, setting_value, value_type, group_name, label, description,
     is_sensitive, min_value, max_value)
VALUES
    ('attendance.carry_over_enabled', '1', 'bool', 'attendance',
     'Carry attendance to the next subject',
     'A student who was Present or Late in the previous period starts the next one Present without tapping again. Absent, Left Early, Excused and Incomplete never carry — those students may still tap in.',
     0, NULL, NULL),
    ('attendance.carry_over_max_gap_minutes', '30', 'int', 'attendance',
     'Carry-over gap limit (minutes)',
     'The longest break between one period ending and the next beginning that still counts as a handover. Beyond this, the new register starts empty.',
     0, '0', '240')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
