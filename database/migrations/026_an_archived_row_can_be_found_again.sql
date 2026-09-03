-- ---------------------------------------------------------------------------
-- 026: an archived section or department can be found again
--
-- Archiving either one set deleted_at, and every listing query filtered
-- deleted_at IS NULL, so the row vanished from the interface completely. There
-- was no filter that brought it back and no action that undid it — a mis-click
-- on Archive was permanent, and the only way to see what had been archived was
-- to open phpMyAdmin.
--
-- The Sections page made it worse by appearing to offer the opposite: its
-- status filter has an "Archived" option, and archiveSection() does set
-- status = 'archived'. But v_section_summary ended with WHERE deleted_at IS
-- NULL, so selecting Archived searched a set the archived rows had already
-- been removed from and returned nothing, every time, with no error. A filter
-- that always returns nothing reads as "there are none", which is exactly the
-- wrong answer.
--
-- The view now carries deleted_at rather than filtering on it, so the caller
-- decides. AcademicStructureService::sections() applies IS NULL by default and
-- IS NOT NULL when the archived list is asked for; nothing else reads this
-- view. Dropping the WHERE here is what makes the existing option honest.
-- ---------------------------------------------------------------------------

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
  -- Exposed, not filtered. Every caller must now say which it wants, which is
  -- the point: the archived rows are reachable and the live lists still
  -- exclude them.
  sec.deleted_at,
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
LEFT JOIN teachers t ON t.teacher_id = sec.adviser_id;
