# L-SIAMS — Video Presentation Script

**Title:** L-SIAMS: A Local IoT-Based Multi-Layer Secured Attendance Monitoring
System with RFID and Biometric Authentication
**Presented by:** Ched Nikko P. Pangilinan · Joshkirby Tyler M. Bote · Naph Yvan P. Villamar
BS Information Technology · Isabela State University – Echague Campus
**Target length:** about 11–12 minutes
**Purpose:** show the finished work, objective by objective

> **How to read this script.** Each scene lists what goes on screen (**VISUAL**)
> and what the narrator says (**NARRATION**). Times are approximate. Lines in
> *[brackets]* are directions for the people recording, not words to speak.
> Scenes are numbered to match the Specific Objectives, so every part of the
> video points back to the objective it proves.

---

## Before recording

- **Run the system over HTTPS.** Objective 3.1 is about encrypted
  communication, so record on the Apache + TLS setup from
  [`docs/HTTPS.md`](HTTPS.md) (`console tls:generate`, then `console tls:apache`).
  Don't record it on the plain `start.bat` server. The browser address bar
  should show `https://` and a padlock.
- Flash the terminal with `L_SIAMS_Bench` and include the root certificate
  (`ls_root_ca.h`), so the terminal connects over HTTPS too. It must show
  **online** on the **Classroom Terminals** page.
- Load sample data: `console.bat seed --demo`.
- Check that a teacher is scheduled in the terminal's classroom **during the
  recording time**. A session only opens inside the scheduled window.
- Open two browser windows: one signed in as **Administrator**, one as
  **Teacher**.
- Have one registered student card and one **unregistered** card on hand.
- For Scene 3.5, keep a spare test account you can lock on purpose. Five wrong
  passwords lock it for 15 minutes.

---

## Scene 0 — Opening (0:00 – 0:45)

**VISUAL:** Title card with the logo, full title, names, course and campus.
Then B-roll: a classroom door with the terminal mounted beside it.

**NARRATION:**
> Good day. We are presenting L-SIAMS, a Local IoT-Based Multi-Layer Secured
> Attendance Monitoring System with RFID and Biometric Authentication.
>
> Today, one RFID terminal at the school gate tells parents that a child
> entered the campus. It can't tell whether that child was in the classroom
> when the lesson started. So teachers still call the roll by hand, which costs
> minutes from every class. And the attendance data crosses the network with
> no encryption, no device authentication, no access control and no audit
> trail.
>
> L-SIAMS moves attendance into the classroom and secures it at every layer.
> This video walks through our specific objectives and shows what we have
> already finished.

---

## Scene 1 — Objectives at a Glance (0:45 – 1:15)

**VISUAL:** A slide listing the five objectives, each with a status tag:

| Objective | Status |
|---|---|
| 1. IoT-based hardware system | **FINISHED** |
| 2. Locally hosted system | **FINISHED** |
| 3. Security mechanisms | **FINISHED** |
| 4. Evaluation and validation | NEXT PHASE |
| 5. Penetration testing | NEXT PHASE (system prepared) |

**NARRATION:**
> Our study has five specific objectives. The first three are built: the IoT
> hardware, the locally hosted system and the security mechanisms. The last
> two, evaluation and penetration testing, are the next phase. The system is
> already prepared for them, and we'll show how.

---

## OBJECTIVE 1 — The IoT-Based Hardware System

### Scene 1-A — The Terminal (1:15 – 1:45)

**VISUAL:** Close-up of the assembled terminal with on-screen labels:
**ESP32-WROOM-32**, **MFRC522 RFID reader (13.56 MHz)**, **AS608 / R307
fingerprint sensor**, **onboard status LED**.

**NARRATION:**
> This is the classroom terminal. An ESP32 microcontroller connects over the
> school's Wi-Fi, with an RFID reader for students and a fingerprint sensor for
> teachers. It has no screen. The onboard LED blinks twice for accepted and
> five times for refused, and the full details appear in the web system.

### Scene 1-B — Objective 1.2: Teacher Verification Opens the Session (1:45 – 2:30)

*[Split screen: terminal on the left, the teacher's **Attendance** page on the
right.]*

**VISUAL:** Before the fingerprint, tap a student card. It is refused (five
blinks), because no session is open. Then the teacher places a finger on the
sensor. Two blinks. The session opens on screen with the teacher, subject,
section and room.

**NARRATION:**
> **Objective 1.2: verify the teacher's identity with a fingerprint before
> attendance is activated.**
>
> Watch what happens first. A student taps, and nothing is recorded, because
> no session is open yet. Only the teacher can open one. The teacher places
> their finger on the sensor, and the server checks two things: that the
> fingerprint belongs to this teacher, and that this teacher is scheduled to
> teach in this room right now. Two blinks, and the session is open.
>
> If the sensor can't read a legitimate teacher's finger, they can use their
> own password instead. That override is recorded as a security event, never
> silently.

### Scene 1-C — Objective 1.1: Students Tap at the Classroom Entry (2:30 – 3:10)

**VISUAL:** Several students tap their cards one after another. Two blinks
each. A new row appears on the teacher's screen after every tap, without a
page refresh.

**NARRATION:**
> **Objective 1.1: capture student attendance through RFID tapping at the
> classroom entry.**
>
> Students tap their cards as they enter. There is no roll call and no paper.
> Each tap appears on the teacher's screen the moment it happens.

### Scene 1-D — Objective 1.3: ID, Date and Time Recorded Automatically (3:10 – 3:50)

**VISUAL:** Zoom in on one attendance row: student ID and name, date,
**time-in**, **status (On time / Late)**. Later, the same student taps out and
the row gains a **time-out**. Cut briefly to the admin **Dashboard**, where the
counts go up live.

**NARRATION:**
> **Objective 1.3: automatically record the student ID, date and time for
> accurate, real-time monitoring.**
>
> Each tap records the student, the date and the exact time. The server
> compares that time with the schedule and marks the student on time or late.
> The terminal never decides this, so a wrong clock or tampered device can't
> fake a status. A second tap records departure. Arrival and departure are
> stored separately, so "arrived late" and "left early" stay two different
> facts.
>
> This is also real time on the administrator's dashboard. And if the network
> drops, the terminal holds up to forty taps with their original times and
> sends them when the connection comes back, without creating duplicates.

---

## OBJECTIVE 2 — The Locally Hosted System

*[Screen recording, Administrator account. Keep the address bar visible so the
local `https://` address shows.]*

### Scene 2-A — Objective 2.1: Registering Users and IoT Devices (3:50 – 4:50)

| On screen | Narration |
|---|---|
| Title card: **Objective 2.1** | **Objective 2.1: let administrators register users and IoT devices.** |
| **Students** → add a student | Administrators register students... |
| **RFID Cards** → assign a card | ...and give each one an RFID card. Each student has only one active card. A lost card is deactivated, and it stops working immediately. |
| **Teachers** → **Fingerprints** → enroll | Teachers are registered, and their fingerprints are enrolled from this page, directly on a classroom terminal. |
| **User Accounts** | Login accounts are created here, each tied to a role: Administrator or Teacher. |
| **Classroom Terminals** → register a terminal with its MAC address | IoT devices are registered here too. Each terminal is identified by its hardware MAC address and bound to one classroom, and it gets its own credentials. The list shows which terminals are online, when each last reported, and whether its sensors are healthy. |

### Scene 2-B — Objective 2.2: One Centralized Database (4:50 – 5:20)

**VISUAL:** The **Attendance** page: search and filter by date, section and
student. Open one record's details. Then a simple diagram: every classroom
terminal → one server → one database, with the label "inside the school."

**NARRATION:**
> **Objective 2.2: store attendance records in a centralized database for
> efficient retrieval.**
>
> Every terminal in every classroom writes to one central database on the
> school's own server. There is no cloud and no internet dependency. Records
> can be searched by date, section, subject or student in seconds.
>
> The database itself protects the records. There is one record per student per
> session, so a retry can't double-count. And a record can never be deleted or
> reassigned to another student. Corrections are allowed, but each one stores
> the old value, the new value, who made the change and why.

### Scene 2-C — Objective 2.3: Reports and Dashboards (5:20 – 6:20)

**VISUAL:** **Dashboard** (live counts, open sessions, terminal status) →
**Analytics** (trends by section) → **Reports**: choose *Section Daily
Attendance Sheet*, generate it, export to **PDF** and **Excel**, and open both
files. Scroll the list of report types. End on the **Teacher dashboard** and
**My Sections**.

**NARRATION:**
> **Objective 2.3: generate attendance reports and dashboards for monitoring
> and decision-making.**
>
> The dashboard shows today at a glance: who is present, which sessions are
> open and which terminals are online, all updating live. Analytics show
> patterns over time, like lateness and chronic absence by section.
>
> For formal records, the system produces sixteen report types, including:
> - daily, weekly and monthly attendance
> - reports per student, teacher and subject
> - section sheets, summaries and comparisons
> - adviser reports and chronic absence
> - device uptime
> - the audit and security logs
>
> Each one exports as PDF, Excel or CSV, and the report generator is built into
> the system.
>
> Teachers get their own dashboard, limited to the classes they actually teach.

---

## OBJECTIVE 3 — Security Mechanisms

**VISUAL (intro, 6:20 – 6:35):** A six-layer diagram, stacked:
**Device → Network → Transport → Application → Database → Monitoring**.

**NARRATION:**
> Our major is network security, so this is the core of our work. Each layer
> has its own control, so no single failure exposes the attendance data.

### Scene 3.1 — Encrypted Communication (6:35 – 7:05)

**VISUAL:** The browser padlock on `https://`. Click it to show the certificate
issued by the **L-SIAMS internal certificate authority**. Then show the Serial
Monitor of the terminal connecting over HTTPS.

**NARRATION:**
> **Objective 3.1: secure transmitted data against interception with encrypted
> communication.**
>
> All traffic runs over HTTPS/TLS: from browsers to the server, and from every
> terminal to the server. A school network with no internet can't get a
> certificate from a public authority, so L-SIAMS runs its own certificate
> authority. The root certificate is built into the terminal firmware, so each
> terminal checks that it's talking to the real server. Our automated TLS test
> suite passes all 40 of its checks.

### Scene 3.2 — Authentication and Role-Based Access Control (7:05 – 7:35)

**VISUAL:** Sign in as the Teacher. Paste an admin URL, such as
`/admin/users`, into the address bar. Access is refused. Cut to the admin
**Security Center → Active Sessions** and end one session with a click.

**NARRATION:**
> **Objective 3.2: prevent unauthorized access through authentication and
> role-based access control.**
>
> Every user signs in. Passwords are stored as bcrypt hashes and must be at
> least twelve characters. Each role reaches only its own pages. A teacher who
> types an administrator's address is refused, and the attempt is logged.
> Administrators can see every active session and end any of them immediately.

### Scene 3.3 — Controlled Network Access (7:35 – 8:00)

**VISUAL:** Show the `TRUSTED_WEB_CIDRS` and `TRUSTED_DEVICE_CIDRS` lines in
the server's `.env` file, which are the allowed address ranges for staff
browsers and for terminals. Then open a request from an address outside the
range. It is refused, and the refusal shows in the security log.
*[For the refused request, temporarily narrow the range, or use a laptop on
another subnet. Put the setting back afterwards.]*

**NARRATION:**
> **Objective 3.3: restrict unauthorized interaction through controlled network
> access.**
>
> The system only answers the addresses it trusts. Staff browsers and
> classroom terminals each have their own allowed range. A request from
> anywhere else is refused before login is even checked, and the refusal is
> logged even when the credentials are valid.

### Scene 3.4 — Audit Logging (8:00 – 8:30)

**VISUAL:** **Audit Logs**: recent actions with who, what and when. Then
**Security Center**: events with severity and resolution status.

**NARRATION:**
> **Objective 3.4: improve accountability and monitoring through audit
> logging.**
>
> There are two separate records. The audit log keeps every administrative
> action: who did it, what changed and when. The security log tracks
> thirty-three kinds of security event, each with a severity, the source
> address and its resolution status.
>
> Neither log can be edited or deleted, not even by an administrator. That is
> enforced in three places: the application, database triggers, and database
> permissions.

### Scene 3.5 — Application-Level Security (8:30 – 9:00)

**VISUAL:** On the test account, enter a wrong password five times. The
account locks for 15 minutes. Then show the **ACCOUNT_LOCKED** event in the
Security Center.

**NARRATION:**
> **Objective 3.5: apply application-level security to protect system
> operations and user information.**
>
> Five wrong passwords lock the account for fifteen minutes, and every attempt
> is logged. Behind the screens:
> - Every form is protected against cross-site request forgery.
> - Every database query is parameterized against SQL injection.
> - Sessions are tied to the browser that created them, and they expire after
>   ten minutes of inactivity. A tab left open doesn't keep a session alive.
> - Sensitive secrets are encrypted in the database.

### Scene 3.6 — Securing IoT Communication on the LAN (9:00 – 9:50)

**VISUAL:** A diagram of the **six checks** every terminal request must pass,
each lighting up in turn:
1. Registered device?
2. Valid API key?
3. Valid signature?
4. Timestamp within ±30 seconds?
5. Nonce never used before?
6. Bound to this classroom?

Then briefly show the Windows Firewall rules that open only the system's ports.

**NARRATION:**
> **Objective 3.6: network security controls (IP filtering, firewall
> configuration, encrypted communication and device authentication) for the
> IoT traffic on the LAN.**
>
> Terminals don't just send a password. Each request is signed with a secret
> that never travels over the network. The server checks six things: that the
> device is registered, the key is valid, the signature matches, the request is
> less than thirty seconds old, it has never been used before, and it comes from
> the right classroom. Fail any one and the request is refused and logged.
>
> A captured request can't be replayed, and a modified one fails the signature.
> A terminal whose key starts failing repeatedly, or shows up from several
> addresses, has its credential revoked automatically.
>
> Around all of this, IP filtering limits terminals to their own address range,
> the firewall opens only the ports the system needs, and everything travels
> over TLS.

---

## OBJECTIVES 4 and 5 — What Comes Next (9:50 – 11:00)

> *[If any of these tests have been completed by the time you record, move them
> to a "FINISHED" scene and show the real results instead.]*

**VISUAL:** A two-column slide: **Objective 4: Evaluation** and **Objective
5: Penetration Testing**. Beside each item, a small tag names the part of the
system already prepared for it.

| Next-phase activity | Already in place in the system |
|---|---|
| **4.1** Processing time, queue length and efficiency | Every tap is timestamped at the terminal and at the server. |
| **4.2** Accuracy against manual roll call | Attendance reports ready for side-by-side comparison. |
| **4.3** Usability, performance, reliability; ISO/IEC 27001 and the CIA Triad | Working system ready for users and survey participants. |
| **5.1** Wireshark: is the traffic encrypted? | HTTPS/TLS between terminals and server (Scene 3.1). |
| **5.2** Hydra: is brute force blocked? | Lockout after 5 failed attempts, plus rate limiting (Scene 3.5). |
| **5.3** Nmap: are only authorized ports open? | Firewall rules for the system's ports only (Scene 3.6). |
| **5.4** Forged or unregistered device requests rejected and logged | Six-check device authentication and security log (Scene 3.6). |

**NARRATION:**
> Objectives four and five are the next phase: deployment in selected
> classrooms, evaluation, and penetration testing.
>
> For evaluation, we will measure processing time, queue length and efficiency
> during attendance recording. We will compare the system's records with manual
> roll call to measure accuracy. And we will assess usability, performance and
> reliability, with security evaluated against ISO/IEC 27001 and the CIA Triad.
>
> For penetration testing, we will capture traffic with Wireshark to confirm it
> is encrypted, run brute-force attempts with Hydra, scan the server with Nmap,
> and send forged and unregistered device requests. Each test targets a control
> we have already built and shown in this video. The system is ready to be
> tested.

---

## Scene 12 — Closing (11:00 – 11:40)

**VISUAL:** The objectives slide from Scene 1 again. Checkmarks appear beside
Objectives 1–3 as each is read. Objectives 4–5 show "Next".

**NARRATION:**
> To summarize:
>
> The IoT hardware is finished. Students tap in with RFID, teachers open
> sessions with their fingerprints, and every tap is recorded with the student,
> the date and the time, in real time. ✔
>
> The locally hosted system is finished. Users and devices are registered, all
> records sit in one central database, and dashboards and reports are ready. ✔
>
> The security mechanisms are finished: encrypted communication, role-based
> access, controlled network access, audit logging, application-level
> protection and authenticated IoT communication. ✔
>
> Next, we evaluate and test it, and we will let the results speak.
>
> Thank you for watching.

**VISUAL:** End card with the logo, the three names, BS Information
Technology, Isabela State University – Echague Campus.

---

## Shot checklist

- [ ] Title card, objectives slide (plain and with checkmarks), end card
- [ ] Terminal close-up with labels (1-A)
- [ ] Split screen: refused tap with no session → fingerprint opens the session
      (1-B)
- [ ] Students tapping in, rows appearing live (1-C)
- [ ] Close-up of one record with ID, date, time-in, status and time-out; live
      dashboard (1-D)
- [ ] Registering a student, card, teacher, fingerprint, user account and
      terminal (2-A)
- [ ] Attendance search plus the centralized-database diagram (2-B)
- [ ] Dashboard, Analytics, a report generated and opened as PDF and Excel;
      teacher dashboard (2-C)
- [ ] Six-layer diagram (3 intro)
- [ ] HTTPS padlock and internal CA certificate; terminal connecting over HTTPS
      (3.1)
- [ ] Teacher refused from an admin page; a session ended by the admin (3.2)
- [ ] Allowed address ranges; refused out-of-range request in the log (3.3)
- [ ] Audit log and security log (3.4)
- [ ] Account lockout after 5 attempts and its log entry (3.5)
- [ ] Six-check diagram and firewall rules (3.6)
- [ ] Next-phase slide (Objectives 4–5)
