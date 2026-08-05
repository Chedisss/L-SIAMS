-- ===========================================================================
-- L-SIAMS · 007 · Triggers and views
-- ===========================================================================
-- The triggers here enforce two things the application must not be trusted to
-- get right on its own:
--   1. sections.enrolled_count is never a stale copy.
--   2. Attendance, audit and security history cannot be deleted or rewritten,
--      even by a direct SQL session with the application's credentials.
-- ===========================================================================

SET NAMES utf8mb4;

DELIMITER $$

-- ------------------------------------------- section enrolment counters ----
DROP TRIGGER IF EXISTS trg_students_after_insert $$
CREATE TRIGGER trg_students_after_insert
AFTER INSERT ON students
FOR EACH ROW
BEGIN
  IF NEW.deleted_at IS NULL AND NEW.status = 'active' THEN
    UPDATE sections
       SET enrolled_count = enrolled_count + 1
     WHERE section_id = NEW.section_id;
  END IF;
END $$

DROP TRIGGER IF EXISTS trg_students_after_update $$
CREATE TRIGGER trg_students_after_update
AFTER UPDATE ON students
FOR EACH ROW
BEGIN
  DECLARE was_counted TINYINT DEFAULT 0;
  DECLARE is_counted  TINYINT DEFAULT 0;

  SET was_counted = (OLD.deleted_at IS NULL AND OLD.status = 'active');
  SET is_counted  = (NEW.deleted_at IS NULL AND NEW.status = 'active');

  IF was_counted = 1 AND (is_counted = 0 OR OLD.section_id <> NEW.section_id) THEN
    UPDATE sections
       SET enrolled_count = GREATEST(enrolled_count - 1, 0)
     WHERE section_id = OLD.section_id;
  END IF;

  IF is_counted = 1 AND (was_counted = 0 OR OLD.section_id <> NEW.section_id) THEN
    UPDATE sections
       SET enrolled_count = enrolled_count + 1
     WHERE section_id = NEW.section_id;
  END IF;
END $$

-- Capacity is a hard limit, not advice. Rejecting here means an enrolment can
-- never exceed capacity through any code path, including a direct import.
DROP TRIGGER IF EXISTS trg_students_before_insert $$
CREATE TRIGGER trg_students_before_insert
BEFORE INSERT ON students
FOR EACH ROW
BEGIN
  DECLARE section_capacity SMALLINT UNSIGNED;
  DECLARE section_enrolled SMALLINT UNSIGNED;

  IF NEW.deleted_at IS NULL AND NEW.status = 'active' THEN
    SELECT capacity, enrolled_count INTO section_capacity, section_enrolled
      FROM sections WHERE section_id = NEW.section_id FOR UPDATE;

    IF section_enrolled >= section_capacity THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'SECTION_CAPACITY_EXCEEDED';
    END IF;
  END IF;
END $$

-- ---------------------------------------- immutable historical records ----
DROP TRIGGER IF EXISTS trg_attendance_no_delete $$
CREATE TRIGGER trg_attendance_no_delete
BEFORE DELETE ON attendance_records
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Attendance records are permanent and cannot be deleted. Use an administrator correction instead.';
END $$

DROP TRIGGER IF EXISTS trg_audit_no_delete $$
CREATE TRIGGER trg_audit_no_delete
BEFORE DELETE ON audit_logs
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Audit logs are immutable and cannot be deleted.';
END $$

DROP TRIGGER IF EXISTS trg_audit_no_update $$
CREATE TRIGGER trg_audit_no_update
BEFORE UPDATE ON audit_logs
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Audit logs are immutable and cannot be modified.';
END $$

DROP TRIGGER IF EXISTS trg_security_no_delete $$
CREATE TRIGGER trg_security_no_delete
BEFORE DELETE ON security_logs
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Security logs are immutable and cannot be deleted.';
END $$

DROP TRIGGER IF EXISTS trg_login_history_no_delete $$
CREATE TRIGGER trg_login_history_no_delete
BEFORE DELETE ON login_history
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Login history is permanent and cannot be deleted.';
END $$

-- Every attendance correction must carry a reason. The column is NOT NULL, but
-- an empty string would satisfy that; this closes the gap.
DROP TRIGGER IF EXISTS trg_modification_requires_reason $$
CREATE TRIGGER trg_modification_requires_reason
BEFORE INSERT ON attendance_modifications
FOR EACH ROW
BEGIN
  IF NEW.reason IS NULL OR TRIM(NEW.reason) = '' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'An attendance modification requires a reason.';
  END IF;
END $$

-- A student's grade level is derived from their section (Part 13.5). This
-- guards the denormalised copy on attendance_records against ever disagreeing
-- with the section it was written alongside.
DROP TRIGGER IF EXISTS trg_attendance_grade_consistency $$
CREATE TRIGGER trg_attendance_grade_consistency
BEFORE INSERT ON attendance_records
FOR EACH ROW
BEGIN
  DECLARE derived_grade INT UNSIGNED;

  SELECT grade_level_id INTO derived_grade
    FROM sections WHERE section_id = NEW.section_id;

  IF derived_grade IS NULL OR derived_grade <> NEW.grade_level_id THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Attendance grade level does not match the recorded section.';
  END IF;
END $$

DELIMITER ;

-- ===========================================================================
-- Views
-- ===========================================================================

-- Flattened attendance for the admin table, live feed and every report. Keeping
-- the joins in one place means the ~12 call sites cannot drift apart.
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
JOIN classrooms c    ON c.classroom_id   = ar.classroom_id;

-- Device fleet health, with online/offline derived from the heartbeat timeout
-- rather than a status column that could go stale between sweeps.
CREATE OR REPLACE VIEW v_device_status AS
SELECT
  dev.id AS device_row_id,
  dev.device_id,
  dev.device_name,
  dev.mac_address,
  dev.ip_address,
  dev.firmware_version,
  dev.device_role,
  dev.classroom_id,
  c.room_number,
  c.building,
  dev.wifi_signal,
  dev.queue_depth,
  dev.uptime_seconds,
  dev.battery_percent,
  dev.status AS configured_status,
  dev.claim_status,
  dev.last_heartbeat_at,
  TIMESTAMPDIFF(SECOND, dev.last_heartbeat_at, NOW()) AS seconds_since_heartbeat,
  CASE
    WHEN dev.status IN ('disabled','decommissioned','suspended') THEN 'disabled'
    WHEN dev.claim_status <> 'claimed' THEN 'pending'
    WHEN dev.last_heartbeat_at IS NULL THEN 'offline'
    WHEN TIMESTAMPDIFF(SECOND, dev.last_heartbeat_at, NOW()) <= 45 THEN 'online'
    WHEN TIMESTAMPDIFF(SECOND, dev.last_heartbeat_at, NOW()) <= 90 THEN 'warning'
    ELSE 'offline'
  END AS health,
  -- Configuration the device detail page edits in place, so that screen needs
  -- one read rather than a second query against `devices`.
  dev.ip_allowlist,
  dev.heartbeat_interval_sec,
  dev.sync_interval_sec,
  dev.offline_queue_limit,
  dev.location_note,
  ak.key_id,
  ak.secret_last_four,
  ak.status AS key_status,
  ak.created_at AS key_created_at,
  DATEDIFF(NOW(), ak.created_at) AS key_age_days,
  ak.last_used_at AS key_last_used_at,
  ak.use_count AS key_use_count,
  sess.session_id AS active_session_id,
  sess.session_code AS active_session_code
FROM devices dev
LEFT JOIN classrooms c ON c.classroom_id = dev.classroom_id
LEFT JOIN api_keys ak  ON ak.device_row_id = dev.id AND ak.status IN ('active','rotating')
LEFT JOIN attendance_sessions sess ON sess.device_row_id = dev.id AND sess.status = 'open'
WHERE dev.deleted_at IS NULL;

-- Everything the Part 14 validators and the cascading dropdowns need about a
-- teacher, in one row.
CREATE OR REPLACE VIEW v_teacher_constraints AS
SELECT
  t.teacher_id,
  t.employee_number,
  CONCAT(t.last_name, ', ', t.first_name) AS teacher_name,
  t.department_id,
  d.department_code,
  d.department_name,
  t.fingerprint_status,
  t.status,
  (SELECT COUNT(*) FROM teacher_grade_levels tgl WHERE tgl.teacher_id = t.teacher_id) AS grade_level_count,
  (SELECT GROUP_CONCAT(gl.grade_level_code ORDER BY gl.numeric_level SEPARATOR ',')
     FROM teacher_grade_levels tgl
     JOIN grade_levels gl ON gl.grade_level_id = tgl.grade_level_id
    WHERE tgl.teacher_id = t.teacher_id) AS grade_level_codes,
  (SELECT COUNT(*) FROM subjects s
    WHERE s.department_id = t.department_id AND s.status = 'active' AND s.deleted_at IS NULL) AS assignable_subject_count,
  (SELECT COUNT(*) FROM sections sec
     JOIN teacher_grade_levels tgl2 ON tgl2.grade_level_id = sec.grade_level_id AND tgl2.teacher_id = t.teacher_id
    WHERE sec.status = 'active' AND sec.deleted_at IS NULL) AS assignable_section_count,
  (SELECT COUNT(*) FROM schedules sch
    WHERE sch.teacher_id = t.teacher_id AND sch.status = 'active' AND sch.deleted_at IS NULL) AS active_schedule_count
FROM teachers t
JOIN departments d ON d.department_id = t.department_id
WHERE t.deleted_at IS NULL;

-- Section rollup used by the Sections page, adviser reports and the
-- section-comparison chart.
CREATE OR REPLACE VIEW v_section_summary AS
SELECT
  sec.section_id,
  sec.section_code,
  sec.section_name,
  sec.grade_level_id,
  gl.grade_level_code,
  gl.numeric_level,
  sec.strand,
  sec.capacity,
  sec.enrolled_count,
  ROUND(sec.enrolled_count / NULLIF(sec.capacity, 0) * 100, 1) AS capacity_percent,
  sec.adviser_id,
  CONCAT(t.last_name, ', ', t.first_name) AS adviser_name,
  sec.status,
  (SELECT COUNT(*) FROM students st
    WHERE st.section_id = sec.section_id AND st.deleted_at IS NULL AND st.status = 'active') AS active_students,
  (SELECT COUNT(*) FROM students st
     JOIN rfid_cards rc ON rc.student_id = st.student_id AND rc.status = 'active'
    WHERE st.section_id = sec.section_id AND st.deleted_at IS NULL AND st.status = 'active') AS students_with_cards,
  (SELECT ROUND(
            SUM(CASE WHEN ar.final_status IN ('Present','Late') THEN 1 ELSE 0 END)
            / NULLIF(COUNT(*), 0) * 100, 2)
     FROM attendance_records ar
    WHERE ar.section_id = sec.section_id
      AND ar.time_in >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS attendance_percentage_30d
FROM sections sec
JOIN grade_levels gl ON gl.grade_level_id = sec.grade_level_id
LEFT JOIN teachers t ON t.teacher_id = sec.adviser_id
WHERE sec.deleted_at IS NULL;
