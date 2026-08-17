-- ===========================================================================
-- L-SIAMS · 016 · One kind of terminal
-- ===========================================================================
-- Registering a device asked what it was for, and the two answers split one
-- board's job in half. An enrolment scanner took fingerprints and issued cards
-- but had no classroom, and a session belongs to a room — so a board registered
-- that way could never open one. It was refused with DEVICE_CLASSROOM_MISMATCH,
-- which reads like a hardware fault and sent people back to the wiring.
--
-- The split was never needed to make enrolment work. captureDevices() has
-- always offered every claimed device, sorting stations first only as a
-- convenience, so a classroom terminal could already take fingerprints. The
-- station flag bought nothing except a way to register a board that could not
-- do the main job.
--
-- Every device is now a classroom terminal. The column stays rather than being
-- dropped: v_device_status selects it, the Devices list reads it, and a
-- migration that drops a column those depend on turns a tidy-up into an outage.
-- Setting it to 0 everywhere is the whole change.
--
-- Classroom is deliberately left alone. A station has none, and there is no
-- room this migration could pick that would not be a guess about the building.
-- The Devices list now says so on any terminal missing one, which is a
-- question for the administrator rather than something to invent here.
-- ===========================================================================

SET NAMES utf8mb4;

UPDATE devices
   SET enrollment_station = 0,
       updated_at = NOW()
 WHERE enrollment_station <> 0;
