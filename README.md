# L-SIAMS

**Local IoT-Based Multi-Layer Secured Attendance Monitoring System with RFID and
Biometric Authentication**

A school attendance system that runs entirely on the school's own LAN. Students
tap an RFID card on a classroom terminal to record arrival and departure; a
teacher opens the attendance session for their class by verifying their
fingerprint on that same terminal. Everything else — the web application, the
database, the realtime server — runs on one machine inside the school. There is
no cloud service, no external API, and no internet dependency at runtime.

---

## What it does

| | |
|---|---|
| **Attendance capture** | RFID tap-in and tap-out on ESP32 terminals, with the server deciding intent, not the device |
| **Session control** | A session opens only when a teacher's fingerprint verifies on the terminal for a class they are actually scheduled to teach |
| **Offline tolerance** | Terminals queue taps in flash when the network drops and replay them with their original timestamps and idempotency keys |
| **Status model** | Separate arrival, departure and final statuses, so "arrived late and left early" is not flattened into one ambiguous word |
| **Reporting** | 15 report types, exported as PDF, Excel or CSV, all generated without any third-party library |
| **Realtime** | WebSocket with SSE and long-polling fallbacks, with sequence-based replay so no event is lost across a reconnect |
| **Administration** | Students, teachers, sections, subjects, schedules, classrooms, RFID cards, fingerprints, devices, users, settings and backups |
| **Security** | Per-request HMAC signing for devices, bcrypt for humans, immutable audit and attendance history, full security logging |

---

## Requirements

- **PHP 8.4** with `pdo_mysql`, `openssl`, `mbstring`, `zip`, `gd`, `json`,
  `zlib`, and `sockets` (the last one only for the realtime server)
- **MySQL 8.0** or MariaDB 10.6+
- **Apache 2.4** with `mod_rewrite`, or **nginx** with PHP-FPM
- ESP32 terminals with an MFRC522 RFID reader, an R307 fingerprint sensor and an
  I²C display — see [`docs/FIRMWARE.md`](docs/FIRMWARE.md)

There are no Composer dependencies. `composer.json` exists to declare the PHP
version and the extension requirements; there is no `vendor/` directory and
nothing to install. Every part of the system that would normally be a package —
the autoloader, the XLSX and PDF writers, the WebSocket server, the chart
renderer, the icon set — is implemented in this repository, because the target
network may have no route to the internet at all.

---

## Installation

```bash
git clone <repository-url> /var/www/lsiams
cd /var/www/lsiams

cp .env.example .env
php bin/console key:generate      # writes APP_KEY, API_KEY_PEPPER, REALTIME_TICKET_SECRET
# edit .env: database credentials, APP_URL, TRUSTED_*_CIDRS

php bin/console install           # migrate + seed + create the first administrator
```

`install` runs the migrations, seeds the reference data (roles, permissions,
grade levels, departments, the attendance-status vocabulary and the default
settings) and prompts for the first administrator account. That password is
shown once and never stored in plaintext.

For a system to explore rather than deploy:

```bash
php bin/console seed --demo
```

This builds a small complete school — teachers with fingerprints enrolled,
sections with students and RFID cards, schedules, and 30 school days of closed
attendance sessions — so every screen has real data behind it. It refuses to run
against a database that already holds real students or attendance.

Full deployment instructions, including web-server configuration, TLS, the
systemd units and the database privilege model, are in
[`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

---

## Console commands

```
php bin/console install                 Full first-time setup
php bin/console key:generate            Generate the cryptographic keys
php bin/console migrate                 Apply pending migrations
php bin/console migrate:status          Show which migrations have run
php bin/console migrate:fresh --force   Drop everything and rebuild (destructive)
php bin/console seed [--demo]           Seed reference data, optionally demo data
php bin/console worker [--once]         Background worker: auto-close, retention, alerts
php bin/console backup                  Take an encrypted backup now
php bin/console security:audit-keys     Audit the API-key path for weak randomness
php bin/console user:create-admin       Create an additional administrator
```

The **worker** is not optional. It auto-closes sessions whose window has passed,
marks stale devices offline, runs the retention sweeps and raises notifications.
Without it, a session left open by a terminal that lost power would stay open
indefinitely. Run it under systemd — see the deployment guide.

---

## Layout

```
app/
  Controllers/     Web (17) and Api (5) controllers, thin — they validate and delegate
  Core/            Autoloader, router, request/response, database, crypto, view engine
  Middleware/      The security chain: headers, allowlist, rate limit, auth, CSRF, …
  Models/          Thin data mappers
  Services/        The domain layer — this is where the system actually lives
  Validators/      Teacher assignment constraint validators
  Views/           Templates: admin/, teacher/, shared/, partials/, layouts/
bin/console        CLI entry point
config/            app, database, security, attendance, realtime
database/
  migrations/      Seven ordered SQL files; the schema is the specification
  seeders/         Reference and demo data
firmware/          ESP32 sketch and configuration header
public/            Web root — index.php, assets, uploads
realtime/          The WebSocket server
routes/            web.php and api.php; each documents its middleware chain
storage/           Logs, cache, generated reports, temp (writable, not in the web root)
tests/concurrency/ The race-condition suite
```

---

## Design decisions worth knowing

**Invariants live in the schema, not in application checks.** "One open session
per classroom", "one active card per student", "one device per classroom role"
and "one attendance record per student per session" are all enforced by UNIQUE
keys over generated columns. A check-then-insert in PHP would lose the race under
load; a UNIQUE key cannot. The application's job is to translate error 1062 into
a sensible business response, which it does.

**Audit and attendance history is immutable, three times over.** The audit log,
security log and login history are strictly append-only: no update or delete
method exists on those services, `BEFORE UPDATE` and `BEFORE DELETE` triggers
raise `SQLSTATE 45000`, and the application's database user holds only `INSERT`
and `SELECT` on them.

Attendance rows are narrower than that, because they are legitimately written
twice — the tap-in creates the row and the tap-out completes it. So deletion is
blocked outright, and a `BEFORE UPDATE` trigger rejects any change to the
student, session, section, grade level, subject, teacher, classroom, card UID,
request id or creation time. Times and statuses can be corrected; a record can
never be re-attributed to somebody else. Every correction is recorded separately
with the administrator, the old and new values, and a reason the database
insists on.

**The server decides tap intent, not the device.** A terminal reports "this card
was presented at this time" and renders whatever the server tells it to display.
Whether that is a time-in, a time-out, or a rejection is decided server-side from
the session state, the schedule windows and the device's configured role. A
terminal with a wrong clock or tampered firmware cannot manufacture a status.

**API keys use SHA-256, deliberately, while passwords use bcrypt.** Device keys
are 256-bit CSPRNG values, so a slow hash buys nothing against brute force, and
bcrypt at cost 12 would add roughly 250 ms to every signed request — untenable at
classroom tap rates. Human passwords, which are low-entropy by nature, stay on
bcrypt with a 12-character minimum. The reasoning is documented at the call site
in `app/Core/Crypto.php`.

**The idle timeout cannot be defeated by leaving a tab open.** The 10-minute
timeout is enforced server-side, and every background request — polling, SSE,
WebSocket traffic, chart refreshes, the notification badge — is marked
`X-Passive-Request: true` and does not extend it. If passive traffic reset the
timer, the control would not exist.

**Attendance records carry a snapshot of their context.** Section, grade level,
subject, teacher and classroom are copied onto each record at write time. A
student who transfers section in January must not have their October attendance
retroactively reattributed — reports have to show where the student actually sat.

---

## Testing

```bash
php tests/concurrency/run.php              # all groups
php tests/concurrency/run.php --group=race # one group
php tests/concurrency/run.php --load       # opt in to the 30-minute soak
```

Twelve groups covering session opening, the concurrent-tap race, cross-device
taps, section mismatch, time-in/time-out sequencing, status resolution, session
close, idempotency, offline replay, throughput and the realtime transport.

The central case fires 50 simultaneous taps of the same card at the same session
and asserts that exactly one attendance row exists afterwards. It runs against a
real MySQL server, not a mock — the guarantee being tested is the database's.

The suite freezes the clock at 10:00 for the duration of a run. A schedule is a
time of day, and the fixture has to place a window that contains "now" while
satisfying the schema's CHECK constraints; against the wall clock that is
satisfiable in the morning and impossible late at night, so an unfrozen suite
would pass all day and fail in the evening for reasons unrelated to the code.
Nothing under test needs real elapsed time — the dwell cases backdate `time_in`
in SQL rather than waiting.

Last verified run: 44 passed, 0 failed, tap p95 6.7 ms, 152–181 taps/second
sustained, against MariaDB 10.11.

Fixtures are prefixed `TEST-CONC-` and torn down afterwards. **Point the suite at
a test database**, never at a school's live data.

---

## Documentation

| | |
|---|---|
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | Web server, TLS, systemd, MySQL tuning, database privileges, backups |
| [`docs/API.md`](docs/API.md) | Device API, request signing, error codes, browser API |
| [`docs/SECURITY.md`](docs/SECURITY.md) | The security model, threat by threat |
| [`docs/FIRMWARE.md`](docs/FIRMWARE.md) | Wiring, flashing, provisioning and the terminal state machine |
