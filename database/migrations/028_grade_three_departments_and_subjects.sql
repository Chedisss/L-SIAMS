-- ---------------------------------------------------------------------------
-- 028: the departments and subjects the Grade 3 timetable actually needs
--
-- Taken from the TAPS Grade 3 - Gold class schedule, SY 2026-2027, adviser
-- Ms. Ericka P. Venida. Nine teaching slots appear on it, and the reference
-- data did not cover them: Computer and Araling Panlipunan existed as
-- departments with no subjects in them at all, Reading and HGP existed
-- nowhere, and every subject already present carried a Grade 7 or Grade 10
-- code.
--
-- INSERT IGNORE throughout, keyed on the unique codes, so this is safe to run
-- against a database that already holds some of it and safe to run twice. It
-- changes nothing that is already there — a department renamed by hand keeps
-- its name, a subject moved to another department stays moved.
--
-- What is NOT here, and why:
--
--   BCA (Before Class Activity), Break Time, Lunch Break and Home Time appear
--   on the timetable but are not subjects. None has a teacher against it, and
--   attendance is opened by a teacher's fingerprint — a period nobody teaches
--   cannot open one. They are routine blocks, and modelling them as subjects
--   would put four rows in every dropdown that can never be scheduled.
--
--   HGP is different and IS here: Ms. Khaycee is named against it, so it is a
--   taught period like any other.
--
-- Codes carry the grade, following the convention already in use on the
-- deployed database (ENG07, FIL07, MATH07). So these are Grade 3's subjects
-- specifically; Grade 4 gets ENG04 and so on. Each is offered to Grade 3 only,
-- which is what makes the schedule builder's grade-level filter correct.
-- ---------------------------------------------------------------------------

-- --------------------------------------------------------------- departments
-- Seven of these already exist on the deployed database and are left exactly
-- as they are. Homeroom Guidance is the only new one.
--
-- HGP is placed under Araling Panlipunan rather than given a department of its
-- own, and that is a deliberate choice about one constraint: a teacher may
-- only be assigned subjects from their own department. Ms. Khaycee teaches
-- both Social Science and HGP, so a separate Homeroom Guidance department
-- would force a cross-department exception for her on day one. Under AP she
-- needs none. The two also sit together naturally in Philippine elementary
-- practice.
--
-- It is created anyway, unused, because a school that later wants Homeroom
-- Guidance to stand on its own should not have to invent it under pressure.

INSERT IGNORE INTO departments (department_code, department_name, description, status, created_at, updated_at) VALUES
  ('ESP',  'Values Education Department',    'Edukasyon sa Pagpapakatao.',                'active', NOW(), NOW()),
  ('ENG',  'English Department',             'Language, literature and communication.',   'active', NOW(), NOW()),
  ('MATH', 'Mathematics Department',         'General mathematics, statistics and calculus.', 'active', NOW(), NOW()),
  ('FIL',  'Filipino Department',            'Wika at panitikang Filipino.',              'active', NOW(), NOW()),
  ('SCI',  'Science Department',             'Biology, chemistry, physics and earth science.', 'active', NOW(), NOW()),
  ('AP',   'Araling Panlipunan Department',  'Social studies, history, civics and homeroom guidance.', 'active', NOW(), NOW()),
  ('COMP', 'Computer Department',            'Computer literacy and the modern world.',   'active', NOW(), NOW()),
  ('HG',   'Homeroom Guidance Department',   'Homeroom guidance and learner formation. Created for schools that run this separately from Araling Panlipunan; TAPS Grade 3 does not.', 'inactive', NOW(), NOW());

-- ------------------------------------------------------------------ subjects
-- One row per teaching slot on the Grade 3 - Gold timetable, in the order the
-- school day meets them.
--
--   Values          Ms. Ericka    daily, 08:00
--   English         Ms. Alyssa    daily, 08:45
--   Math            Sir Darwin    daily, 09:50
--   Reading         Ms. Crizel    Mon/Wed/Fri, 10:35
--   Computer        Ms. Crizel    Tue/Thu, 10:35
--   HGP             Ms. Khaycee   Mon/Wed/Fri, 12:45
--   Filipino        Ms. Jezza     daily, 13:00
--   Science         Ms. Liemer    daily, 13:45
--   Social Science  Ms. Khaycee   daily, 14:50
--
-- Reading sits under English rather than in a department of its own. That
-- leaves Ms. Crizel teaching across two departments — Reading here and
-- Computer under COMP — which is the one cross-department assignment this
-- timetable genuinely requires. Reading is the heavier half of her load
-- (three periods to Computer's two), so English is the department to put her
-- in, and Computer becomes the exception rather than the other way round.

INSERT IGNORE INTO subjects (subject_code, subject_name, description, department_id, status, created_at, updated_at)
SELECT v.code, v.name, v.descr, d.department_id, 'active', NOW(), NOW()
  FROM (
    SELECT 'VAL03'  AS code, 'Values Education'  AS name, 'Edukasyon sa Pagpapakatao for Grade 3.'        AS descr, 'ESP'  AS dept UNION ALL
    SELECT 'ENG03',         'English',                    'English language and communication, Grade 3.',        'ENG'  UNION ALL
    SELECT 'MATH03',        'Mathematics',                'Grade 3 mathematics.',                                'MATH' UNION ALL
    SELECT 'READ03',        'Reading',                    'Guided reading and comprehension, Grade 3.',          'ENG'  UNION ALL
    SELECT 'COMP03',        'Computer',                   'Computer literacy, Grade 3.',                         'COMP' UNION ALL
    SELECT 'HGP03',         'Homeroom Guidance Program',  'Homeroom guidance, Grade 3.',                         'AP'   UNION ALL
    SELECT 'FIL03',         'Filipino',                   'Wika at panitikang Filipino, Baitang 3.',             'FIL'  UNION ALL
    SELECT 'SCI03',         'Science',                    'Grade 3 science.',                                    'SCI'  UNION ALL
    SELECT 'SOC03',         'Social Science',             'Araling Panlipunan for Grade 3.',                     'AP'
  ) AS v
  JOIN departments d ON d.department_code = v.dept AND d.deleted_at IS NULL;

-- ------------------------------------------------- offered to Grade 3 only
-- Without this the subject exists but the schedule builder will not offer it,
-- because a schedule needs a subject offered to the section's grade level.
-- That is the commonest reason a newly created subject appears to do nothing.

INSERT IGNORE INTO subject_grade_levels (subject_id, grade_level_id, created_at)
SELECT s.subject_id, g.grade_level_id, NOW()
  FROM subjects s
  JOIN grade_levels g ON g.grade_level_code = 'G3'
 WHERE s.subject_code IN ('VAL03','ENG03','MATH03','READ03','COMP03','HGP03','FIL03','SCI03','SOC03')
   AND s.deleted_at IS NULL;
