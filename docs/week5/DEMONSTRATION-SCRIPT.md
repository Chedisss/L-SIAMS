# Week 6 Prototype Demonstration — Script

Eight minutes of live system, after the slides. Written to be read almost
word-for-word, with the fallback for each step if the hardware misbehaves.

**Roles.** [Member 4] drives the terminal and the cards. [Member 5] drives the
laptop and the slides. [Group Leader] narrates and takes questions.

---

## Before the room fills

Do all of this at least twenty minutes early, in this order.

- [ ] MySQL running; `php bin/console doctor` reports no problems.
- [ ] Web server, realtime server and the maintenance worker all started.
- [ ] Browser at the dashboard, signed in, **zoomed to 125 %** so the back row can read it.
- [ ] Terminal powered, on the school Wi-Fi, showing its idle screen.
- [ ] The demonstration cards in a known order, face down, with the unregistered card last and clearly distinguishable by touch.
- [ ] The teacher's finger that is actually enrolled — confirm with one throwaway scan, then close the session it opens.
- [ ] A schedule window that contains the demonstration time. If the presentation slot falls outside class hours, set the clock shift beforehand and **say so out loud** during step 2 — the banner is visible on screen and someone will ask.
- [ ] Second browser tab already open on the reports page, so step 8 does not need navigation.

**If the terminal will not connect at all**, say so plainly and run the
demonstration from the recorded screenshots in `docs/week5/screenshots/`. Do not
spend presentation time debugging hardware.

---

## Step 1 — Sign in · 30 seconds

> "This is L-SIAMS running on a single machine inside the school. No cloud
> service, no internet. What you are seeing is a live database — a hundred and
> thirty-one students, seven teachers, five terminals."

Sign in. Let the dashboard load.

> "Terminals online, five of five. Attendance rate, live. This number is not
> typed in by anyone — it comes from cards touching readers."

**Fallback:** `screenshots/02-dashboard.png`.

---

## Step 2 — The schedule and the terminal · 45 seconds

Open **Schedules**, then **IoT Devices**.

> "This terminal is bound to Room 101. It is claimed, which means it has been
> through provisioning and holds a key that only it has. The class scheduled in
> this room right now is English 7 for section G7-RIZAL, with Maria Reyes."

> "That binding matters. A terminal cannot record attendance for a class that is
> not scheduled in its own room."

If the clock is shifted, point at the banner:

> "You will see a notice that the clock is shifted. We are demonstrating outside
> school hours, so the system has been told to run at Monday morning. Everything
> else is real."

**Fallback:** `screenshots/08-devices.png`, `screenshots/09-schedules.png`.

---

## Step 3 — The teacher opens the register · 1 minute

Open the **Sessions** page on the laptop so the room can watch it change.

> "Right now there is no register open. Nothing a student does can create an
> attendance record — watch."

[Member 4] taps a **student card** on the terminal first.

> "Refused. 'No session — awaiting teacher.' The card is valid and the student
> is on the roster, and it still refuses, because the teacher is not here yet."

Now the teacher presents their **fingerprint**.

> "The register opens. And look at what the terminal shows: the teacher's name,
> the subject, the section. The teacher did not type anything. They did not sign
> in. Their finger, on this terminal, in this room, at this time, is the only
> thing that opens a register in L-SIAMS."

Refresh the sessions page — the open session appears.

> "One more thing worth noticing: the session record says how it was opened —
> by fingerprint. If a teacher ever has to fall back to a password because the
> reader will not read them, that is recorded as a different kind of opening.
> The register always says how the teacher proved they were there."

**Fallback:** `screenshots/03-session-detail.png` — point at "Opened by:
Fingerprint".

---

## Step 4 — Students tap in · 1 minute 30 seconds

[Member 4] taps four or five cards, unhurried, one every two seconds.

> "Each tap is a card being presented, and the terminal is only reporting that
> — the server decides what it means."

Switch to the live session view.

> "The names are appearing as they tap. Notice the pill in the corner reads
> 'Live' — this page is on a WebSocket. Nobody is refreshing anything."

> "In the room: eighteen. Not yet seen: twelve. Those twelve become absences
> automatically when the register closes — nobody marks them."

**Fallback:** `screenshots/03-session-detail.png`, `screenshots/04-attendance-records.png`.

---

## Step 5 — The same card again, immediately · 45 seconds

[Member 4] re-taps a card that has just tapped in.

> "'Too early to exit — zero of twenty minutes.' The system will not let a
> student arrive and leave in the same breath. Twenty minutes is a setting; the
> point is that the rule lives on the server."

> "This is the design decision I would most like you to take away. The terminal
> has no idea whether that tap was an arrival or a departure. It reports that a
> card was presented, and the server decides — from the session state, the
> schedule window and the terminal's own role. A terminal with a wrong clock, or
> with tampered firmware, cannot manufacture an attendance status."

**Fallback:** describe it; the refusal codes are in `docs/API.md`.

---

## Step 6 — An unregistered card · 30 seconds

[Member 4] taps the **unregistered** card.

> "'Unknown card.' Not silently ignored — logged, with the card's number and the
> terminal that saw it."

Open **Security Centre** (or **RFID Cards → Unknown**).

> "It is already here. If somebody starts presenting unknown cards to a
> terminal, that is visible."

**Fallback:** `screenshots/13-security-center.png`, `screenshots/11-rfid-cards.png`.

---

## Step 7 — Close the register · 45 seconds

The teacher presents their fingerprint again, or [Member 5] closes it from the
session page.

> "Closing resolves the whole roster at once. The students who tapped in but
> never tapped out are stamped automatically and labelled as such — the system
> does not pretend to know when they left. The twelve who never appeared are
> marked absent. Thirty students, all accounted for, and nobody typed a name."

> "And this is the part that matters for a record that a school has to stand
> behind: that row cannot now be deleted. It cannot be re-attributed to a
> different student. The times and the status can be corrected by an
> administrator — with a reason the database insists on — and the correction is
> recorded separately, alongside who made it and what it was before."

**Fallback:** `screenshots/05-sessions.png`.

---

## Step 8 — A report · 45 seconds

Switch to the reports tab. Generate a **Section Daily Attendance Sheet** as PDF.
Open it.

> "Fifteen kinds of report, in PDF, Excel or CSV. This PDF was produced by code
> in this repository — there is no third-party library in this system at all,
> because the school network may have no route to the internet."

**Fallback:** `screenshots/10-reports.png`, and the PDFs generated during Week 5
testing.

---

## Closing · 30 seconds

> "That is the whole path: a teacher proves they are present, students tap in
> and out, the server decides every tap, the record is written once and cannot
> be re-attributed, and fifteen kinds of report come out the other end."

> "During Week 5 we tested this rather than assumed it, and it found seven
> defects — three of which would have broken this demonstration. One of them
> meant no teacher could have opened a register on demonstration data at all.
> All seven are fixed, and forty-four of forty-four automated tests pass."

> "What is left is on the terminal side: the offline queue is implemented and
> tested on the server, and still needs to go into the shipping firmware."

> "Questions."

---

## Questions to expect, and the honest answer

**"What if a student forgets their card?"**
> The adviser records it as an administrator correction, with a reason. It is
> recorded as a correction rather than a normal tap, which is deliberate —
> nothing should be able to create attendance without the card except a named
> person taking responsibility for it.

**"What if the terminal loses Wi-Fi mid-class?"**
> Each tap is stamped at the moment it happens and carries an idempotency key,
> so the queue can be replayed later without double-recording. The server side
> is complete and tested — twenty-five queued records replay to exactly
> twenty-five rows. The queue in the shipping terminal firmware is the one piece
> of work still outstanding, and we would rather say so than claim it.

**"Can a teacher open a register for a class they do not teach?"**
> No. The schedule is checked against the teacher, the section, the room and the
> time. It is refused, and the refusal is logged.

**"Could someone edit the database directly?"**
> Someone with database credentials could read it. Changing history is a
> different matter: deletion and re-attribution are refused by triggers inside
> the database itself, and the account the application uses holds no privilege
> to modify a log at all. During Week 5 we found that two of the three log
> tables were missing one of those triggers, and we added it.

**"Is the fingerprint stored?"**
> The sensor's template, not an image, and it is encrypted with the application
> key. It cannot be turned back into a fingerprint. That is a real trade and
> it is documented — it is what lets a teacher open a register in more than one
> room.

**"How many students can it handle?"**
> Measured: 148 taps per second sustained, with a 95th-percentile processing
> time of under 8 milliseconds. A busy classroom produces about 110 requests a
> minute.
