# Sample import files

## `student-import-G7-RIZAL.xlsx`

Fifteen Grade 7 students for section `G7-RIZAL`, ready to upload at
**Admin → Students → Import**. Every row validates against a database seeded
with the demo data; if your sections differ, change the `section_code` column
to one of your own.

### Why one section per file

The import is all-or-nothing: a single bad row aborts the whole file and
nothing is written. In a 40-row section file you fix one section and the rest
are already safely in, and you can fix it while the adviser who wrote the list
is still reachable. In a 500-row master list, one missing guardian contact on
line 312 means starting over.

It also matches how the cards get handed out — one class at a time in front of
the reader, not the whole school queued at once.

### The columns

| Column | Required | Notes |
| --- | --- | --- |
| `student_number` | yes | Must be unique. Checked against the database *and* against the other rows in the file. |
| `first_name` | yes | |
| `middle_name` | no | |
| `last_name` | yes | |
| `suffix` | no | `Jr.`, `III`, and so on. |
| `gender` | no | `Male`, `Female`, `Other`. Anything else becomes *Prefer not to say*. |
| `birthdate` | no | `YYYY-MM-DD` only. |
| `email` | no | Validated if present. |
| `address` | no | |
| `guardian_name` | yes | |
| `guardian_contact` | yes | |
| `guardian_email` | no | |
| `grade_level_code` | no | Advisory. If it disagrees with the section, the row is flagged rather than guessed at. |
| `section_code` | yes | This is what places the student. Must exist, be active, and have room. |
| `card_uid` | no | **Leave blank.** See below. |
| `status` | no | `active` or `inactive`. Defaults to `active`. |

### Leave `card_uid` empty

It is blank on all fifteen rows on purpose. To fill it you would have to read
each card's UID anyway, one at a time, then hand-transcribe 8–16 hex characters
into the right row — and the cards are physically identical blanks, so once a
UID is typed next to a name there is no way to tell which card in the stack it
was. A typo produces a UID that does not exist, and the student taps and gets
*unknown card* until somebody works out why.

Import the names with the column empty, then issue the cards by scanning:

1. **Admin → RFID Cards**, set the **Waiting for a card** filter to the section
   you just imported.
2. Press **Issue card** on the first row — the tap dialog opens with the
   student already named, so you only pick the reader.
3. Tap the card, press **Issue this card**.
4. The row leaves the list and the next student is armed automatically with the
   reader still selected. One press and one tap for the rest of the class.

The UID cannot be mistyped that way, the card is in hand at the moment it is
bound, and the student's name is on the terminal display so whoever is holding
the card is checking it against the name as they go.

### Regenerating

The file is written by `XlsxWriter`, the same class that produces the app's own
Excel exports, so it parses with exactly the reader the importer uses.
