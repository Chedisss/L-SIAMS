-- A terminal that admits its sensors are dead.
--
-- A board can join the Wi-Fi, claim its key, sync its clock and heartbeat
-- every thirty seconds — everything the server measures — while both of its
-- modules are unresponsive. The server called that terminal "online", because
-- by every signal it had, it was.
--
-- The effect showed up at the other end. Issuing a card and enrolling a
-- fingerprint are both started in the browser and performed by the terminal,
-- so both open a modal that says "Waiting for the terminal…" and polls. A
-- terminal whose reader is dead never picks the request up, and the modal
-- waits forever with nothing to say. There was already a warning for a
-- terminal that had stopped reporting; this case slipped past it precisely
-- because the terminal WAS reporting.
--
-- The board knows. It probes both modules at boot and prints the result to
-- its serial monitor, where nobody watching the browser can see it. These
-- columns carry that verdict up to the server so the modal can name the real
-- problem instead of spinning.
--
-- NULL is a third state and carries its own meaning: firmware older than this
-- change does not report module health, and "did not say" must not be stored
-- as "said no" — that would paint every terminal on older firmware as broken.

ALTER TABLE devices
    ADD COLUMN rfid_ok TINYINT(1) NULL DEFAULT NULL
        COMMENT 'Card reader answered at boot. NULL = firmware did not report.'
        AFTER sensor_reported_at,
    ADD COLUMN fingerprint_ok TINYINT(1) NULL DEFAULT NULL
        COMMENT 'Fingerprint sensor answered at boot. NULL = firmware did not report.'
        AFTER rfid_ok,
    ADD COLUMN modules_reported_at DATETIME NULL DEFAULT NULL
        COMMENT 'When the two columns above were last written.'
        AFTER fingerprint_ok;

-- Finding the terminals worth chasing: a module that reported itself as failed
-- is a maintenance queue, and it is a short list against a long table.
CREATE INDEX idx_devices_module_health ON devices (rfid_ok, fingerprint_ok);
