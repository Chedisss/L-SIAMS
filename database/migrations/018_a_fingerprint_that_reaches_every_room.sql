-- ===========================================================================
-- L-SIAMS · 018 · Let a teacher's fingerprint reach every terminal they teach at
-- ===========================================================================
-- Until now the sensor was the only place a template existed. The server kept
-- a slot number and nothing else, and the schema said so proudly: "No
-- biometric data ever reaches this database, which keeps the system's exposure
-- to a template breach at zero."
--
-- That guarantee is being given up on purpose, and it should be recorded
-- honestly rather than quietly. A teacher teaches in more than one room. With
-- the template living only in the sensor it was enrolled on, that teacher
-- could open a register in one classroom and nowhere else, and there was no
-- fix short of walking them to every terminal in the building.
--
-- What changes, exactly:
--
--   The template now lives in this database as well as in the sensors. It is
--   encrypted with the application key (AES-256-GCM, via Crypto::encrypt), so
--   a stolen .sql dump is not enough on its own — but a host compromise that
--   yields both the dump and APP_KEY does now leak biometric data, where
--   before there was none to leak. That is the trade, stated plainly.
--
--   A template is not a fingerprint image and cannot be turned back into one;
--   it is the sensor's own feature vector. That reduces the harm, it does not
--   remove it, and it is not a reason to be casual with the column.
--
-- Two structures:
--
--   fingerprint_templates gains the template itself. One row per teacher, the
--   master copy, still recording where it was originally enrolled.
--
--   fingerprint_slots records where that template physically sits: which slot
--   on which sensor. This is the table verification reads, because a slot
--   number means nothing without the device that allocated it — every sensor
--   counts from 1 upward in its own flash, so slot 3 in one room and slot 3 in
--   another are different people. That confusion caused a real
--   misidentification bug, and giving the mapping its own table with a unique
--   key on (device, slot) makes the ambiguity unrepresentable.
--
-- Existing enrolments are backfilled from the columns already on
-- fingerprint_templates, so nothing already working stops working. Their
-- template_data is NULL — the bytes were never captured and cannot be
-- recovered from the sensor's slot number alone. Those teachers keep working
-- on the terminal they enrolled at and must be re-enrolled once to reach the
-- others; the Fingerprints page can find them with template_data IS NULL.
-- ===========================================================================

SET NAMES utf8mb4;

ALTER TABLE fingerprint_templates
    ADD COLUMN template_data MEDIUMTEXT NULL DEFAULT NULL
        COMMENT 'The sensor template, encrypted with the app key. NULL for enrolments made before sync existed.'
        AFTER sensor_template_id,
    ADD COLUMN template_bytes SMALLINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Plaintext length in bytes, so a truncated upload is caught before it is written to a sensor'
        AFTER template_data,
    ADD COLUMN template_captured_at DATETIME NULL DEFAULT NULL
        AFTER template_bytes;

CREATE TABLE IF NOT EXISTS fingerprint_slots (
  slot_row_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fingerprint_id    INT UNSIGNED NOT NULL,
  device_row_id     INT UNSIGNED NOT NULL,
  sensor_template_id SMALLINT UNSIGNED NOT NULL COMMENT 'The slot this sensor holds it in. Per-sensor: meaningless without device_row_id.',
  -- 'enrolled' is the sensor the finger was actually presented to; 'synced' is
  -- a copy this server pushed. Worth distinguishing: a sync that silently
  -- failed leaves a row claiming a template the sensor does not have, and the
  -- heartbeat's template count is how that gets noticed.
  source            ENUM('enrolled','synced') NOT NULL DEFAULT 'synced',
  status            ENUM('pending','present','failed') NOT NULL DEFAULT 'pending',
  last_error        VARCHAR(255) NULL,
  synced_at         DATETIME NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- One template per slot on a given sensor, and one slot per template on a
  -- given sensor. Both directions, because both are corruption.
  UNIQUE KEY uq_slot_on_device (device_row_id, sensor_template_id),
  UNIQUE KEY uq_template_on_device (fingerprint_id, device_row_id),
  KEY idx_slot_pending (device_row_id, status),
  CONSTRAINT fk_slot_fingerprint FOREIGN KEY (fingerprint_id) REFERENCES fingerprint_templates(fingerprint_id)
      ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_slot_device      FOREIGN KEY (device_row_id)  REFERENCES devices(id)
      ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill. Every enrolment that already names the terminal it was made on
-- becomes the 'enrolled' row for that terminal, marked present because the
-- sensor genuinely does hold it.
INSERT IGNORE INTO fingerprint_slots
    (fingerprint_id, device_row_id, sensor_template_id, source, status, synced_at, created_at)
SELECT fp.fingerprint_id,
       fp.enrolled_device_row_id,
       fp.sensor_template_id,
       'enrolled',
       'present',
       fp.enrollment_date,
       NOW()
  FROM fingerprint_templates fp
 WHERE fp.enrolled_device_row_id IS NOT NULL;

-- A capture taken during teacher registration has no teacher row to attach to
-- yet — the template sits in the sensor while somebody finishes the form. The
-- bytes have to wait somewhere too, or they are lost at exactly the moment the
-- teacher is created and the sync would otherwise begin.
ALTER TABLE fingerprint_enrollment_requests
    ADD COLUMN template_data MEDIUMTEXT NULL DEFAULT NULL
        COMMENT 'Encrypted template held between capture and bind(); cleared once it reaches fingerprint_templates';
