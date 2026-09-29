# L-SIAMS — Explanation and Discussion of the Finished Objectives

This document explains and discusses, one by one, the specific objectives of the
study that have already been accomplished. It is based entirely on the manuscript
(*LSIAMS_Manuscript_Revised.docx*) and does not change any of its content.

The general objective of the study is to design, implement, and evaluate L-SIAMS:
A Local IoT-Based Multi-Layer Secured Attendance Monitoring System with RFID and
Biometric Authentication that improves classroom-level attendance monitoring,
reduces processing time, and ensures data security. Out of the five specific
objectives, Objectives 1, 2, 3, and 5 have been completed, while Objective 4, the
evaluation and validation of the system, remains to be carried out.

---

## Objective 1 — Design and implement an IoT-based hardware system

### 1.1 Captures student attendance via RFID tapping at classroom entry points

This objective has been accomplished through the development of a classroom
terminal built on the ESP32 microcontroller together with the MFRC522 RFID reader.
Unlike the existing setup at Top Achievers Private School Inc., where students tap
only at the school gate as a notification for parents, the terminal is positioned
at the classroom entry point so that attendance is taken at the actual point of
instruction. Two terminals were constructed for the prototype, and their wiring
and connectivity are presented in Figure 12. By moving the tapping point to the
classroom, the system addresses the gap between entry monitoring and actual
student presence, and it also spreads out the tapping that previously caused a
queue of 20–30 students at a single gate terminal.

### 1.2 Verifies teacher identity using biometric fingerprint authentication before activating attendance sessions

This objective has been accomplished by integrating the AS608 optical fingerprint
module into the same terminal. Before any student can tap in, the teacher must
first scan a fingerprint to open the attendance session, and the fingerprints of
teaching staff are enrolled in the system beforehand, as shown in Figure 19. This
matters because RFID alone cannot prevent proxy attendance: a card can be tapped
by anyone who holds it. Requiring the teacher's biometric verification ensures
that attendance sessions are opened only by an authorized teacher who is
physically present in the classroom.

### 1.3 Automatically records student ID, date, and time for accurate and real-time attendance monitoring

This objective has been accomplished because every RFID tap is automatically
saved with the student ID, the date, and the exact time, removing the need for the
5–10 minute manual roll call that consumed part of each 40-minute class period.
The system also handles network interruptions: the terminal keeps the taps while
the server is unreachable and synchronizes them automatically once the connection
is restored, with each record keeping the time of the original tap. Each record
also carries an idempotency key, so a retried transmission cannot create a
duplicate attendance entry. In this way, the records remain accurate and complete
even when the network is not stable.

---

## Objective 2 — Develop a locally hosted system

### 2.1 Allows administrators to register users and IoT devices

This objective has been accomplished through the administrator functions of the
web application. Administrators create user accounts, and each account is bound
to a role that determines what it can access, as shown in Figure 14. Classroom
terminals must likewise be registered before they are allowed to send attendance
data. The device registry shows each terminal's identifier, assigned classroom,
and connection state, and the detail view of a terminal is where its credentials
are issued, rotated, and revoked (Figures 15 and 16). Registration therefore
serves both as management and as a security measure, since only known users and
known devices can take part in the system.

### 2.2 Stores attendance records in a centralized database for efficient retrieval

This objective has been accomplished by using a MariaDB relational database on a
locally hosted server with a PHP backend. All attendance records from the
classroom terminals are sent to this single database, where they are processed
and stored. The student register, shown in Figure 22, defines which students are
expected in which room at which time, which allows the system to determine which
taps are valid. Because the server runs inside the school's Local Area Network
and does not depend on the internet, records are retrieved quickly and remain
under the school's control.

### 2.3 Generates attendance reports and dashboards for monitoring and decision-making

This objective has been accomplished through the dashboards of the web
application. The administrator dashboard in Figure 20 presents attendance trends,
live tap activity, terminal connection status, and a security summary in a single
view, giving administrators an immediate picture of attendance across classrooms.
The teacher portal in Figure 21 is restricted by role to the classes the teacher
is actually scheduled to teach, so each teacher sees only the information relevant
to them. Through web-based access to attendance records and reports, the system
supports both day-to-day monitoring and school-level decision-making.

---

## Objective 3 — Implement selected security mechanisms

### 3.1 Securing transmitted data against interception through encrypted communication

This objective has been accomplished by securing the communication between the
ESP32 terminals and the server with HTTPS/TLS. Because the system is designed to
run without internet access, it cannot obtain a certificate from a public
certificate authority, so it operates its own internal certificate authority that
issues the server certificate and keeps its private key encrypted. As a result,
card identifiers, credentials, and attendance data travelling across the LAN
cannot be read by anyone who intercepts the traffic, which was later confirmed
through penetration testing (Objective 5.1).

### 3.2 Preventing unauthorized access through authentication and role-based access control

This objective has been accomplished through a single authenticated sign-in point
(Figure 13) combined with role-based access control (Figure 14). Passwords are
stored only as bcrypt hashes, the password policy requires strong passwords, and
accounts are locked after repeated failed login attempts. Each role is limited to
the pages and operations it is allowed to use, so, for example, a teacher cannot
reach administrative functions. Administrators can also view active sessions and
end them individually or all at once, which gives a direct response if a session
is believed to be compromised.

### 3.3 Restricting unauthorized system interaction through controlled network access

This objective has been accomplished by applying network address restriction to
every request before credentials are even checked. Only authorized address ranges
are allowed, and browser traffic and terminal traffic are governed by separate
permitted ranges, so a compromised staff computer does not gain the network
position of a classroom terminal. Together with firewall configuration and port
restriction, this ensures that only authorized machines inside the LAN can
interact with the system.

### 3.4 Improving accountability and monitoring through audit logging of system activities

This objective has been accomplished through two logs. The audit trail in
Figure 18 records administrative and attendance activity, providing
accountability and non-repudiation. The security event log in Figure 17 records
each security event with its type, severity, source address, and resolution
state. Attendance and audit records are append-only, meaning corrections are
added as new entries instead of overwriting old ones, so the original record is
always preserved. This makes it possible to trace who did what and when.

### 3.5 Applying application-level security measures to protect system operations and user information

This objective has been accomplished through several application-level controls.
Sessions use cryptographically random tokens and protected cookies and expire
after inactivity; state-changing requests require a cross-site request forgery
token; request rates are limited per terminal; security headers and a content
security policy restrict how the interface can be loaded and what scripts may
run; and uploaded files are stored where they cannot be executed. On the database
side, all queries are parameterised and device secrets are stored encrypted.
Backups are also encrypted (Figure 23), which supports recovery of attendance
records after loss or corruption.

### 3.6 Implementing network security controls such as IP filtering, firewall configuration, encrypted communication, and device authentication

This objective has been accomplished by combining the network controls above with
per-device request authentication. Every classroom terminal signs each request it
sends, and the server checks this signature, rejects replayed requests, and makes
sure the device is bound to its assigned classroom. Device keys are managed over
their whole life: they are generated at registration, stored in protected form,
and can be rotated or revoked (Figure 16). Together with IP filtering, firewall
configuration, and encrypted communication, this secures the IoT attendance
traffic within the LAN so that a fake or stolen device cannot submit attendance.

---

## Objective 4 — Evaluate and validate the system (not yet finished)

Objective 4, which covers measuring processing time, queue length, and efficiency
(4.1), comparing system records with manual attendance for accuracy (4.2), and
assessing usability, performance, reliability, and security against ISO/IEC 27001
guided by the CIA Triad (4.3), has not yet been carried out. The manuscript
describes its methods and instruments in Chapter III, but the results are still
to be gathered. The penetration testing results below already provide part of the
security evidence needed for 4.3.

---

## Objective 5 — Conduct penetration testing

All tests were performed from a Kali Linux machine on the same Local Area Network
as the server and were limited strictly to the owned and authorized system, as
presented in Section 4.13.3.

### 5.1 Verifying secure HTTPS/TLS communication using Wireshark

This objective has been accomplished. Figure 24 shows the traffic between an
ESP32 terminal and the server captured in Wireshark. The TLS handshake is visible,
and after it all application data appears only as encrypted ciphertext. No card
identifiers, credentials, or attendance records could be read from the captured
traffic. This confirms that the encrypted communication implemented in
Objective 3.1 protects sensitive data while it travels across the network.

### 5.2 Testing resistance to brute-force login attempts using Hydra

This objective has been accomplished. Figure 25 shows a controlled brute-force
attack on the login using Hydra. After the configured number of failed attempts,
the account lockout and rate limiting blocked all further attempts regardless of
the password used, and no login succeeded. Figure 26 shows the matching lockout
event in the Security Center. This shows that the attack was not only stopped but
also recorded, confirming the authentication controls of Objective 3.2 and the
logging of Objective 3.4.

### 5.3 Performing port scanning using Nmap

This objective has been accomplished. Figure 27 shows the Nmap scan of the server,
where only the ports required for the system were open: the HTTPS port for the web
application and the port for the real-time monitoring channel. The database port
was not reachable from the LAN, and no unnecessary services were exposed. This
confirms that the network controls of Objectives 3.3 and 3.6 reduce the system's
attack surface to only what is needed.

### 5.4 Confirming that forged or unregistered device requests are rejected and recorded

This objective has been accomplished. Figure 28 shows attempts to reach a device
endpoint using an unregistered device identifier and a request without a valid
signature. Each forged request was rejected by the device authentication layer,
and the matching security events appear in the Security Center in Figure 29. This
confirms that a fake terminal cannot submit attendance, and that every such
attempt leaves a record for accountability.

Overall, no vulnerabilities rated High or Critical under the CVSS scale were found
during testing, and every security-related action performed during the tests
produced a matching entry in the audit and security logs.

---

## Conclusion

The completed objectives show that L-SIAMS has been successfully built and
secured. The hardware captures classroom attendance with RFID and teacher
fingerprint verification, the locally hosted system manages users, devices,
records, and dashboards, and the multi-layer security mechanisms have been
implemented and then proven effective through penetration testing. What remains
is Objective 4, the evaluation of the system's performance, accuracy, and quality
with its actual users.
