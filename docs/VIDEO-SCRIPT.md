# L-SIAMS — Video Presentation Script

**Title:** L-SIAMS: Local IoT-Based Multi-Layer Secured Attendance Monitoring
System with RFID and Biometric Authentication
**Target length:** about 9–10 minutes
**Format:** narrator voice-over with screen recording, plus live shots of the
classroom terminal

> **How to read this script.** Each scene lists what goes on screen (**VISUAL**)
> and what the narrator says (**NARRATION**). Times are approximate. Lines in
> *[brackets]* are directions for the person recording, not words to speak.

---

## Before recording

- Start the system with `start.bat` (or your Apache/nginx deployment), and start
  **MySQL** from the XAMPP Control Panel.
- Fill the system with sample data: `console.bat seed --demo`. This creates
  teachers with enrolled fingerprints, sections of students with RFID cards,
  schedules, and 30 school days of attendance, so every screen has data on it.
- Have one ESP32 terminal (MFRC522 RFID reader + AS608/R307 fingerprint sensor)
  flashed with `L_SIAMS_Bench`, registered on the **Classroom Terminals** page,
  and showing **online**.
- Before the live demo, check that a teacher is scheduled for that terminal's
  classroom **right now**. A session will only open inside the scheduled window.
- Prepare two browser windows: one signed in as an **Administrator**, one as a
  **Teacher**.
- Keep one student RFID card and one unregistered card on hand.

---

## Scene 1 — Opening (0:00 – 0:30)

**VISUAL:** Title card with the L-SIAMS logo and full system name. Then a slow
shot of a classroom door and the terminal on the wall.

**NARRATION:**
> Every school day starts with the same question: who is here? For most schools
> the answer is still a paper list and a pen. That is slow, easy to fake, and
> hard to turn into reports.
>
> This is L-SIAMS, a Local IoT-Based Multi-Layer Secured Attendance Monitoring
> System. It uses RFID cards and fingerprint authentication, and it runs
> entirely inside the school.

---

## Scene 2 — The Problem (0:30 – 1:15)

**VISUAL:** Simple animated icons, one at a time: a paper attendance sheet, a
student signing for an absent friend, a teacher paging through notebooks at the
end of the term, and a cloud with a red "no internet" mark.

**NARRATION:**
> Manual attendance has four weaknesses.
>
> First, it takes time out of every class. Second, it can be faked: a friend can
> answer "present" for someone who isn't there. Third, the records sit in
> notebooks, so building a monthly or per-student report means doing it by hand.
>
> Fourth, many "smart" attendance systems depend on a cloud service. When the
> school's internet goes down, attendance stops, and student data ends up on
> someone else's server.

---

## Scene 3 — Objectives (1:15 – 2:30)

**VISUAL:** An "Objectives" slide. Each objective appears as it is read, with an
icon: card, fingerprint, shield, wifi-off, chart, laptop.

**NARRATION:**
> L-SIAMS was built to meet six objectives.

| # | Objective (on screen) | Narration |
|---|---|---|
| 1 | **Automate attendance with RFID** | Students tap an RFID card on the classroom terminal to record when they arrive and when they leave. No roll call and no paper. |
| 2 | **Verify the teacher with biometrics** | An attendance session opens only when the teacher proves they are in the room with their fingerprint, and only for a class they are scheduled to teach. |
| 3 | **Secure every layer** | Each device request is signed, passwords are hashed, and the audit and attendance history cannot be edited or deleted after the fact. |
| 4 | **Run fully offline on the school LAN** | No cloud and no internet dependency. The web app, database and realtime server all run on one machine inside the school. |
| 5 | **Monitor in real time** | Administrators and teachers watch taps appear live, as they happen. |
| 6 | **Produce accurate reports** | Sixteen report types, exported as PDF, Excel or CSV, straight from the recorded data. |

---

## Scene 4 — How It Works (2:30 – 3:30)

**VISUAL:** An architecture diagram that builds left to right:
`ESP32 terminal (RFID + fingerprint)` → `School LAN` → `L-SIAMS server (web app +
MariaDB + realtime server)` → `Admin & Teacher browsers`.
Draw a dashed border labelled "Inside the school" around all of it.

**NARRATION:**
> Here is the whole system. Each classroom has one terminal: an ESP32
> microcontroller with an RFID reader and a fingerprint sensor.
>
> The terminal connects over the school's own network to one server. That
> server runs the web application, the database and the realtime server.
> Administrators and teachers use it from any browser on the same network.
>
> One design choice matters here. **The server decides, not the device.** The
> terminal only reports that a card was presented at a certain time. The server
> decides whether that is a time-in, a time-out or a rejection, based on the
> schedule and the session. A terminal with a wrong clock or modified firmware
> cannot create an attendance status.

---

## Scene 5 — Hardware Close-Up (3:30 – 4:00)

**VISUAL:** Close-up of the assembled terminal. Label each part on screen:
ESP32-WROOM-32, MFRC522 RFID reader, AS608/R307 fingerprint sensor, onboard LED.

**NARRATION:**
> The terminal is built from inexpensive, widely available parts. It needs no
> display. The onboard LED gives feedback by counting blinks: **two blinks means
> accepted** and **five blinks means refused**. The web interface shows the full
> details.

---

## Scene 6 — Finished Output: Live Demo on the Terminal (4:00 – 5:30)

*[Split screen: the terminal on the left, the teacher's Attendance page on the
right, so viewers see the tap and the result together.]*

### 6a. The teacher opens the session

**VISUAL:** The teacher places a finger on the sensor. The LED blinks **twice**.
On screen, the session for that class and subject shows as open.

**NARRATION:**
> It's the start of class. The teacher places their finger on the sensor. The
> server checks that this teacher is scheduled for this room at this time. Two
> blinks: the session is open.

### 6b. Students tap in

**VISUAL:** A student taps their card. Two blinks. A new row appears on the
teacher's screen with the student's name, time-in and status. Tap a second card
and let the row arrive live.

**NARRATION:**
> Students tap their cards as they come in. Each tap appears on the teacher's
> screen within moments. Nobody has to refresh the page. The system records the
> exact time and marks the student on time or late using the schedule.

### 6c. An unknown card is refused

**VISUAL:** Tap the unregistered card. Five blinks. Cut to the admin window,
where a notification about the unknown card appears.

**NARRATION:**
> An unregistered card gets five blinks and is refused. It isn't ignored,
> either: the administrator is notified about the unknown card.

### 6d. Tapping out

**VISUAL:** At the end of class, a student taps again. The row updates with a
time-out and a departure status.

**NARRATION:**
> At the end of class, a second tap records departure. L-SIAMS keeps arrival,
> departure and final status separate. "Arrived late but stayed the whole
> class" and "arrived on time but left early" are recorded as different things.

---

## Scene 7 — Finished Output: The Administrator's View (5:30 – 7:15)

*[Screen recording of the Administrator account. Spend about 10–15 seconds on
each page, moving the cursor slowly.]*

| On screen | Narration |
|---|---|
| **Sign-in page** → sign in | Administrators sign in with a strong password. The session times out after ten minutes idle, and leaving a tab open does not keep it alive. |
| **Dashboard** | The dashboard shows today's attendance, which sessions are open and which terminals are online, all updating live. |
| **Attendance** | Every attendance record can be searched and filtered. When a correction is needed, the system saves the old value, the new value, who changed it and the reason. |
| **Analytics** | Analytics show trends over time: attendance rates, lateness and absence patterns by section. |
| **Students / Teachers** | Students, teachers and user accounts are managed here. |
| **Grade Levels, Sections, Subjects, Classrooms, Schedules** | The academic setup: grade levels, sections, subjects, classrooms and the weekly schedule that decides when each session may open. |
| **RFID Cards** | Each student has one active card. A lost card is deactivated and replaced, and the old card stops working immediately. |
| **Fingerprints** | Teacher fingerprints are enrolled from the web page, directly on a classroom terminal. |
| **Classroom Terminals** | Each terminal's status is visible, with online or offline, last contact and sensor health. |
| **Security Center / Audit Logs** | Every sign-in, failed attempt and administrative action is logged. These logs can only be added to. Nobody can edit or delete them, not even the administrator. |
| **Backup & Restore** | Encrypted backups can be taken and restored with one click. |

---

## Scene 8 — Finished Output: The Teacher's View (7:15 – 8:00)

*[Screen recording of the Teacher account.]*

| On screen | Narration |
|---|---|
| **Teacher Dashboard** | Teachers get their own, simpler view. |
| **My Schedule** | Their weekly schedule. |
| **My Sections** | The students in each section they handle, with RFID status. |
| **Attendance / My Sessions** | Live and past sessions. If the fingerprint sensor can't read their finger, a teacher can open the session with their own password as a backup. |
| **Reports** | Reports for their own classes, ready to download. |

---

## Scene 9 — Finished Output: Reports (8:00 – 8:45)

**VISUAL:** Open **Reports**, choose *Section Daily Attendance Sheet*, set a
date range and generate it. Export to **PDF**, then to **Excel**, and open both
files. Quickly scroll the list of report types.

**NARRATION:**
> Paper records turn into reports in seconds. L-SIAMS produces sixteen report
> types, including daily, weekly and monthly attendance, per student, per
> teacher, per subject, section summaries and comparisons, adviser reports,
> chronic absence, device uptime, and the audit and security logs.
>
> Each report exports as PDF, Excel or CSV. The report generator is built into
> the system and needs no outside service.

---

## Scene 10 — Reliability and Security Highlights (8:45 – 9:20)

**VISUAL:** Three quick cards, one after another.

**NARRATION:**
> Three details make the system reliable.
>
> **Offline tolerance.** If the network drops, the terminal holds up to forty
> taps in memory and sends them with their original times once the connection
> returns. Every tap carries a unique key, so a retry can never be recorded
> twice.
>
> **Integrity.** Rules like "one open session per classroom" and "one active
> card per student" are enforced by the database itself, so two requests at the
> same moment cannot break them.
>
> **History that can't be rewritten.** Each attendance record keeps the section,
> subject and teacher it was taken under. If a student transfers sections later,
> their earlier records stay correct.

---

## Scene 11 — Objectives Met and Closing (9:20 – 10:00)

**VISUAL:** Bring back the Objectives slide from Scene 3. A green check appears
beside each objective as it is read.

**NARRATION:**
> Back to the six objectives.
>
> Attendance is automated with RFID. ✔
> Teachers are verified by fingerprint before any session opens. ✔
> Every layer is secured, from signed device requests to logs that can't be
> changed. ✔
> The whole system runs offline on the school's own network. ✔
> Attendance is monitored in real time. ✔
> Accurate reports are one click away. ✔
>
> L-SIAMS gives the school attendance records that are fast, hard to fake, and
> entirely under its own control.
>
> Thank you for watching.

**VISUAL:** End card with the L-SIAMS logo, the project name, and the team
members' names.

---

## Shot checklist

- [ ] Title card and end card
- [ ] Classroom/terminal B-roll
- [ ] Problem icons animation
- [ ] Objectives slide (Scene 3) and the checked version (Scene 11)
- [ ] Architecture diagram (Scene 4)
- [ ] Hardware close-up with labels (Scene 5)
- [ ] Live terminal demo, split screen: fingerprint open, card tap-in, unknown
      card, tap-out (Scene 6)
- [ ] Admin walkthrough recording (Scene 7)
- [ ] Teacher walkthrough recording (Scene 8)
- [ ] Report generation plus opened PDF and Excel files (Scene 9)
- [ ] Highlight cards (Scene 10)
