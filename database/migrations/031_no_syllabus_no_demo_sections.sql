-- ---------------------------------------------------------------------------
-- 031: drop the per-grade syllabus, and clear the demo sections
--
-- The syllabus field was built on request and withdrawn on sight, which is the
-- right way round: a subject offered to six grades produced six empty
-- textareas on the edit form, and six boxes nobody is going to fill in are
-- worse than none — they push the fields that matter below the fold and make
-- every edit look unfinished.
--
-- Dropped rather than hidden, for the same reason subjects.units was in
-- migration 027: a dead column outlives everyone who knew it was dead. Nothing
-- was ever written to it on the deployed database, so nothing is lost.
--
-- The Grade 7 sections and their students are the last of the demo data the
-- school has confirmed is not real. Soft deletes, restorable from the Sections
-- page, and no attendance record is touched.
-- ---------------------------------------------------------------------------

ALTER TABLE subject_grade_levels DROP COLUMN syllabus;

-- --------------------------------------------------------- demo sections
-- Students first: archiveSection() refuses while any are enrolled, and this
-- follows the same order the interface would force.

UPDATE students st
  JOIN sections sec ON sec.section_id = st.section_id
   SET st.status = 'inactive', st.deleted_at = NOW(), st.updated_at = NOW()
 WHERE sec.section_code IN ('G7-S1','G7-S2')
   AND st.deleted_at IS NULL;

UPDATE schedules sch
  JOIN sections sec ON sec.section_id = sch.section_id
   SET sch.status = 'archived', sch.deleted_at = NOW(), sch.updated_at = NOW()
 WHERE sec.section_code IN ('G7-S1','G7-S2')
   AND sch.status = 'active'
   AND sch.deleted_at IS NULL;

UPDATE sections
   SET status = 'archived', deleted_at = NOW(), updated_at = NOW()
 WHERE section_code IN ('G7-S1','G7-S2')
   AND deleted_at IS NULL;
