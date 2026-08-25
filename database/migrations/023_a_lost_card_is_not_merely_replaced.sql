-- ===========================================================================
-- L-SIAMS · 023 · A lost card is not merely "replaced"
-- ===========================================================================
-- Issuing a card to a student who already holds one retires the old row and
-- chains the new one to it, which is right and has always worked. What it did
-- not do is ask why — every retired card was recorded as 'replaced', whatever
-- had happened to it.
--
-- That single word covers four different situations, and one of them matters
-- far more than the others:
--
--   lost          the card is somewhere. Somebody may find it and present it.
--   stolen        somebody has it deliberately.
--   damaged       it is in a bin, and no risk to anyone.
--   not_returned  a student left without handing it back.
--
-- The status the old card ends in follows from that, and only the reason can
-- decide it: a damaged card is 'replaced', a lost one is 'lost', and a stolen
-- one is 'blacklisted' so it can never be issued to anybody again. All three
-- are refused at the reader — a tap requires status 'active' — but a school
-- asked "which of our cards are unaccounted for" needs the register to be able
-- to answer, and 'replaced' answers nothing.
--
-- Two columns on the card being retired:
--
--   replacement_reason  from a fixed list, for the same reason every other
--                       reason in this system is: free text produces "lost",
--                       "Lost", "lost it" and "student lost card" in one term
--                       and cannot be counted.
--   replacement_note    the administrator's own words. Required when the
--                       reason is 'other', which otherwise records nothing.
--
-- Both stay NULL on a card that is still in service and on the first card a
-- student is ever issued, because neither is being replaced.
-- ===========================================================================

SET NAMES utf8mb4;

ALTER TABLE rfid_cards
    ADD COLUMN replacement_reason
        ENUM('lost','damaged','stolen','not_returned','other') NULL DEFAULT NULL
        COMMENT 'Why this card was taken out of service; NULL while it is in service'
        AFTER replacement_date,
    ADD COLUMN replacement_note VARCHAR(255) NULL DEFAULT NULL
        COMMENT "The administrator's own words; required when the reason is 'other'"
        AFTER replacement_reason,
    ADD KEY idx_rfid_replacement_reason (replacement_reason, replacement_date);
