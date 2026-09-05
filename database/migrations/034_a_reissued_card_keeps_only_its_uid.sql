-- ---------------------------------------------------------------------------
-- 034: a re-issued card carries its UID and nothing else
--
-- Releasing a card back to stock cleared student_id, which was right, and threw
-- away the only record of who had held it, which was not. On the RFID Cards
-- page the row went to "unassigned" with an em dash where the name had been,
-- and there was nowhere left in the interface to find out whose card it had
-- been — the audit log knew, and nothing a person looks at did.
--
-- So the holder is remembered rather than erased. released_student_id is who
-- last carried the card and released_at is when they stopped; both survive the
-- card being issued to somebody else, because "this used to be Ocampo's card"
-- stays true and stays useful.
--
-- The other half of the same fault is in the query rather than the schema and
-- is fixed alongside this: the Taps and Last tap columns counted every tap the
-- physical card had ever made, so a card re-issued after a term showed its new
-- owner fifteen taps they had never made. Those counts are now scoped to the
-- student who actually holds it. The card's full history is still readable, in
-- the tap history view, which is per-UID on purpose — that is the one place the
-- question is "what has this piece of plastic done", not "what has this pupil
-- done".
-- ---------------------------------------------------------------------------

ALTER TABLE rfid_cards
  ADD COLUMN IF NOT EXISTS released_student_id INT UNSIGNED NULL
    COMMENT 'Who last held this card before it went back to stock'
    AFTER student_id,
  ADD COLUMN IF NOT EXISTS released_at DATETIME NULL
    COMMENT 'When it was released; NULL for a card never released'
    AFTER released_student_id;

-- Dropped before it is added, which is how a constraint gets added idempotently
-- without a prepared statement: MariaDB has DROP FOREIGN KEY IF EXISTS but no
-- ADD CONSTRAINT IF NOT EXISTS, and a migration that fails part-way is re-run
-- from the top. The obvious alternative — SET @x := (SELECT ...) then PREPARE —
-- leaves an unbuffered result set the runner cannot step past, and fails the
-- migration on the statement after it rather than on this one.
ALTER TABLE rfid_cards DROP FOREIGN KEY IF EXISTS fk_rfid_released_student;

ALTER TABLE rfid_cards
  ADD CONSTRAINT fk_rfid_released_student FOREIGN KEY (released_student_id)
    REFERENCES students(student_id) ON UPDATE CASCADE ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS idx_rfid_released ON rfid_cards (released_student_id);

-- ------------------------------------------------------ recover what was lost
-- Cards released before this migration had their holder cleared with nothing
-- kept, so those names are already gone from the page. They are not gone from
-- the audit log: RFID_RELEASED records the previous student_id in old_value,
-- precisely so a question like this one can be answered later.
--
-- Recovered from there rather than left blank, because a school that released
-- a drawer of cards yesterday should not have to accept a permanent hole in
-- the register as the price of a fix shipped today.
--
-- Only the most recent release per card is used, and only where the student
-- still exists; a card released twice is described by the last release.
UPDATE rfid_cards rc
   JOIN (
        SELECT record_id,
               MAX(audit_id) AS audit_id
          FROM audit_logs
         WHERE action = 'RFID_RELEASED'
           AND record_type = 'rfid_card'
         GROUP BY record_id
        -- Compared as numbers, not as strings. record_id is a varchar in
        -- utf8mb4_unicode_ci and CAST(... AS CHAR) yields utf8mb4_general_ci,
        -- so the string form raises "illegal mix of collations".
   ) latest ON CAST(latest.record_id AS UNSIGNED) = rc.rfid_id
   JOIN audit_logs a ON a.audit_id = latest.audit_id
   JOIN students st
     ON st.student_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(a.old_value, '$.student_id')) AS UNSIGNED)
    SET rc.released_student_id = st.student_id,
        rc.released_at         = a.created_at
  WHERE rc.released_student_id IS NULL
    AND JSON_VALID(a.old_value)
    AND JSON_EXTRACT(a.old_value, '$.student_id') IS NOT NULL;
