-- ===========================================================================
-- L-SIAMS · 015 · Let a terminal say what its sensor is actually holding
-- ===========================================================================
-- The server records which teacher owns which sensor slot. The R307 holds the
-- templates themselves, in its own flash, and nothing ever compared the two.
--
-- They come apart easily and silently. A template stored while the completion
-- callback was lost leaves the sensor holding a print the server has no row
-- for. Erasing the sensor leaves the server listing teachers whose prints are
-- gone. Either way the Fingerprints page reads "Active" for everybody and the
-- reader answers NOT RECOGNISED, with nothing on any screen connecting the
-- two facts.
--
-- The heartbeat already arrives every 30 seconds and already carries what the
-- terminal knows about itself. One integer more is all that is needed to make
-- the divergence visible instead of leaving it to be inferred from a serial
-- log.
--
-- NULL means "this terminal has not told us" — an older firmware, or one that
-- has not sent a heartbeat since upgrading. That is deliberately different
-- from 0, which means the terminal answered and its sensor is empty.
-- ===========================================================================

SET NAMES utf8mb4;

ALTER TABLE devices
    ADD COLUMN sensor_template_count SMALLINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Templates the fingerprint sensor reported holding at the last heartbeat; NULL if never reported'
        AFTER queue_depth,
    ADD COLUMN sensor_reported_at DATETIME NULL DEFAULT NULL
        COMMENT 'When that count last arrived'
        AFTER sensor_template_count;
