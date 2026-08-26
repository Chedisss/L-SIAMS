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
| **Session control** | A session opens only when the teacher proves they are there — a fingerprint on the terminal, or their own password as a failover when the reader cannot read them — and only for a class they are actually scheduled to teach |
| **Offline tolerance** | Designed for it — every tap carries a timestamp taken at the tap and an idempotency key, so a retry can never double-record. The queue that replays them from flash is implemented in the reference firmware, not yet in the shipping terminal sketch |
| **Status model** | Separate arrival, departure and final statuses, so "arrived late and left early" is not flattened into one ambiguous word |
| **Reporting** | 15 report types, exported as PDF, Excel or CSV, all generated without any third-party library |
| **Realtime** | WebSocket with SSE and long-polling fallbacks, with sequence-based replay so no event is lost across a reconnect |
| **Administration** | Students, teachers, sections, subjects, schedules, classrooms, RFID cards, fingerprints, devices, users, settings and backups |
| **Security** | Per-request HMAC signing for devices, bcrypt for humans, immutable audit and attendance history, full security logging |

---

## Requirements

- **PHP 8.1 or newer.** Required: `pdo_mysql`, `openssl`, `mbstring`, `json`,
  `zlib`. Optional: `zip` (Excel export and the bulk device provisioning
  bundle) and `gd` (profile photo uploads) — the system runs without them and
  each feature says what to enable if you use it. Developed and tested on 8.4;
  nothing in the codebase uses a construct newer than 8.1.
- **MariaDB 10.6+** or **MySQL 8.0**. Verified against MariaDB 10.11 — which is
  also what runs underneath phpMyAdmin in a XAMPP install. MySQL 8 is supported
  but has not been exercised here.
- **Apache 2.4** with `mod_rewrite`, or **nginx** with PHP-FPM
- ESP32 terminals with an MFRC522 RFID reader and an AS608 or R307 fingerprint
  sensor. No display is needed — see [`firmware/README.md`](firmware/README.md)
  for the wiring and [`docs/FIRMWARE.md`](docs/FIRMWARE.md) for the detail

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
# edit .env: database credentials, TRUSTED_*_CIDRS
# APP_URL and REALTIME_WS_PUBLIC_URL may be left blank — the base URL and the
# realtime endpoint then follow the address each browser used, so one PC serves
# localhost and the whole LAN without either being configured. Set them behind
# a reverse proxy, where the public name is not the one this process sees.

php bin/console install           # migrate + seed + create the first administrator
```

### Windows and XAMPP — just run `start.bat`

Double-click **`start.bat`**. On its first run it copies the local
configuration, generates the cryptographic keys, creates the database, applies
the schema and asks you to make an administrator account; after that it simply
starts everything and opens the browser at <http://localhost:8080>.

Three windows stay open while the system runs — the site, the maintenance
worker and the realtime server. **`stop.bat`** closes all three.

When something is wrong and it is not obvious why, double-click
**`doctor.bat`**. It checks PHP, the keys, the database, the migrations,
whether `APP_KEY` still opens the encrypted data, every registered terminal
and the realtime server, and changes nothing.

**`console.bat`** runs any other console command without hunting for
`php.exe`:

```
doctor.bat                     check the installation and report problems
mysql-doctor.bat               find out why MySQL will not stay started
console.bat seed --demo        fill the system with sample data
console.bat user:create-admin  add another administrator
console.bat backup             take an encrypted backup
```

**`update.bat`** pulls a newer version in place — only the changed lines, then
any pending migrations. It leaves `.env`, the uploaded photos, the generated
reports and the backups alone, which is the reason to use it rather than
deleting the folder and downloading the ZIP again. On its first run it offers to
link an unzipped folder to the repository; that part happens once.

You still start **MySQL** yourself from the XAMPP Control Panel; `start.bat`
checks for it and tells you if it is not running.

> `start.bat` uses PHP's built-in web server rather than Apache, so it needs no
> XAMPP configuration at all. That server is single threaded, which is fine for
> one person developing or demonstrating, but is not what a classroom full of
> terminals should run against — deploy behind Apache or nginx using
> [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) for that.
>
> The local configuration it installs (`.env.local.example`) relaxes three
> settings that only make sense to relax on localhost, each labelled in the
> file: the session cookie is not marked Secure (a Secure cookie is never sent
> over `http://`, so login would fail), the realtime server runs without TLS
> (no certificate exists on a fresh machine), and Server-Sent Events are off
> (one open stream blocks a single-threaded server completely — measured, not
> assumed). **Do not deploy that file.**

### Installing through phpMyAdmin instead

If you administer the database through phpMyAdmin — a XAMPP install, typically —
you do not need to run the migrations from a shell:

1. In phpMyAdmin, open **Import** and upload
   [`database/lsiams_schema.sql`](database/lsiams_schema.sql). That single file
   carries every table, view, trigger, index and constraint, plus the reference
   data the system needs to start. It creates the `lsiams_db` database and
   selects it on the way in, so you do not have to make one first.
2. Copy `.env.example` to `.env` and fill in the database credentials
   (XAMPP's defaults are user `root` with an empty password).
3. Run `php bin/console key:generate` once, then
   `php bin/console user:create-admin` to create the first login.

If you paste the file's contents into the **SQL** tab rather than using
**Import**, make sure `lsiams_db` is selected in the left sidebar first, or
MySQL answers every statement with `#1046 - No database selected`.

The schema file deliberately contains **no user accounts and no student,
teacher or attendance data** — a schema that shipped a known login would be a
backdoor in every installation that used it. Regenerate it after any migration
with `php bin/console schema:dump`.

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
php bin/console doctor                  Diagnose a running installation, read-only
php bin/console schema:dump             Regenerate database/lsiams_schema.sql
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
firmware/          ESP32 terminal sketch, plus diagnostics (see firmware/README.md)
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

**A database behind the code says so, rather than answering 500.** The
likeliest thing to be wrong after an update is a schema a few migrations short
of what the code expects — and the application used to say nothing, so the
dashboard and every page touching a new column returned "An unexpected error
occurred", which describes a bug and sends somebody hunting for one. Pending
migrations now produce a 503 naming them and the one command that fixes it, and
a banner on every page that still renders. `update.bat` migrates for you; this
is for everybody who updates another way.

**Credentials are issued all-or-nothing.** Rotating a terminal's key and
building its provisioning file are one transaction, and every precondition is
checked before anything is written. They used to run as separate steps with the
rotation first, so a failure afterwards left the key already rotated and the new
credentials unshowable — a key is stored hashed, so nobody could ever recover
what had just been created, and the terminal was left counting down to its old
key auto-revoking.

**An import refuses what it cannot use; it never quietly drops it.** A card UID
column was normalised before it was validated, and normalising strips
everything that is not hexadecimal — so a cell reading `ZZZZ` became the empty
string and was read as "no card given". The preview reported no problems and
the student was created cardless, to be marked absent every day until somebody
worked out why. Emptiness is now decided from the raw cell, and anything that
is not a usable UID is reported with the value that was actually typed.

**Both themes are checked, not eyeballed.** Every colour that sits on a themed
surface is a token that flips with the theme, because the ones that were
literals did not: `alert-success` was `#14532D` text on a `#14532D` background
in dark mode — the same value, so the words were not there at all. The palette
is audited by walking all 23 admin and teacher pages in a real browser and
computing every text node's contrast against its effective background; both
themes come back clean at WCAG AA.

**A replaced card says what happened to it.** Issuing a card to a student who
already holds one retires the old row and chains the new one to it, and the
student's attendance is untouched — records reference the student, never the
card. What the retirement now also records is why: a lost card ends as `lost`,
a stolen one as `blacklisted` and can never be issued to anybody again, a
damaged one as `replaced`. All three are refused at the reader, but only the
reason lets a school answer which of its cards are unaccounted for.

**A rejected tap leaves everything behind that an accepted one does.** The
rejection unwinds its own transaction, so anything written inside it goes with
it — the scan log, the unknown-card tally, the security event, the
administrator alert and the live-feed broadcast are all buffered and written
once the rollback is done. Losing them loses exactly the evidence they exist
to preserve.

**An unregistered card tells somebody, once.** It is either a card that was
never enrolled — the student is being marked absent until it is — or somebody
at the reader trying cards, and both want an administrator today. The same UID
raises at most one alert per cooldown window, stops alerting entirely once it
has been triaged, and is escalated to high priority with a security event only
after it has been presented enough times to mean something different.

**A teacher can send a student out of the room, and must say why.** The card
reader refuses a tap-out before the minimum dwell, which is right against a
student tapping in and walking straight out and wrong for a child who has been
unwell for ten minutes. The teacher records the departure instead, from a fixed
list of reasons so the register can be counted, and their account is stored
against it. It is a real departure at the real time, marked Left Early — never
flagged as an automatic time-out, because a person witnessed this one.

**Presence carries to the next subject; absence never does.** A section stays in
one room across periods, so a student who was Present or Late when the bell went
starts the next subject Present, without tapping again. Anyone who was Absent,
Left Early, Excused or Incomplete carries nothing and taps in normally — missing
first period must not follow a student through the whole day. A carried record
sets `carried_from_session_id` and leaves the time-in terminal, IP and MAC NULL,
because no card was presented to any reader and a report must be able to tell
the difference.

---

## Testing

```bash
php tests/concurrency/run.php              # all groups
php tests/concurrency/run.php --only=race  # one group
php tests/concurrency/run.php --load       # opt in to the 30-minute soak
```

Twenty-one groups covering session opening, the concurrent-tap race,
cross-device taps, section mismatch, time-in/time-out sequencing, status
resolution, session close, idempotency, offline replay, throughput, the
realtime transport, which lesson a terminal will actually open a session for,
the handover that carries a register into the next subject, the teacher-led
early release, what an unrecognised card leaves behind, replacing a lost card
without losing its history, whether a report actually applies the filters its
form offers, whether an import ever discards a cell somebody filled in, and
whether issuing a terminal's credentials is all-or-nothing, and whether a
database behind the code says so.

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
| [`docs/RUNNING-ON-WINDOWS.md`](docs/RUNNING-ON-WINDOWS.md) | Step-by-step first run on Windows with XAMPP, and what to do when it breaks |
| [`docs/NETWORK-ACCESS.md`](docs/NETWORK-ACCESS.md) | Reaching the system from phones and laptops on the same school network |
| [`docs/MOVING-TO-ANOTHER-PC.md`](docs/MOVING-TO-ANOTHER-PC.md) | Moving the system to a different computer without losing the fingerprints and the terminals |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | Web server, TLS, systemd, MySQL tuning, database privileges, backups |
| [`docs/API.md`](docs/API.md) | Device API, request signing, error codes, browser API |
| [`docs/SECURITY.md`](docs/SECURITY.md) | The security model, threat by threat |
| [`docs/FIRMWARE.md`](docs/FIRMWARE.md) | Wiring, flashing, provisioning and the terminal state machine |
