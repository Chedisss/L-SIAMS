-- ===========================================================================
-- L-SIAMS · 025 · Grades 1 to 6
-- ===========================================================================
-- The grade levels seeded at install were Grade 7 to Grade 12 — junior and
-- senior high school, which is what the pilot at TAPS needed and all it needed.
-- A school that also runs elementary had no way to record a Grade 3 section
-- short of editing the table by hand, and a grade level is the spine that
-- sections, subjects, schedules and every report hang from.
--
-- Added rather than replaced. The existing six keep their ids, so every
-- section, schedule and attendance record that already points at them is
-- untouched; the new rows take ids of their own. numeric_level carries the
-- ordering, so Grade 1 sorts before Grade 7 wherever the application orders by
-- it rather than by insertion.
--
-- track stays NULL for all six. It describes the senior-high strands — Academic,
-- TVL, Sports, Arts and Design — which do not apply below Grade 11, and writing
-- one in would be inventing a fact about a nine-year-old's curriculum.
--
-- INSERT IGNORE against the natural key: an installation that has already added
-- some of these by hand keeps what it has instead of failing the migration
-- half way through.
-- ===========================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO grade_levels (grade_level_code, grade_level_name, numeric_level, track, status)
VALUES
    ('G1', 'Grade 1', 1, NULL, 'active'),
    ('G2', 'Grade 2', 2, NULL, 'active'),
    ('G3', 'Grade 3', 3, NULL, 'active'),
    ('G4', 'Grade 4', 4, NULL, 'active'),
    ('G5', 'Grade 5', 5, NULL, 'active'),
    ('G6', 'Grade 6', 6, NULL, 'active');
