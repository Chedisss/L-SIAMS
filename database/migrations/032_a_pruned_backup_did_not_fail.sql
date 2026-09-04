-- ---------------------------------------------------------------------------
-- 032: a pruned backup did not fail
--
-- A backup taken successfully and later removed by the retention policy was
-- recorded as status = 'failed', with the reason in error_message. It did not
-- fail — it did exactly what the policy asked of it.
--
-- The effect is on the Backups page, where every pruned archive shows in red
-- alongside genuine failures. A school that keeps 30 backups and takes one a
-- day sees a growing list of "failed" rows for a retention policy that is
-- working perfectly, which is the fastest way to stop somebody trusting their
-- backups — and the point of a backup system is that it is trusted.
-- ---------------------------------------------------------------------------

ALTER TABLE backups
  MODIFY COLUMN status ENUM('running','completed','failed','verified','restored','pruned')
    NOT NULL DEFAULT 'running';

UPDATE backups
   SET status = 'pruned', error_message = 'Archive removed by the retention policy.'
 WHERE status = 'failed'
   AND error_message = 'Archive pruned by the retention policy.';
