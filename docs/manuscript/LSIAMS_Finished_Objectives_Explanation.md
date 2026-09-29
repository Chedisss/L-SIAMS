# L-SIAMS — Explanation of the Finished Objectives

This explains, one by one, the objectives we have already finished, based on the
manuscript. Each objective is copied exactly as written in the manuscript,
followed by its explanation. Objectives 1, 2, 3, and 5 are done; Objective 4
(evaluation) is not yet carried out.

## Objective 1

> **1. Design and implement an IoT-based hardware system that:**

> **1.1 Captures student attendance via RFID tapping at classroom entry points;**

We built a classroom terminal using an ESP32 and an MFRC522 RFID reader, placed at
the classroom door instead of the school gate (Figure 12). Two terminals were made
for the prototype.

> **1.2 Verifies teacher identity using biometric fingerprint authentication before activating attendance sessions;**

An AS608 fingerprint sensor is part of the terminal. The teacher must scan a
fingerprint before students can tap in (Figure 19), which prevents proxy
attendance.

> **1.3 Automatically records student ID, date, and time for accurate and real-time attendance monitoring.**

Every tap is saved automatically with the student ID, date, and time, replacing
the manual roll call. If the network goes down, the terminal keeps the taps and
sends them later without creating duplicates.

## Objective 2

> **2. Develop a locally hosted system that:**

> **2.1 Allows administrators to register users and IoT devices;**

Administrators create user accounts with roles (Figure 14) and register each
terminal before it can send data (Figures 15–16).

> **2.2 Stores attendance records in a centralized database for efficient retrieval;**

All attendance is stored in one MariaDB database on a local server inside the
school network, with no internet needed.

> **2.3 Generates attendance reports and dashboards for monitoring and decision-making.**

The admin dashboard shows attendance trends, live taps, terminal status, and a
security summary (Figure 20). The teacher portal shows only the teacher's own
classes (Figure 21).

## Objective 3

> **3. Implement selected security mechanisms to protect attendance data and system operations by:**

> **3.1 Securing transmitted data against interception through encrypted communication;**

Data between the terminals and the server is protected with HTTPS/TLS using our
own internal certificate authority.

> **3.2 Preventing unauthorized access through authentication and role-based access control;**

Users must log in (Figure 13), passwords are stored as bcrypt hashes, accounts
lock after repeated failed attempts, and each role only sees what it is allowed
to.

> **3.3 Restricting unauthorized system interaction through controlled network access;**

Only authorized IP address ranges can reach the system, and only the needed ports
are open.

> **3.4 Improving accountability and monitoring through audit logging of system activities;**

Important actions are recorded in the audit trail (Figure 18) and the security
event log (Figure 17).

> **3.5 Applying application-level security measures to protect system operations and user information;**

The system uses secure sessions, CSRF protection, rate limiting, safe database
queries, and encrypted backups (Figure 23).

> **3.6 Implementing network security controls such as IP filtering, firewall configuration, encrypted communication, and device authentication to secure IoT-based attendance communication within the Local Area Network (LAN).**

Along with IP filtering, firewall, and encryption, every terminal must sign each
request, so fake devices are rejected. Device keys can be rotated or revoked
(Figure 16).

## Objective 4 (not yet finished)

> **4. Evaluate and validate the system by:**
>
> **4.1 Measuring processing time, queue length, and system efficiency during attendance recording;**
>
> **4.2 Comparing system-recorded attendance with manual classroom attendance to assess accuracy;**
>
> **4.3 Assessing system usability, performance, and reliability using measurable indicators, and evaluating system security against ISO/IEC 27001 guided by the CIA Triad.**

This objective is planned in Chapter III but has not yet been carried out.

## Objective 5

> **5. Conduct penetration testing to validate the effectiveness of the implemented security controls by:**

All tests were done from Kali Linux on our own LAN only.

> **5.1 Verifying secure HTTPS/TLS communication between the IoT terminals and the server using Wireshark, confirming that transmitted data is encrypted;**

The captured traffic was fully encrypted; no card IDs, passwords, or attendance
data could be read (Figure 24).

> **5.2 Testing resistance to brute-force login attempts using Hydra on Kali Linux to confirm that repeated unauthorized login attempts are blocked;**

Login attempts were blocked after the lockout limit, no login succeeded, and the
lockout was logged (Figures 25–26).

> **5.3 Performing port scanning using Nmap on Kali Linux to confirm that only authorized ports are exposed on the server;**

Only the HTTPS port and the real-time monitoring port were open; the database port
was not reachable (Figure 27).

> **5.4 Confirming that forged or unregistered device requests are rejected and recorded in the system’s audit and security logs.**

Requests from unregistered devices or without a valid signature were rejected and
recorded as security events (Figures 28–29).

No High or Critical vulnerabilities were found.

## Summary

The hardware, the local system, and the security layers are complete, and the
penetration tests prove the security works. Only Objective 4, the evaluation with
actual users, remains.
