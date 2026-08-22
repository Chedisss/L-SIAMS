-- ===========================================================================
-- L-SIAMS · 019 · Finish the append-only triggers
-- ===========================================================================
-- The security model says the audit log, the security log and the login
-- history are append-only three times over: no service method writes them
-- twice, BEFORE UPDATE and BEFORE DELETE triggers raise SQLSTATE 45000, and
-- the application's database user holds only INSERT and SELECT on them.
--
-- Two of the three were only protected twice. Migration 007 gave audit_logs
-- both triggers, but security_logs and login_history got a BEFORE DELETE and
-- no BEFORE UPDATE. So an UPDATE against either one succeeded at the database
-- level — silently, and against the table whose whole purpose is to be the
-- record nobody can quietly edit. The grant layer still refused the
-- application's own user, which is why nothing failed visibly; anyone holding
-- a more privileged connection was unimpeded.
--
-- Deleting a security-log row was already impossible. Rewriting one to say
-- something else was not. That is the more useful of the two to an attacker,
-- and it is the one that was open.
-- ===========================================================================

SET NAMES utf8mb4;

DELIMITER $$

DROP TRIGGER IF EXISTS trg_security_no_update $$
CREATE TRIGGER trg_security_no_update
BEFORE UPDATE ON security_logs
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Security logs are immutable and cannot be modified.';
END $$

DROP TRIGGER IF EXISTS trg_login_history_no_update $$
CREATE TRIGGER trg_login_history_no_update
BEFORE UPDATE ON login_history
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Login history is permanent and cannot be modified.';
END $$

DELIMITER ;
