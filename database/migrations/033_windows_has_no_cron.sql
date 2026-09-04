-- ---------------------------------------------------------------------------
-- 033: Windows has no cron
--
-- The Daily backup time setting carried the hint "Run the worker with cron for
-- this to take effect." That is advice from a different operating system. This
-- system is deployed on Windows and XAMPP, which has no cron, so the
-- instruction could not be followed by the person reading it and the automatic
-- backup never ran on any installation that took it at face value.
--
-- It now points at install-worker.bat, which registers a Windows scheduled
-- task that starts the worker at boot.
-- ---------------------------------------------------------------------------

UPDATE settings
   SET description = 'Needs the background worker running - run install-worker.bat once, or keep worker.bat open.'
 WHERE setting_key = 'backup.schedule_time';
