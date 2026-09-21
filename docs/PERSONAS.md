# Personas

Who L-SIAMS is actually for, what each of them is trying to get done, and
which parts of the system they touch.

These are not marketing sketches. Every capability, threshold and limitation
below is traceable to something in this repository — a route in
[`routes/web.php`](../routes/web.php), a value in [`config/`](../config), a
status in
[`AttendanceStatusResolver`](../app/Services/AttendanceStatusResolver.php). The
point of writing them down is that a feature argument is easier to settle by
naming the person it is for than by naming the screen it lives on.

The system recognises exactly **two account roles** — `administrator` and
`teacher` — and that is deliberate. Three of the five people described here
never log in at all. They are in this document because they are affected by
the system whether or not the system knows about them, and a design that only
considers the two people holding passwords will fail the other three quietly.

---

## At a glance

| | Persona | Role in the system | Logs in? | Touches the terminal? |
|---|---|---|---|---|
| 1 | **Ms. Ramos**, class adviser and subject teacher | `teacher` | Daily, on a phone | Every period |
| 2 | **Mr. Dela Cruz**, ICT coordinator and system administrator | `administrator` | Daily, on the server PC | When something is broken |
| 3 | **Ms. Bautista**, records officer | `administrator` | In bursts, at enrolment | To issue cards |
| 4 | **Angelo**, Grade 8 student | none | Never | Twice a period |
| 5 | **Mrs. Villanueva**, school head | none — reads PDFs | Never | Never |

---

## 1. Ms. Ramos — class adviser, Grade 8

> "I have forty-one students and four minutes before the bell. Do not make me
> take out a laptop."

**Role:** `teacher`. Everything under `/teacher` in
[`routes/web.php`](../routes/web.php).

### Her day

She reaches the room at 7:20. The Grade 8 terminal is already awake — it
heartbeats every 30 seconds and the server considers it offline after 90
(`config/attendance.php` → `device.heartbeat_interval_sec`,
`device.offline_after_sec`), so if the room's terminal died overnight the
dashboard already knows.

At 7:25 she puts her thumb on the sensor. That single gesture is the whole
session-open ceremony: it proves she is physically in that room, at that
minute, for a class the timetable says is hers. The register opens and the
tap-in window is already open, because tap-in opens 10 minutes before the
period starts.

Students tap as they come in. She is not watching the terminal — she is
watching her phone, which has the session panel open. The panel follows the
scan on its own: a tap opens the class without her pressing anything. Taps
make a sound so she can keep her eyes on the door, and a refused tap-out
announces itself rather than failing silently.

At 7:40 — 15 minutes after the start — the system stops writing `present` and
starts writing `late`. At 7:55 it refuses tap-in entirely. She does not
enforce either rule; she does not argue about either rule.

Mid-period a student is called to the clinic. She releases them from the
session on her phone (`POST /teacher/sessions/{id}/release`), which records a
departure rather than leaving the student looking like they walked out.

At the bell the session closes. Anyone who never tapped is written `Absent`;
anyone still open is settled by the auto-close rules. Her second period is in
the same room twenty minutes later, so the register **carries over** — students
who were `Present` or `Late` are still present without tapping again
(`config/attendance.php` → `carry_over`, gap limit 30 minutes, hard reset at
12:00 so the morning never leaks into the afternoon).

### What she needs

- **The session to open in one gesture, from the room.** Not a login, not a
  code, not a form.
- **A failover for the gesture.** Wet hands, a plaster, a cut, a sensor that
  died overnight. Her own account password, typed at her own dashboard, opens
  the class instead
  ([`SessionOverrideService`](../app/Services/SessionOverrideService.php)) —
  and relaxes *only* the proof of identity. The class must still be scheduled
  now, in this room, with her assigned to it. The session records which proof
  was used, so nobody auditing attendance later has to guess.
- **To be believed.** Her corrections are visible and attributable, not silent
  edits.
- **Her own reports, and only hers.** She can preview, generate and download
  from `/teacher/reports`; download is ownership-checked, so she cannot pull a
  report another teacher generated.

### Where she is still underserved

- She cannot edit an attendance row herself. Corrections
  (`POST /admin/attendance/{id}/correct`) are an administrator action, so a
  wrong status becomes a message to Mr. Dela Cruz.
- Excusing a student is not a teacher action either. `Excused` and
  `Official Business` exist as statuses; putting one on a record does not.
- Nothing reaches her when she is not on the school network. Notifications are
  in-app (`/notifications`), and the system has no mail, no SMS and no push by
  design.

---

## 2. Mr. Dela Cruz — ICT coordinator, and the administrator account

> "If this needs the internet on a Monday morning, it does not work here."

**Role:** `administrator`. Everything under `/admin`, plus the machine itself.

### His day

He is the reason the system runs at all, and he is not a full-time system
administrator — he teaches two sections of TLE. His interface is
`start.bat`, three console windows, and a browser.

When something is wrong, his first move is `doctor.bat`: it checks PHP, the
keys, the database, the migrations, whether `APP_KEY` still opens the encrypted
data, every registered terminal and the realtime server — and changes nothing.
That last property is the one he cares about.

His dashboard is the live one (`/admin/attendance/live`) and the Security
Center (`/admin/security`). He watches for four things:

| What he watches | Where it surfaces |
|---|---|
| A terminal that stopped heartbeating | Devices, offline after 90 s |
| A student tapping in a section that is not theirs | Notification after 3 taps/day (`section_mismatch_alert_threshold`) |
| Repeated fingerprint failures at one terminal | Locks the device after 5, alerts after 3 |
| Unknown card UIDs | `/admin/rfid/unknown`, each one resolvable |

### What he needs

- **No cloud and no internet dependency.** There are no Composer
  dependencies and no `vendor/` directory; the XLSX writer, the PDF writer, the
  WebSocket server and the chart renderer are all in this repository because
  the target network may have no route out at all.
- **A key rotation path that does not mean re-flashing.** Devices rotate and
  revoke keys from the browser, with a key history and a downloadable
  provisioning file per terminal.
- **Backups he can prove.** Create, schedule, **verify**, download, restore
  (`/admin/backup`). Verify matters more than create: an unverified backup is
  a belief, not a backup.
- **To not be the bottleneck for a teacher who cannot open a register.** This
  is exactly why the password failover exists, and why it is rate limited per
  account rather than per IP — the whole school sits behind one address, and
  one teacher mistyping must not lock out the rest.
- **An audit trail that survives him.** Attendance history and audit logs are
  immutable and exportable.

### Where he is still underserved

- `start.bat` runs PHP's built-in web server, which is single threaded. It is
  fine for one person demonstrating and wrong for a corridor of terminals;
  moving to Apache or nginx is a documented manual step
  ([`docs/DEPLOYMENT.md`](DEPLOYMENT.md)), not something the system does for
  him.
- A classroom power cut still loses held taps. The terminal holds up to 40 taps
  in the ESP32's RTC memory and replays them with their original timestamps,
  and RTC memory survives a reset or a watchdog reboot — but not a power cut.
- The terminal has no screen. When a terminal misbehaves, his console is a
  Serial Monitor at 115200 and an onboard LED blink pattern.

---

## 3. Ms. Bautista — records officer

> "Four hundred and twelve students, one afternoon, and every card has to land
> on the right name."

**Role:** `administrator` — the same account type as Mr. Dela Cruz, doing a
completely different job. She is listed separately because nearly every
bulk-data screen in the system exists for her, not for him.

### Her work

It is not daily. It is three or four intense days a year: enrolment, sectioning,
card issuance, and the end-of-year rollover.

- **Import, previewed before it commits.** Student import is deliberately two
  steps — `POST /admin/students/import/preview`, then
  `/import/commit`. She sees what will happen before anything happens. A
  template is downloadable, and worked CSV and XLSX samples live in
  [`docs/samples/`](samples).
- **Card issuance driven from the browser.** She clicks Read; the terminal
  holds its reader to itself for 45 seconds and does nothing else — no
  heartbeat, no sync, no polling — so a card presented at any moment in that
  window is actually read instead of landing in the gap where the board was
  busy talking to the server. A UID that has been read is then held for 30
  minutes while she works out whose it is, because the card is in somebody's
  hand and losing it means walking back.
- **Students who have no card yet**, as a list she can work down
  (`/admin/rfid/without-card`).
- **Transfer, archive, restore — never delete.** A student who leaves is
  archived; their attendance history stays intact and their card can be
  released and reissued.
- **Printable student cards** and a per-student, per-school-year summary, from
  the reports wizard.

### What she needs

- To never be asked to do the same thing four hundred times.
- To be told what an action will break *before* she takes it. This is why
  archiving a teacher, a subject or a department shows an impact check first.
- A school-year boundary that is a real boundary (`/admin/settings/school-year`).

---

## 4. Angelo — Grade 8 student

> He does not know this system has a name.

**Role:** none. He has no account, no password, and no screen. He is the
highest-volume user of the system and the only one who cannot report a bug.

### His entire interaction

Tap on the way in. Tap on the way out. That is all of it.

Everything he experiences is a consequence of decisions made elsewhere:

| What he feels | What is actually happening |
|---|---|
| "It worked" | The server — not the terminal — decided this was an arrival, and the teacher's dashboard chimed |
| "It said no" | Outside the window: earlier than 10 minutes before the bell, or later than 30 minutes after it |
| "It did nothing" | Session not open — no teacher has verified in this room yet |
| "It counted me anyway" | The link was down; his tap was held in RTC memory with the time it actually happened and replayed later, marked `synced_offline` |

### What he needs, whether or not anyone asks him

- **A tap that is honest.** The terminal never judges his tap. It does not
  decide arrival versus departure, and it does not decide late. It reports;
  the server rules. He cannot get a better outcome by tapping at a different
  terminal.
- **Not to be flattened into one word.** Arrival, departure and final status
  are three separate facts, which is why "arrived late and left early" survives
  as `Late` + `left_early` → **Left Early** rather than collapsing into an
  ambiguous label. The full set he can end up in: `Present`, `Late`, `Absent`,
  `Excused`, `Official Business`, `Incomplete`, `Left Early`.
- **Credit for actually being there.** A stay shorter than 20 minutes
  (`minimum_dwell_minutes`) is not a class attended, and a forgotten tap-out at
  a session the system closed for him does not turn a real morning into
  `Incomplete`.
- **Not to be punished for a dead network.** His retry can never double-record:
  every tap carries an idempotency key.

### Where he is still underserved

- He gets no feedback he can read. No display, no buzzer — the confirmation he
  relies on is a sound played on the *teacher's* phone and an LED blink
  pattern. If the teacher has the tap sound off and is not looking, Angelo
  genuinely does not know whether his tap landed.
- He has no way to see his own record, and no way to dispute one. Both go
  through Ms. Ramos, and the correction itself goes through an administrator.
- A power cut in his classroom can erase a tap he made correctly.

---

## 5. Mrs. Villanueva — school head

> "I need one page I can hand to the division office."

**Role:** none. She is handed PDFs. If she ever gets an account it will be an
`administrator` one, which is a mismatch worth being honest about.

### What she asks for

She never asks for a screen; she asks for a document, on paper, with the
school's name on it. The report catalogue
([`ReportService::TYPES`](../app/Services/ReportService.php)) is written for
her questions, not for the database's shape:

| Her question | The report |
|---|---|
| "How is Grade 8 doing overall?" | Section Attendance Summary, Section Comparison |
| "Which students are we losing?" | Chronic Absence by Section — below **80 %** (`chronic_absence_threshold_percent`) |
| "Is Ms. Ramos opening her registers?" | Teacher Attendance, Adviser Report |
| "Show me last Tuesday." | Daily Attendance, Section Daily Attendance Sheet |
| "Did the equipment work?" | Device Uptime |
| "Who changed this record?" | Audit Log, Security Log |

All sixteen export as PDF, Excel or CSV, generated without a third-party
library.

### What she needs

- **A number that survives being questioned.** When the division office
  challenges a figure, the chain behind it — audit log, immutable attendance
  history, which proof opened which session — has to exist.
- **Nothing that requires her to learn the system.** A wizard that produces the
  right document from a question, not from a knowledge of table names.

### Where she is still underserved

- There is no role for her. Giving her a login today means giving her
  `administrator`, which means giving her key rotation and database restore to
  answer "how is Grade 8 doing". The honest workaround is that somebody else
  runs the report and hands it over.

---

## Reading the roster as a design test

Four rules fall straight out of the five people above, and most of the
arguments worth having about this system are really arguments about one of
them.

1. **The person with the most at stake has the least access.** Angelo cannot
   log in, cannot see his record and cannot report a fault. Any change that
   makes his tap more ambiguous is a bigger regression than it looks, because
   nobody will report it.

2. **A single point of failure in front of a full class is not acceptable, no
   matter how good the primary control is.** The fingerprint is the right
   primary control and the wrong only control. Every new gate should be asked
   the same question the sensor was asked: *what happens to the whole class
   when this fails at 7:25?*

3. **Two roles is a simplification, and it has a cost.** Ms. Bautista and
   Mr. Dela Cruz share an account type while sharing almost no tasks;
   Mrs. Villanueva is locked out entirely because the only key available is too
   large. When a third role is eventually added, these are the two seams it
   should be cut along — not a general permission system nobody asked for.

4. **Offline is the normal case, not the exception.** The building loses its
   link and the terminals keep working; that is the premise, not a feature.
   Anything that assumes the server is reachable at the moment of a tap is
   wrong for this school.
