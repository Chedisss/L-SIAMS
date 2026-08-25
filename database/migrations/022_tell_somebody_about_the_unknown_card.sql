-- ===========================================================================
-- L-SIAMS · 022 · Tell somebody about the unknown card
-- ===========================================================================
-- An unregistered card is presented at a classroom terminal. It is refused,
-- which is right, and then nobody hears about it — which is not. It is either
-- a new student whose card was never enrolled, standing outside a lesson they
-- are being marked absent from, or somebody at the reader trying cards. Both
-- want an administrator to know today, not at the end of term when the unknown
-- card list is next opened.
--
-- Two columns, and they exist entirely to stop the alert being useless:
--
--   last_notified_at  when an administrator was last told about this UID. A
--                     card tapped forty times in one lesson is one problem,
--                     not forty, and forty notifications would train everyone
--                     to ignore the category within a week.
--   notified_count    how many times it has been raised. Kept because "we have
--                     told you about this card six times" is a different
--                     sentence from "a card was tapped", and the escalation
--                     needs to know which one it is saying.
--
-- Nothing is notified once an administrator has triaged the card — resolution
-- moves off 'pending' — because at that point they know, and the system
-- repeating itself is noise from something they have already answered.
-- ===========================================================================

SET NAMES utf8mb4;

ALTER TABLE unknown_rfid_logs
    ADD COLUMN last_notified_at DATETIME NULL DEFAULT NULL
        COMMENT 'When an administrator was last alerted about this UID'
        AFTER last_seen_at,
    ADD COLUMN notified_count INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'How many alerts this UID has produced'
        AFTER last_notified_at;

-- ---------------------------------------------------------------------------
-- Settings
-- ---------------------------------------------------------------------------
-- The cooldown is the whole difference between a useful alert and a category
-- everybody mutes. An hour is long enough that a lesson's worth of taps is one
-- notification, and short enough that a card presented across the morning and
-- again after lunch is two.
--
-- The repeat threshold is a separate signal, not a louder version of the same
-- one. One presentation of an unregistered card is usually an enrolment that
-- was never finished. Ten is somebody standing at a reader.
-- ---------------------------------------------------------------------------

INSERT INTO settings
    (setting_key, setting_value, value_type, group_name, label, description,
     is_sensitive, min_value, max_value)
VALUES
    ('security.unknown_card_alert_cooldown_minutes', '60', 'int', 'security',
     'Unknown card alert cooldown (minutes)',
     'The same unrecognised card raises at most one administrator alert in this window. Set to 0 to alert on every tap, which is rarely what anybody wants.',
     0, '0', '1440'),
    ('security.unknown_card_repeat_threshold', '5', 'int', 'security',
     'Unknown card repeat threshold',
     'After this many sightings of the same unrecognised card, the alert is raised as high priority and logged as a security event rather than an enrolment gap.',
     0, '2', '100')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
