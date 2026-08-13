-- ===========================================================================
-- L-SIAMS · 011 · Enrolment stations
-- ===========================================================================
-- Enrolment is driven entirely from the web UI, but the capture itself has to
-- happen at a fingerprint sensor — a browser cannot read a finger. That left
-- the only sensors in the building bolted to classroom walls, so enrolling
-- somebody meant walking them to a classroom.
--
-- An enrolment station is the same ESP32 and R307 sitting on the administrator's
-- desk instead. It takes no classroom, records no attendance, and exists purely
-- so the person being enrolled can put their finger down next to the computer
-- the enrolment is being driven from.
--
-- Kept as a flag on devices rather than a fourth device_role: role describes
-- which way a tap counts (entry, exit, or both), and a station that records no
-- taps has no answer to that question. Keeping them separate also means a
-- station can be pressed into service as a classroom terminal later by clearing
-- one column, without a role that then means nothing.
-- ===========================================================================

SET NAMES utf8mb4;

ALTER TABLE devices
  ADD COLUMN enrollment_station TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Desk-side scanner used for enrolment only; takes no classroom and records no attendance'
    AFTER device_role;

-- Enrolment pickers list stations first, and there are only ever a handful.
ALTER TABLE devices
  ADD KEY idx_device_enrollment_station (enrollment_station, deleted_at);
