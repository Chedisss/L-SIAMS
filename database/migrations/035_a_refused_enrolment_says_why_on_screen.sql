-- ---------------------------------------------------------------------------
-- 035: a refused enrolment says why, on the screen, and stays said
--
-- The server already refused a fingerprint that belonged to somebody else, and
-- already wrote the reason with the other teacher's name in it. What it had no
-- way to say was *which kind* of refusal this was, so the enrolment wizard
-- could only render the sentence as one more line of grey status text and then
-- clear it two and a half seconds later, on the way back to the setup form.
--
-- A duplicate fingerprint is the one outcome in this dialog that the person
-- standing at the terminal must not miss. It means the finger in front of them
-- is already registered to a colleague, and every explanation for that is
-- serious: the wrong teacher is at the sensor, somebody enrolled on another
-- member of staff's behalf, or two records exist for one person. Told in
-- passing and then withdrawn, it reads as a glitch worth retrying, which is
-- exactly the wrong response.
--
-- failure_code carries the machine-readable half so the browser can tell the
-- cases apart without reading the prose. The message column keeps saying what
-- it said; nothing about the existing text changes. The codes in use:
--
--     DUPLICATE_FINGERPRINT   the finger is enrolled to another teacher
--     DUPLICATE_UNVERIFIED    matched something, and the server could not be
--                             asked whose it was, so nothing was written
--     CAPTURE_FAILED          no usable print in the time allowed
--     DUPLICATE_CHECK_MISSING firmware too old to check; refused on arrival
--
-- Nullable and unconstrained on purpose. Rows written before this migration
-- have no code and must still render — they fall back to the plain failure
-- wording — and the firmware is free to report a reason this list does not
-- name yet without the enrolment failing to record at all.
-- ---------------------------------------------------------------------------

ALTER TABLE fingerprint_enrollment_requests
  ADD COLUMN IF NOT EXISTS failure_code VARCHAR(40) NULL
    COMMENT 'Machine-readable reason a request ended in failed; NULL for older rows'
    AFTER message;

-- Backfill the one case that is unambiguous from the text already stored. The
-- sentence is generated in exactly one place (FingerprintEnrollmentService::
-- duplicateCheck) and has never had another form, so matching it is safe here
-- in a way that matching prose at read time would not be.
UPDATE fingerprint_enrollment_requests
   SET failure_code = 'DUPLICATE_FINGERPRINT'
 WHERE status = 'failed'
   AND failure_code IS NULL
   AND message LIKE 'This fingerprint is already enrolled to %';
