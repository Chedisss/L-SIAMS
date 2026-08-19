-- ===========================================================================
-- L-SIAMS · 017 · Open a session when the finger cannot be read
-- ===========================================================================
-- A fingerprint is a good primary control and a bad only control. Sweat, a
-- cut, a burn, a plaster, a sensor that has failed overnight — any one of them
-- leaves a teacher standing in front of a full class with no way to open the
-- register, and a class whose attendance is never recorded at all.
--
-- The failover is the teacher's own account password, typed at their own
-- dashboard. It proves the same thing the finger proves — that this teacher is
-- here — through a credential the school already issued and already trusts for
-- everything else that account can do.
--
-- What it does NOT relax is anything else. The class must be scheduled now, in
-- this room, with this teacher assigned to it, and the room's terminal must be
-- registered — exactly the checks the fingerprint path makes. Only the proof
-- of identity changes.
--
-- Two columns, so that change is never invisible:
--
--   opened_method      how the teacher proved they were there. Existing rows
--                      are all fingerprint openings, which is why the default
--                      is 'fingerprint' — backfilling them as anything else
--                      would rewrite history that did happen at a reader.
--   opened_by_user_id  the account that authorised the override, NULL for a
--                      fingerprint opening. teacher_id already says whose
--                      class it is; this says who typed the password, and
--                      RESTRICT keeps that account from being deleted out
--                      from under the record.
--
-- Anyone auditing attendance can now ask "which sessions were opened without a
-- scan" in one query instead of inferring it from a NULL fingerprint_log_id,
-- which was also NULL for other reasons.
-- ===========================================================================

SET NAMES utf8mb4;

ALTER TABLE attendance_sessions
    ADD COLUMN opened_method ENUM('fingerprint','password') NOT NULL DEFAULT 'fingerprint'
        COMMENT 'How the teacher proved identity when opening this session'
        AFTER fingerprint_log_id,
    ADD COLUMN opened_by_user_id INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'The account that authorised a password override; NULL for a fingerprint opening'
        AFTER opened_method,
    ADD KEY idx_session_opened_method (opened_method, session_date),
    ADD CONSTRAINT fk_session_opened_by FOREIGN KEY (opened_by_user_id)
        REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE RESTRICT;
