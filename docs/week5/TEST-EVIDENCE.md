# Week 5 — Test Evidence

Raw output from every check behind Forms 2, 3 and 6, with the command that
produced it. Run on 22 August 2026 against PHP 8.4.19 and MariaDB 10.11.14.

---

## 1. Installation from an empty database

```
$ php bin/console migrate
✓ 19 migration(s) applied.

$ php bin/console seed --demo
  Demo data created:
    8 classrooms
    11 subjects
    7 teachers (all with fingerprints enrolled)
    5 sections
    140 students
    5 terminals (no API keys — register them from the Devices page)
    25 schedules
    108 closed sessions, 3021 attendance records
```

The application was then switched to the least-privilege credentials a real
deployment uses (`SELECT, INSERT` database-wide, `UPDATE`/`DELETE` per table),
so everything below was exercised under production conditions.

---

## 2. Automated concurrency and correctness suite

```
$ php tests/concurrency/run.php

  1. Simultaneous sessions on separate devices
  ✓ 4 devices open 4 independent sessions
  ✓ each session is scoped to its own classroom
  ✓ a second session on the same device is refused

  2. Duplicate race: 50 concurrent taps of one card
  ✓ exactly one attendance row exists
  ✓ exactly one tap was accepted
  ✓ the remaining 49 were rejected as duplicates
  ✓ every rejected tap was logged

  3. Cross-device race: one card, two terminals
  ✓ the first terminal accepts the tap
  ✓ the second is refused, not double-recorded
  ✓ at most one row per student per session

  4. Section mismatch is rejected, not recorded elsewhere
  ✓ a foreign-section card is rejected with SECTION_MISMATCH
  ✓ no attendance record was created for that student
  ✓ the rejection was logged with both sections
  ✓ the session rejected-tap counter incremented

  5. Time-in / time-out state machine
  ✓ the first tap is resolved as TIME IN
  ✓ tapping out before the minimum stay is refused
  ✓ the second tap is resolved as TIME OUT
  ✓ a duration was computed
  ✓ the third tap is refused as ALREADY_COMPLETE
  ✓ all of that produced exactly one row

  6. Status resolver produces all seven final values
  ✓ every arrival/departure combination resolves correctly
  ✓ at least 7 distinct final statuses are reachable
  ✓ auto-closed keeps the arrival status but is labelled

  7. Session close: auto time-out, absences and rollups
  ✓ the roster is fully accounted for
  ✓ 8 students who never tapped are marked Absent
  ✓ the 8 who never tapped out were auto-stamped
  ✓ no attending record is left pending
  ✓ the session is closed
  ✓ closing twice is refused

  8. Idempotent retries land exactly once
  ✓ a retried request_id does not create a second row
  ✓ exactly one row carries that request id

  9. Offline queue reconciles exactly once
  ✓ all 25 queued records were accepted
  ✓ replaying the batch accepts nothing new
  ✓ the replay is reported as duplicates
  ✓ still exactly 25 rows after the replay
  ✓ original timestamps were preserved, not rewritten to now

  10. Throughput and latency
    p50 latency: 6.5 ms
    p95 latency: 7.9 ms
    max latency: 18.1 ms
  ✓ p95 tap processing is under the 800 ms budget
    sustained rate: 148 taps/second
  ✓ sustained rate exceeds 100 taps/minute

  11. Realtime sequencing and replay
  ✓ sequences are strictly monotonic
  ✓ replay from sequence 10 returns the last 10 events
  ✓ replay starts at the next sequence
  ✓ no event is duplicated in a replay

  44 passed, 0 failed  ·  1.6s
    tap p95    7.9 ms
```

A second run after every fix in section 7: **44 passed, 0 failed, tap p95 7.7 ms.**

---

## 3. Administrator modules — all served live data

Each fetched over HTTP with an authenticated session:

```
/admin                    200      /admin/rfid              200
/admin/analytics          200      /admin/fingerprints      200
/admin/students           200      /admin/security          200
/admin/teachers           200      /admin/users             200
/admin/attendance         200      /admin/settings          200
/admin/attendance/live    200      /admin/departments       200
/admin/attendance/sessions 200     /admin/grade-levels      200
/admin/reports            200      /admin/sections          200
/admin/devices            200      /admin/subjects          200
/admin/schedules          200      /admin/classrooms        200
```

**20 of 20.**

---

## 4. Data entry and retrieval

```
POST /admin/students   →  HTTP 201
{"success":true,"code":"OK","message":"Student registered successfully.",
 "data":{"student_id":331}}

GET /admin/students/331 →  WK5-0001 · Week Five · Tester · Maria Tester
```

A request without a CSRF token was refused with **HTTP 419**.

---

## 5. Report generation — 15 types × 3 formats

```
daily                  pdf:OK(3451b)    xlsx:OK(3164b)    csv:OK(102b)
weekly                 pdf:OK(664859b)  xlsx:OK(42238b)   csv:OK(86873b)
monthly                pdf:OK(1984651b) xlsx:OK(119563b)  csv:OK(259694b)
student                pdf:OK(27670b)   xlsx:OK(4734b)    csv:OK(3088b)
teacher                pdf:OK(23152b)   xlsx:OK(4555b)    csv:OK(2084b)
subject                pdf:OK(7093b)    xlsx:OK(3608b)    csv:OK(700b)
section_daily          pdf:OK(38836b)   xlsx:OK(5649b)    csv:OK(4676b)
section_summary        pdf:OK(5281b)    xlsx:OK(3421b)    csv:OK(270b)
section_comparison     pdf:OK(3878b)    xlsx:OK(3245b)    csv:OK(141b)
adviser                pdf:OK(59192b)   xlsx:OK(6360b)    csv:OK(5165b)
section_roster_rfid    pdf:OK(21394b)   xlsx:OK(4911b)    csv:OK(2779b)
chronic_absence        pdf:OK(3154b)    xlsx:OK(3227b)    csv:OK(154b)
device_uptime          pdf:OK(4230b)    xlsx:OK(3282b)    csv:OK(224b)
audit                  pdf:OK(81821b)   xlsx:OK(7031b)    csv:OK(12150b)
security               pdf:OK(2794b)    xlsx:OK(3218b)    csv:OK(174b)
```

**45 of 45.** Files were opened to confirm they are real, not merely non-empty:
PDFs carry a `%PDF-1.4` header (the monthly report is 85 pages), XLSX files are
valid ZIP packages containing `xl/worksheets/sheet1.xml`, CSVs carry a UTF-8 BOM
and correct headers.

Six report types require a mandatory filter and correctly refuse without one —
`student`, `teacher`, `section_daily`, `section_comparison`, `adviser` and
`section_roster_rfid` each returned a validation error until given their
subject, and produced valid output once supplied.

---

## 6. Device API, driven over HTTP with real HMAC signatures

Signature computed as documented: `HMAC-SHA256` over
`{METHOD}\n{path}\n{device_id}\n{timestamp}\n{nonce}\n{sha256_hex(body)}`.

```
POST /api/device/claim       200  {"claim_status":"claimed"}
GET  /api/device/time        200  {"server_epoch":1787529900,"timezone":"Asia/Manila"}
POST /api/device/heartbeat   200  {"active_session":null,"device_locked":false}
GET  /api/device/status      200  {"health":"online","queue_depth":0}
POST /api/device/sync        200  {"accepted":0,"duplicate":0,"rejected":[]}
```

### Session open and the tap path

```
POST /api/attendance/start   201  SESSION_OPENED
     → teacher "Maria Reyes", display "SESSION OPEN / Reyes / ENG-7 G7-RIZAL"

POST /api/attendance/tap     201  TIME_IN_RECORDED  attendance_id 2681  Garcia, Lorraine
POST /api/attendance/tap     201  TIME_IN_RECORDED  attendance_id 2681  ← retry, same request_id
POST /api/attendance/tap     201  TIME_IN_RECORDED  attendance_id 2682  Gonzales, Luis
POST /api/attendance/tap     201  TIME_IN_RECORDED  attendance_id 2683  Navarro, Rafael
```

Four tap requests carrying three distinct `request_id` values produced exactly
three rows — confirmed in the database:

```sql
SELECT COUNT(*) FROM attendance_records WHERE attendance_id >= 2681;  -- 3
```

### Tap out, and the close

```
POST /api/attendance/tap     201  TIME_OUT_RECORDED  attendance_id 2681, duration computed
POST /api/attendance/tap     409  ALREADY_COMPLETE
POST /api/attendance/end     200  SESSION_CLOSED
     → roster_count 30, present 3, absent 27, timed_out 3
```

### Refusals — each one a control that must hold

```
bad signature          401  SIGNATURE_INVALID
stale timestamp        401  TIMESTAMP_EXPIRED
unknown card           404  RFID_UNKNOWN          display "UNKNOWN CARD"
tap-out too soon       409  MINIMUM_DWELL_NOT_MET display "TOO EARLY TO EXIT / 0/20 min"
tap with no session    409  SESSION_NOT_OPEN      display "NO SESSION / Awaiting teacher"
key rotation           422  VALIDATION_ERROR      "Enter your password to confirm this action."
```

### Clock drift recovery (after the fix in section 7.2)

A terminal deliberately set 35 hours out of sync:

```
GET /api/device/time   401  {"code":"TIMESTAMP_EXPIRED",
                             "data":{"server_epoch":1787529900}}
  → terminal corrects itself by +127683 s from the rejection payload
GET /api/device/time   200  OK
POST /api/attendance/start  201  SESSION_OPENED
```

---

## 7. Defects found, and the evidence for each

### 7.1 `doctor` aborted on a correctly hardened deployment — High

`doctor` is documented as read-only, but called `ensureMigrationsTable()`, which
issues `CREATE TABLE IF NOT EXISTS`. The deployment guide gives the application
no DDL rights, so:

```
$ php bin/console doctor
✗ SQLSTATE[42000]: 1142 CREATE command denied to user 'lsiams_app'@'localhost'
  for table `lsiams_db`.`schema_migrations`
```

The one command an administrator runs when something is wrong was the one
command that died. Fixed in `bin/console`: it now reads the migration history
and reports it as a diagnosis when unreadable. After the fix, `doctor` completes
under production privileges with only the expected warnings.

### 7.2 A drifted terminal had no way back — High

`GET /api/device/time` is documented as the cure for clock drift, but it sits
behind the same ±30 s freshness check, so a terminal drifted past the window can
never reach it. `DeviceAuthMiddleware` already attached the server clock to the
rejection for exactly this case — but `App::respondHttp()` called
`Response::fail(...)` for every 401 without passing `$e->context()`, so the hint
was computed and then discarded:

```
401  {"code":"TIMESTAMP_EXPIRED","data":[]}          ← before
401  {"code":"TIMESTAMP_EXPIRED","data":{"server_time":"…","server_epoch":…}}   ← after
```

Fixed in `app/Core/App.php`. Verified end to end above. The only other 401 that
carries context is a session-timeout reason; no credential material is exposed.

### 7.3 No teacher could open a session on demo data — High

Verification reads `fingerprint_slots` — a slot number is meaningless without the
device that allocated it. The demo seeder wrote `fingerprint_templates` but never
created a slot row and never set `enrolled_device_row_id`:

```sql
SELECT teacher_id, enrolled_device_row_id FROM fingerprint_templates;
-- 1..7, all NULL
```

```
POST /api/attendance/start  403  FINGERPRINT_UNKNOWN  "NOT RECOGNIZED"
```

`doctor` reported "enrolled 7 of 7 active teachers" and the seeder printed
"7 teachers (all with fingerprints enrolled)", so nothing looked wrong — but the
Week 6 demonstration would have failed at its first step. Fixed in
`DemoSeeder.php`: each teacher is now bound to every terminal, the state
migration 018 describes. After rebuilding from empty:

```
slot_rows  teachers  devices        source    status   count
35         7         5              enrolled  present  7
                                    synced    present  28
```

```
POST /api/attendance/start  201  SESSION_OPENED
```

### 7.4 The security log could be silently rewritten — Medium

The README states the audit log, security log and login history are append-only
"three times over", including `BEFORE UPDATE` and `BEFORE DELETE` triggers. Only
`audit_logs` had both:

```
trg_audit_no_delete           DELETE  audit_logs
trg_audit_no_update           UPDATE  audit_logs
trg_login_history_no_delete   DELETE  login_history
trg_security_no_delete        DELETE  security_logs
```

```sql
UPDATE security_logs SET severity='low' LIMIT 1;   -- succeeded
```

Deleting a security-log row was already impossible; rewriting one was not — and
that is the more useful of the two to an attacker. Added migration
`019_finish_the_append_only_triggers.sql`. After it:

```
UPDATE security_logs  →  ERROR 1644 (45000): Security logs are immutable and cannot be modified.
UPDATE login_history  →  ERROR 1644 (45000): Login history is permanent and cannot be modified.
INSERT login_history  →  allowed (append-only, not read-only)
```

### 7.5 The deployment guide's hardening could not be applied — Medium

The guide grants `SELECT, INSERT, UPDATE, DELETE ON lsiams_db.*` and then tells
the administrator to revoke per table. Neither MySQL nor MariaDB permits that:

```sql
REVOKE UPDATE, DELETE ON lsiams_db.audit_logs FROM 'lsiams_app'@'localhost';
ERROR 1147 (42000): There is no such grant defined for user 'lsiams_app'
on host 'localhost' on table 'audit_logs'
```

The layer described as "the only one an attacker holding the application's own
credentials cannot get around" was therefore absent on any installation that
followed the guide — while appearing to be present. `docs/DEPLOYMENT.md` now
grants the narrow privilege instead of revoking the broad one, with a generator
query and a verification step. Applied from a clean user, it takes effect with
no errors, and all three immutability layers were then re-tested:

```
-- Layer 3, application credentials
UPDATE audit_logs      ERROR 1142 ... denied
DELETE audit_logs      ERROR 1142 ... denied
UPDATE security_logs   ERROR 1142 ... denied
UPDATE login_history   ERROR 1142 ... denied
DELETE attendance_records          ERROR 1142 ... denied
DELETE rfid_logs                   ERROR 1142 ... denied
DELETE attendance_modifications    ERROR 1142 ... denied

-- Legitimate writes still work
INSERT audit_logs                   allowed
INSERT login_history                allowed
UPDATE attendance_records (tap-out) allowed
UPDATE students                     allowed

-- Layer 2, triggers, bypassing grants entirely
DELETE attendance_records                       ERROR 1644 ... permanent
UPDATE attendance_records SET student_id = 99   ERROR 1644 ... cannot be re-attributed
UPDATE audit_logs                               ERROR 1644 ... cannot be modified
```

### 7.6 The shipped schema file was four migrations behind — Medium

`database/lsiams_schema.sql` is the install path for anyone using phpMyAdmin
rather than the migrations. It was missing the `fingerprint_slots` table and
every column added by migrations 015–018. Regenerated:

| | before | after |
|---|---|---|
| tables | 58 | 60 |
| triggers | 11 | 13 |

### 7.7 The documented way to run one test group did nothing — Low

The README gave `--group=race`; the runner parses `--only=`. An unrecognised flag
is ignored, so anyone following the README silently ran the whole suite:

```
$ php tests/concurrency/run.php --only=race     →   4 passed, 0 failed
$ php tests/concurrency/run.php --group=race    →  44 passed, 0 failed
```

Corrected in `README.md`.

---

## 8. Final state

```
$ php tests/concurrency/run.php
  44 passed, 0 failed  ·  1.5s
    tap p95    7.7 ms

$ php bin/console doctor        # under production privileges
  ✓ PHP 8.1 or newer            8.4.19
  ✓ Extensions pdo_mysql, openssl, mbstring, json
  ✓ APP_KEY, API_KEY_PEPPER, REALTIME_TICKET_SECRET set
  ✓ Connected to lsiams_db
  ✓ All migrations applied      19 applied
  ✓ Teacher status and template records agree
  ✓ storage/logs, storage/reports, public/uploads writable
```
