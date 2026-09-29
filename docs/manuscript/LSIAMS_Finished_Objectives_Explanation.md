# L-SIAMS — Simple Explanation of Our Finished Objectives

This is a short, plain-language explanation of the objectives we have already
finished, based on our manuscript (*LSIAMS_Manuscript_Revised.docx*). It does not
change the manuscript; it only explains what the manuscript already says we have
done.

**General objective (from Section 1.2):** design, implement, and evaluate L-SIAMS,
a local IoT-based, multi-layer secured attendance monitoring system with RFID and
biometric authentication.

| Objective | Status |
|---|---|
| 1. IoT-based hardware system | Finished |
| 2. Locally hosted system | Finished |
| 3. Security mechanisms | Finished |
| 4. Evaluation and validation | Not yet finished (planned in Chapter III) |
| 5. Penetration testing | Finished |

---

## Objective 1 — IoT-Based Hardware System (Finished)

**What we set out to do:** build a classroom device that records attendance by
RFID tap, checks the teacher's fingerprint before a session starts, and saves the
student ID, date, and time automatically.

**What we finished:**
- We built a classroom terminal using an **ESP32** microcontroller, an **MFRC522
  RFID reader**, and an **AS608 fingerprint sensor**. Two terminals were built for
  the prototype (Section 4.13.1, Figure 12).
- **1.1 RFID tapping:** students tap their cards at the classroom entry point
  instead of at the school gate.
- **1.2 Teacher fingerprint:** a teacher must scan a fingerprint before an
  attendance session can be opened. Teachers' fingerprints are enrolled in the
  system (Figure 19). This prevents proxy attendance, which RFID alone cannot stop.
- **1.3 Automatic recording:** each tap is saved with the student ID, date, and
  time. If the network goes down, the terminal holds the taps and sends them later
  with their original time, and an idempotency key makes sure a retried tap is not
  recorded twice.

**In simple words:** no more manual roll call — the teacher scans a finger, the
students tap, and the system writes down who came in and when.

---

## Objective 2 — Locally Hosted System (Finished)

**What we set out to do:** make a system that runs inside the school network,
where the admin can register users and devices, all attendance is stored in one
database, and reports and dashboards are produced.

**What we finished:**
- The system runs on a local server in the school LAN, built with **PHP and
  MariaDB**, and does not need the internet.
- **2.1 Registration:** administrators create user accounts with roles
  (Figure 14) and register each classroom terminal before it can send data
  (Figures 15–16).
- **2.2 Central database:** all attendance records are stored in one MariaDB
  database for easy retrieval. The student register decides which students are
  expected in which room at which time (Figure 22).
- **2.3 Reports and dashboards:** the administrator dashboard shows attendance
  trends, live taps, terminal status, and a security summary (Figure 20). The
  teacher portal shows only the teacher's own classes (Figure 21).

**In simple words:** everything is managed in one place, inside the school, and
the admin and teachers can see attendance at a glance.

---

## Objective 3 — Security Mechanisms (Finished)

**What we set out to do:** protect attendance data using several layers of
security.

**What we finished:**
- **3.1 Encrypted communication:** traffic between the terminals and the server
  uses **HTTPS/TLS**. Because the system is offline, it uses its own internal
  certificate authority.
- **3.2 Authentication and role-based access control:** users must log in
  (Figure 13); passwords are stored as bcrypt hashes; accounts lock after repeated
  failed attempts; each role only sees what it is allowed to (Figure 14). Admins can
  see and end active sessions.
- **3.3 Controlled network access:** only allowed network address ranges can reach
  the system, with firewall rules and port restriction.
- **3.4 Audit logging:** important actions are written to an audit trail
  (Figure 18) and a security event log (Figure 17).
- **3.5 Application-level protection:** CSRF tokens, secure session cookies, rate
  limiting, security headers, safe file uploads, parameterised database queries,
  and append-only attendance and audit history.
- **3.6 Network and device security:** every terminal proves who it is on each
  request with a signature. Its keys can be issued, rotated, and revoked
  (Figure 16). Backups are encrypted (Figure 23).

**In simple words:** the data is locked in transit, only the right people and the
right devices can get in, and everything important is recorded.

---

## Objective 4 — Evaluation and Validation (Not Yet Finished)

This objective is **planned but not yet carried out**. The manuscript
(Chapter III) describes it in the future tense: measuring processing time, queue
length, and efficiency; comparing system records with manual attendance for
accuracy; running the five-point Likert questionnaire (success = grand mean of
3.40 or above); and evaluating security against ISO/IEC 27001 guided by the CIA
Triad.

The penetration test results from Objective 5 already give part of the security
evidence for this objective.

---

## Objective 5 — Penetration Testing (Finished)

**What we set out to do:** attack our own system, safely and only on our own LAN,
to prove the security controls actually work.

**What we finished (Section 4.13.3):** all tests were run from a Kali Linux
machine on the same LAN, against our own system only.

| Test | Tool | Result |
|---|---|---|
| 5.1 Encrypted communication | Wireshark | TLS handshake seen; all data was encrypted — no card IDs, passwords, or attendance data could be read (Figure 24). |
| 5.2 Brute-force login | Hydra | After the failed-attempt limit, lockout and rate limiting blocked all further tries; no login succeeded, and the lockout was logged (Figures 25–26). |
| 5.3 Port scanning | Nmap | Only the needed ports were open (the HTTPS web port and the real-time monitoring port); the database port was not reachable (Figure 27). |
| 5.4 Forged / unregistered devices | Kali (crafted requests) | Requests with an unknown device or no valid signature were rejected and recorded as security events (Figures 28–29). |

**Summary:** no High or Critical vulnerabilities (CVSS) were found, and every
security-related action during testing appeared in the audit and security logs.

**In simple words:** we tried to break in, and the system blocked every attempt
and wrote it down.

---

## Overall

We have finished **four of the five objectives** (1, 2, 3, and 5): the hardware,
the local system, the security layers, and the penetration testing. What remains
is **Objective 4**, the evaluation with real users and measurements.
