-- ===========================================================================
-- L-SIAMS · 024 · Let an administrator wipe a sensor that has drifted
-- ===========================================================================
-- Migration 015 gave terminals a way to report how many templates their sensor
-- holds, and the Fingerprints page has been able to say "this sensor holds four
-- but one is recorded" ever since. It has also been recommending a fix nobody
-- could carry out: "clearing the sensor and enrolling everybody again is the
-- reliable fix", with no button anywhere that clears a sensor.
--
-- Orphans are not cosmetic. A slot holding a template the server has no record
-- of is still matchable by the reader, and a scan that lands on one is refused
-- as unrecognised — a genuinely enrolled teacher, turned away for matching the
-- wrong copy of their own finger. The automatic cleanup only reaches slots left
-- by abandoned enrolment requests; anything older, or written by a build that
-- allocated a fresh slot on every re-enrolment, is invisible to it.
--
-- The server cannot reach into a sensor's flash, so this is a request the
-- terminal collects and acts on, in the same shape as everything else here:
-- the terminal polls, wipes, and confirms. One column carries the request and
-- one carries the acknowledgement, so a wipe that was asked for but never
-- performed is distinguishable from one that completed — a terminal that is
-- switched off must not look like a terminal that has finished.
--
-- Wiping is safe in a way it would not have been before migration 018. Every
-- template the server holds is queued straight back onto the sensor and
-- rewritten within a couple of minutes. The exception is a template that
-- exists ONLY in the sensor being wiped — an enrolment predating template
-- storage — which is destroyed permanently, so the application refuses the
-- request unless the administrator has been shown the count and accepted it.
-- ===========================================================================

SET NAMES utf8mb4;

ALTER TABLE devices
    ADD COLUMN sensor_wipe_requested_at DATETIME NULL DEFAULT NULL
        COMMENT 'An administrator asked this terminal to erase its sensor; cleared when the terminal confirms'
        AFTER sensor_template_count,
    ADD COLUMN sensor_wipe_requested_by INT UNSIGNED NULL DEFAULT NULL
        AFTER sensor_wipe_requested_at,
    ADD COLUMN sensor_wiped_at DATETIME NULL DEFAULT NULL
        COMMENT 'When the terminal last confirmed it erased its sensor'
        AFTER sensor_wipe_requested_by;

ALTER TABLE devices
    ADD CONSTRAINT fk_devices_wipe_requested_by
        FOREIGN KEY (sensor_wipe_requested_by) REFERENCES users (user_id)
        ON DELETE SET NULL ON UPDATE CASCADE;
