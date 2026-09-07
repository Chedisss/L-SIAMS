-- ---------------------------------------------------------------------------
-- 036: a security event is triaged, not rewritten
--
-- security_logs was always meant to be updatable — an administrator resolves an
-- event, marks it a false positive, adds a note. That is why migration 007 gave
-- it a BEFORE DELETE guard but, unlike audit_logs, no BEFORE UPDATE guard: the
-- row is delete-proof, and its triage fields are meant to change.
--
-- The database privilege lockdown missed that distinction. It revoked UPDATE on
-- security_logs alongside audit_logs and login_history, on the reasoning that
-- all three are "log tables", and the resolve action then failed with
-- "1142 UPDATE command denied". The account has to be allowed to update the
-- table again — but a bare UPDATE grant would also let the event's own content
-- be rewritten, and a security log whose description can be edited is not
-- evidence of anything.
--
-- So the same treatment attendance_records already has: the table is updatable,
-- and a trigger freezes everything that identifies the event, leaving only the
-- triage columns free to change. What happened, when, to whom, from where — all
-- immutable; whether somebody has dealt with it — editable, and itself written
-- to the audit log by the service that changes it.
--
-- This restores the resolve workflow AND makes the guarantee precise rather than
-- broad: a security event cannot be deleted, and its content cannot be altered.
-- Only its resolution status and the administrator's note can move.
-- ---------------------------------------------------------------------------

DELIMITER $$

DROP TRIGGER IF EXISTS trg_security_immutable_content $$

CREATE TRIGGER trg_security_immutable_content
BEFORE UPDATE ON security_logs
FOR EACH ROW
BEGIN
  -- Everything that says what the event WAS is frozen. `<=>` is the
  -- null-safe comparison, so a column that is NULL on both sides counts as
  -- unchanged rather than tripping the guard.
  IF  NEW.security_id    <> OLD.security_id
  OR  NEW.event          <> OLD.event
  OR  NEW.severity       <> OLD.severity
  OR  NOT (NEW.description   <=> OLD.description)
  OR  NOT (NEW.context_json  <=> OLD.context_json)
  OR  NOT (NEW.source_ip     <=> OLD.source_ip)
  OR  NOT (NEW.user_id       <=> OLD.user_id)
  OR  NOT (NEW.device_row_id <=> OLD.device_row_id)
  OR  NOT (NEW.device_id_text <=> OLD.device_id_text)
  OR  NOT (NEW.endpoint      <=> OLD.endpoint)
  OR  NOT (NEW.http_method   <=> OLD.http_method)
  OR  NOT (NEW.user_agent    <=> OLD.user_agent)
  OR  NOT (NEW.created_at    <=> OLD.created_at)
  THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'A security event is immutable except for its resolution status and notes.';
  END IF;
END $$

DELIMITER ;
