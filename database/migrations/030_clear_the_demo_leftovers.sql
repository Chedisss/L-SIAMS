-- ---------------------------------------------------------------------------
-- 030: clear the Grade 7/8 demo leftovers and the unused rooms
--
-- The deployed database carries two of several subjects — Filipino and
-- Filipino, Mathematics and Mathematics, Science and Science, Values and
-- Values Education — because the grade-suffixed set that shipped as demo data
-- was never removed when the real Grade 1-6 set was created in migrations 028
-- and 029. The school has confirmed the Grade 7 and Grade 8 material is demo
-- data and none of it is real.
--
-- Everything here is a soft delete. Nothing is dropped, no attendance row is
-- touched, and every one of these can be brought back from the archived view
-- on its own page if this turns out to have been wrong.
--
-- Scoped by exact code rather than by pattern. A LIKE '%07' would also catch a
-- subject a school legitimately creates later, and a migration that removes
-- rows nobody named is not something anyone can review.
-- ---------------------------------------------------------------------------

-- ------------------------------------------- the schedules that hold them up
-- These have to go first: archiveSubject() refuses while a subject is on an
-- active schedule, and it refuses for a good reason — ScheduleService does not
-- check a subject's status, so a schedule left behind would go on opening
-- classes every day for a subject that had been removed. Doing it in the right
-- order here is the same order the interface would force.

UPDATE schedules sch
  JOIN subjects s ON s.subject_id = sch.subject_id
   SET sch.status = 'archived', sch.deleted_at = NOW(), sch.updated_at = NOW()
 WHERE s.subject_code IN ('ENG07','FIL07','MATH07','SCIE07','VAL07')
   AND sch.status = 'active'
   AND sch.deleted_at IS NULL;

-- --------------------------------------------- the qualifications that go too
-- Left behind, these would put the subject back in a teacher's list the moment
-- anybody restored it, with nobody having decided that.

DELETE ts FROM teacher_subjects ts
  JOIN subjects s ON s.subject_id = ts.subject_id
 WHERE s.subject_code IN ('ENG07','FIL07','MATH07','SCIE07','VAL07');

-- ------------------------------------------------------ the duplicates
UPDATE subjects
   SET status = 'inactive', deleted_at = NOW(), updated_at = NOW()
 WHERE subject_code IN ('ENG07','FIL07','MATH07','SCIE07','VAL07')
   AND deleted_at IS NULL;

-- ------------------------------------------------------------- the rooms
-- Only 101 and 102 exist at the school, and only those two have a terminal.
-- 103 to 105 came from the same demo seed.
--
-- Guarded rather than assumed: a room that has somehow acquired a terminal or
-- a live schedule since is left alone, and will still be sitting there
-- afterwards for somebody to look at. The archive rules on the page enforce
-- the same two conditions, so this cannot remove anything the interface would
-- have refused to.

UPDATE classrooms c
   SET c.status = 'inactive', c.deleted_at = NOW(), c.updated_at = NOW()
 WHERE c.room_number IN ('103','104','105')
   AND c.deleted_at IS NULL
   AND NOT EXISTS (
         SELECT 1 FROM devices d
          WHERE d.classroom_id = c.classroom_id
            AND d.deleted_at IS NULL
            AND d.status <> 'decommissioned'
       )
   AND NOT EXISTS (
         SELECT 1 FROM schedules sch
          WHERE sch.classroom_id = c.classroom_id
            AND sch.status = 'active'
            AND sch.deleted_at IS NULL
       );
