# L-SIAMS — Explanation of the Finished Objectives

This explains, one by one, the objectives we have already finished, based on the
manuscript. Objectives 1, 2, 3, and 5 are done; Objective 4 (evaluation) is not
yet carried out.

## Objective 1 — IoT-Based Hardware System

**1.1 RFID tapping at classroom entry points.** We built a classroom terminal
using an ESP32 and an MFRC522 RFID reader, placed at the classroom door instead of
the school gate (Figure 12). Two terminals were made for the prototype.

**1.2 Teacher fingerprint verification.** An AS608 fingerprint sensor is part of
the terminal. The teacher must scan a fingerprint before students can tap in
(Figure 19), which prevents proxy attendance.

**1.3 Automatic recording of student ID, date, and time.** Every tap is saved
automatically with the student ID, date, and time, replacing the manual roll call.
If the network goes down, the terminal keeps the taps and sends them later without
creating duplicates.

## Objective 2 — Locally Hosted System

**2.1 Register users and IoT devices.** Administrators create user accounts with
roles (Figure 14) and register each terminal before it can send data
(Figures 15–16).

**2.2 Centralized database.** All attendance is stored in one MariaDB database on
a local server inside the school network, with no internet needed.

**2.3 Reports and dashboards.** The admin dashboard shows attendance trends, live
taps, terminal status, and a security summary (Figure 20). The teacher portal
shows only the teacher's own classes (Figure 21).

## Objective 3 — Security Mechanisms

**3.1 Encrypted communication.** Data between the terminals and the server is
protected with HTTPS/TLS using our own internal certificate authority.

**3.2 Authentication and role-based access control.** Users must log in (Figure
13), passwords are stored as bcrypt hashes, accounts lock after repeated failed
attempts, and each role only sees what it is allowed to.

**3.3 Controlled network access.** Only authorized IP address ranges can reach
the system, and only the needed ports are open.

**3.4 Audit logging.** Important actions are recorded in the audit trail (Figure
18) and the security event log (Figure 17).

**3.5 Application-level security.** The system uses secure sessions, CSRF
protection, rate limiting, safe database queries, and encrypted backups
(Figure 23).

**3.6 Network and device security.** Along with IP filtering, firewall, and
encryption, every terminal must sign each request, so fake devices are rejected.
Device keys can be rotated or revoked (Figure 16).

## Objective 5 — Penetration Testing

All tests were done from Kali Linux on our own LAN only.

**5.1 Wireshark.** The captured traffic was fully encrypted; no card IDs,
passwords, or attendance data could be read (Figure 24).

**5.2 Hydra brute-force.** Login attempts were blocked after the lockout limit, no
login succeeded, and the lockout was logged (Figures 25–26).

**5.3 Nmap port scan.** Only the HTTPS port and the real-time monitoring port were
open; the database port was not reachable (Figure 27).

**5.4 Forged device requests.** Requests from unregistered devices or without a
valid signature were rejected and recorded as security events (Figures 28–29).

No High or Critical vulnerabilities were found.

## Summary

The hardware, the local system, and the security layers are complete, and the
penetration tests prove the security works. Only Objective 4, the evaluation with
actual users, remains.
