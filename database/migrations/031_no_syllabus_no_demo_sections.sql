-- ---------------------------------------------------------------------------
-- 031: drop the per-grade syllabus, and clear the demo sections
--
-- The syllabus field was built on request and withdrawn on sight, which is the
-- right way round: a subject offered to six grades produced six empty
-- textareas on the edit form, and six boxes nobody is going to fill in are
-- worse than none — they push the fields that matter below the fold.
--
-- Dropped rather than hidden, for the same reason subjects.units was in
-- migration 027: a dead column outlives everyone who knew it was dead.
--
-- The Grade 7 sections and their students are the last of the demo data the
-- school has confirmed is not real. Soft deletes, restorable from the Sections
-- page, and no attendance record is touched.
--
-- ---------------------------------------------------------------------------
-- Two things in here are defensive rather than decorative, and both are the
-- result of this migration failing on the deployed database:
--
--   IF EXISTS on the column drop. A migration that fails part-way is not
--   recorded as applied, so the next run starts it again from the top — and
--   DDL had already committed. Without the guard the retry fails on "check
--   that it exists" and the school is stuck in a loop it cannot get out of.
--
--   The temporary table. students carries an AFTER UPDATE trigger that keeps
--   sections.enrolled_count in step, and MySQL refuses (error 1442) to let a
--   trigger write to a table the invoking statement is already reading. The
--   original wrote UPDATE students JOIN sections, which reads sections, so
--   every school with actual students in those sections hit it. Copying the
--   ids out first means the UPDATE never mentions sections at all.
--
-- The reason this passed here and failed there is worth recording: the demo
-- database had no active students in those sections, so the UPDATE matched
-- nothing, the trigger never fired, and the bug was invisible. Verifying the
-- end state is not the same as verifying the work happened.
-- ---------------------------------------------------------------------------

ALTER TABLE subject_grade_levels DROP COLUMN IF EXISTS syllabus;

-- ------------------------------------------------------------ demo sections
-- The ids, copied out of sections so that nothing below has to read from it.

CREATE TEMPORARY TABLE IF NOT EXISTS tmp_demo_sections (
    section_id INT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE = MEMORY;

TRUNCATE TABLE tmp_demo_sections;

INSERT INTO tmp_demo_sections (section_id)
SELECT section_id FROM sections WHERE section_code IN ('G7-S1','G7-S2');

-- Students first: archiveSection() refuses while any are enrolled, and this
-- follows the same order the interface would force.

UPDATE students st
  JOIN tmp_demo_sections d ON d.section_id = st.section_id
   SET st.status = 'inactive', st.deleted_at = NOW(), st.updated_at = NOW()
 WHERE st.deleted_at IS NULL;

UPDATE schedules sch
  JOIN tmp_demo_sections d ON d.section_id = sch.section_id
   SET sch.status = 'archived', sch.deleted_at = NOW(), sch.updated_at = NOW()
 WHERE sch.status = 'active'
   AND sch.deleted_at IS NULL;

UPDATE sections
   SET status = 'archived', deleted_at = NOW(), updated_at = NOW()
 WHERE section_code IN ('G7-S1','G7-S2')
   AND deleted_at IS NULL;

DROP TEMPORARY TABLE IF EXISTS tmp_demo_sections;
