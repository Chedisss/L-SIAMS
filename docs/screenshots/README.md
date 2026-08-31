# System screenshots

Captured from the running system against seeded demo data — not mockups.
Every image is a real page render, 1440 px wide at 2× pixel density
(2880 px wide PNG), so it stays sharp when placed in a manuscript and
printed.

## How these were produced

| | |
|---|---|
| Database | MariaDB 10.11, schema built by `php bin/console migrate` (24 migrations) |
| Data | `php bin/console seed --demo` — 142 students, 7 teachers, 5 sections, 11 subjects, 8 classrooms, 25 schedules, 5 terminals, 100 closed sessions, 2,896 attendance records across 30 school days |
| Server | PHP 8.4 built-in server via `bin/router.php`, plus `realtime/server.php` for the WebSocket channel |
| Capture | Headless Chromium, viewport resized to full document height so the fixed sidebar renders its whole length |
| Accounts | `demoadmin` (administrator) and `mreyes` (teacher) |

To reproduce: `php bin/console install`, seed with `--demo`, start the
server, and sign in with the demo credentials the seeder prints.

## Administrator

| File | Page |
|---|---|
| `00-login.png` | Sign-in screen |
| `admin-01-dashboard.png` | Dashboard — attendance trend, live taps, terminal status, security summary |
| `admin-02-analytics.png` | Analytics |
| `admin-03-students.png` | Student register |
| `admin-04-student-detail.png` | Student record |
| `admin-05-teachers.png` | Teachers |
| `admin-06-sections.png` | Sections |
| `admin-07-subjects.png` | Subjects |
| `admin-08-classrooms.png` | Classrooms |
| `admin-09-grade-levels.png` | Grade levels |
| `admin-10-schedules.png` | Class schedules |
| `admin-11-attendance.png` | Attendance register |
| `admin-12-sessions.png` | Attendance sessions |
| `admin-13-rfid-cards.png` | RFID cards |
| `admin-14-rfid-unknown.png` | Unknown cards presented |
| `admin-15-fingerprints.png` | Fingerprint enrolment |
| `admin-16-devices.png` | IoT terminals |
| `admin-17-device-detail.png` | Terminal detail |
| `admin-18-reports.png` | Report generator |
| `admin-19-security-center.png` | Security centre |
| `admin-20-security-logs.png` | Security logs |
| `admin-21-security-sessions.png` | Active sessions |
| `admin-22-audit-trail.png` | Audit trail |
| `admin-23-backups.png` | Backup and restore |
| `admin-24-settings.png` | System settings |
| `admin-25-users.png` | User accounts |
| `admin-26-notifications.png` | Notifications |
| `admin-27-profile.png` | Profile |

## Teacher portal

| File | Page |
|---|---|
| `teacher-01-dashboard.png` | Dashboard — today's classes, recent attendance |
| `teacher-02-sessions.png` | My sessions |
| `teacher-03-attendance.png` | Attendance |
| `teacher-04-schedule.png` | My schedule |
| `teacher-05-sections.png` | My sections |
| `teacher-06-reports.png` | Reports |

## Note on the dashboard tiles

The demo seeder builds 30 school days ending the day before the capture, so
the "today" tiles (Present Today, Attendance Rate, Active Sessions) read zero
and the terminals show offline — no physical terminal is connected to this
machine. The historical panels (attendance trend, live attendance list,
recent activity) are fully populated. If a screenshot showing an open session
and live tap-ins is needed, the demo data has to be extended into the current
day first.
