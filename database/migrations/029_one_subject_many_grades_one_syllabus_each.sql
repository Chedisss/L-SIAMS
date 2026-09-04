-- ---------------------------------------------------------------------------
-- 029: one subject, many grades, a syllabus for each
--
-- Migration 028 gave Grade 3 its own coded set — ENG03, MATH03, FIL03 — which
-- followed the convention already on the deployed database (ENG07, FIL07,
-- MATH07) and fought the schema the whole way.
--
-- subject_grade_levels has always been a many-to-many. One subject offered to
-- six grades is what it is for, and the grade-suffixed codes reduced it to a
-- one-to-one that needed a new subject row per grade: six English subjects,
-- six teacher assignments to keep in step, and a report that treats Grade 3
-- English and Grade 4 English as unrelated things.
--
-- The reason the suffix looked necessary is real, though — the syllabus is not
-- the same at Grade 1 and Grade 6, and one description field on the subject
-- could only ever describe one of them. So the syllabus moves to where it
-- belongs: onto the pairing of subject and grade, which is the only place a
-- statement like "what English covers in Grade 3" is actually true.
--
-- After this there is one English, offered to Grades 1 to 6, carrying six
-- syllabus entries. A teacher assigned English teaches English. A report about
-- English is about English.
-- ---------------------------------------------------------------------------

-- ------------------------------------------- the syllabus, per subject+grade
-- TEXT rather than VARCHAR because this is prose written by a teacher — a
-- competency list, a term breakdown, a set of learning outcomes — and a length
-- limit here would be an arbitrary decision about somebody else's curriculum.

ALTER TABLE subject_grade_levels
  ADD COLUMN syllabus TEXT NULL
    COMMENT 'What this subject covers at this grade level. Null means not yet written.'
    AFTER grade_level_id;

-- --------------------------------------------------- generalise the codes
-- UPDATE IGNORE, so a school that has already created a subject under the
-- general code keeps it and the grade-suffixed one is simply left alone rather
-- than the migration failing. Nothing is merged automatically: two subjects
-- that ought to be one are a judgement only the school can make, and doing it
-- here would silently move schedules between subjects.
--
-- Safe to rename because nothing keys on a subject code — schedules, sessions
-- and attendance records all carry subject_id (see migration 027 and the
-- reasoning in AcademicStructureService::updateSubject).

UPDATE IGNORE subjects SET subject_code = 'VAL',  updated_at = NOW() WHERE subject_code = 'VAL03'  AND deleted_at IS NULL;
UPDATE IGNORE subjects SET subject_code = 'ENG',  updated_at = NOW() WHERE subject_code = 'ENG03'  AND deleted_at IS NULL;
UPDATE IGNORE subjects SET subject_code = 'MATH', updated_at = NOW() WHERE subject_code = 'MATH03' AND deleted_at IS NULL;
UPDATE IGNORE subjects SET subject_code = 'READ', updated_at = NOW() WHERE subject_code = 'READ03' AND deleted_at IS NULL;
UPDATE IGNORE subjects SET subject_code = 'COMP', updated_at = NOW() WHERE subject_code = 'COMP03' AND deleted_at IS NULL;
UPDATE IGNORE subjects SET subject_code = 'HGP',  updated_at = NOW() WHERE subject_code = 'HGP03'  AND deleted_at IS NULL;
UPDATE IGNORE subjects SET subject_code = 'FIL',  updated_at = NOW() WHERE subject_code = 'FIL03'  AND deleted_at IS NULL;
UPDATE IGNORE subjects SET subject_code = 'SCI',  updated_at = NOW() WHERE subject_code = 'SCI03'  AND deleted_at IS NULL;
UPDATE IGNORE subjects SET subject_code = 'SOC',  updated_at = NOW() WHERE subject_code = 'SOC03'  AND deleted_at IS NULL;

-- The names lose the grade too, for the same reason: "Mathematics" is the
-- subject, Grade 3 is one of the grades it is taught at.
UPDATE subjects SET subject_name = 'Values Education',          updated_at = NOW() WHERE subject_code = 'VAL'  AND deleted_at IS NULL;
UPDATE subjects SET subject_name = 'English',                   updated_at = NOW() WHERE subject_code = 'ENG'  AND deleted_at IS NULL;
UPDATE subjects SET subject_name = 'Mathematics',               updated_at = NOW() WHERE subject_code = 'MATH' AND deleted_at IS NULL;
UPDATE subjects SET subject_name = 'Reading',                   updated_at = NOW() WHERE subject_code = 'READ' AND deleted_at IS NULL;
UPDATE subjects SET subject_name = 'Computer',                  updated_at = NOW() WHERE subject_code = 'COMP' AND deleted_at IS NULL;
UPDATE subjects SET subject_name = 'Homeroom Guidance Program', updated_at = NOW() WHERE subject_code = 'HGP'  AND deleted_at IS NULL;
UPDATE subjects SET subject_name = 'Filipino',                  updated_at = NOW() WHERE subject_code = 'FIL'  AND deleted_at IS NULL;
UPDATE subjects SET subject_name = 'Science',                   updated_at = NOW() WHERE subject_code = 'SCI'  AND deleted_at IS NULL;
UPDATE subjects SET subject_name = 'Social Science',            updated_at = NOW() WHERE subject_code = 'SOC'  AND deleted_at IS NULL;

-- ---------------------------------------------------- offer them Grades 1-6
-- Elementary only: numeric_level 1 to 6, so Grades 7 upward are untouched and
-- keep whatever subjects they already have.
--
-- Every one of the nine is offered to all six as a starting point rather than
-- a claim about the curriculum. Reading may well stop after Grade 3 and
-- Computer may not start until Grade 4; unticking a grade on the subject form
-- is one click, and guessing wrong in this direction is visible and harmless,
-- where guessing the other way leaves a teacher unable to build a timetable
-- with no clue why.

INSERT IGNORE INTO subject_grade_levels (subject_id, grade_level_id, created_at)
SELECT s.subject_id, g.grade_level_id, NOW()
  FROM subjects s
  JOIN grade_levels g ON g.numeric_level BETWEEN 1 AND 6
 WHERE s.subject_code IN ('VAL','ENG','MATH','READ','COMP','HGP','FIL','SCI','SOC')
   AND s.deleted_at IS NULL;
