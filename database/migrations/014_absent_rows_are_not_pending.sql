-- ===========================================================================
-- L-SIAMS · 014 · An absent student is not waiting to leave
-- ===========================================================================
-- Closing a session writes an Absent row for every rostered student who never
-- tapped, and those rows carried departure_status = 'pending'.
--
-- 'pending' is the state a present student sits in while the class runs: in
-- the room, tap-out still to come. Every screen renders it that way. On a
-- student who never arrived it produced a row that read "Absent" and "still in
-- room" at the same time, which is not a display problem so much as the row
-- saying two contradictory things.
--
-- 'no_time_out' says the only thing that is true: there is no tap-out, and
-- there is never going to be one.
--
-- Final status is untouched and cannot change. AttendanceStatusResolver
-- short-circuits on an absent arrival before it looks at the departure at all,
-- so these rows read Absent before and Absent after. Nothing is recalculated
-- and no attendance is rewritten — the correction is to a field that was
-- describing the wrong thing.
--
-- Scoped to rows that have neither a tap-in nor a tap-out, so a genuine
-- in-progress record in an open session is left alone.
-- ===========================================================================

SET NAMES utf8mb4;

UPDATE attendance_records
   SET departure_status = 'no_time_out'
 WHERE arrival_status   = 'absent'
   AND departure_status = 'pending'
   AND time_in  IS NULL
   AND time_out IS NULL;
