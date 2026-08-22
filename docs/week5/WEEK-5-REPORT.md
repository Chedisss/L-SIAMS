# Week 5 — System Integration and Prototype Preparation

Forms 1 to 7, in Markdown. The submission copy is
[`WEEK-5-Accomplished-Forms.docx`](WEEK-5-Accomplished-Forms.docx); the raw
output behind every result here is in [`TEST-EVIDENCE.md`](TEST-EVIDENCE.md).

| | |
|---|---|
| **Project Title** | L-SIAMS — Local IoT-Based Multi-Layer Secured Attendance Monitoring System with RFID and Biometric Authentication |
| **Alpha Version** | v0.5.0-alpha, branch `claude/week5-activity-r41f2a` |
| **Group Leader** | [Group Leader] |
| **Members** | [Member 2] · [Member 3] · [Member 4] · [Member 5] |
| **Project Adviser** | [Project Adviser] |
| **Date Submitted** | 22 August 2026 |

> **How this report was produced.** Every result in Forms 2, 3 and 6 comes from
> an actual run, not from a reading of the source code. The Alpha Version was
> installed from a clean database on MariaDB 10.11 and PHP 8.4, seeded, and then
> exercised three ways: the 44-case concurrency and correctness suite, the full
> device API driven over HTTP with real HMAC-signed requests, and every
> administrator screen and report export fetched from the running server. Seven
> defects were found this way. All seven were corrected and re-verified.
>
> Where a check failed, the form says so and says what was done about it.
> Nothing here is marked Pass on the strength of an inspection alone.

---

## Activity 1 · Module Integration Planning

### Form 1 — Module Integration Plan

| Module | Completion Status | Dependency | Sequence |
|---|---|---|---|
| Database schema and migrations (60 tables, 13 triggers, 19 migrations) | Complete | None — the foundation | 1 |
| Core framework (router, PDO layer, crypto, view engine) | Complete | Database | 2 |
| Authentication and session control | Complete | Core, Database | 3 |
| Security middleware chain (13 middleware) | Complete | Authentication | 4 |
| Academic structure (departments, grade levels, sections, subjects, classrooms, schedules) | Complete | Authentication | 5 |
| Student and RFID card management | Complete | Academic structure | 6 |
| Teacher and fingerprint enrolment | Complete | Academic structure, Devices | 7 |
| IoT device registry and provisioning | Complete | Security middleware | 8 |
| Attendance engine and status resolver | Complete | All of the above | 9 — the core |
| Device API | Complete | Attendance engine, Device registry | 10 |
| Realtime server | Complete | Attendance engine | 11 |
| Reporting and export (15 types; PDF, XLSX, CSV) | Complete | Attendance engine | 12 |
| Web interfaces (56 views, 20 modules) | Complete | All services | 13 |
| Maintenance worker | Complete | Attendance engine | 14 |
| ESP32 terminal firmware | **In Progress** | Device API | 15 |

**Note on the firmware.** The terminal sketch compiles and drives the full tap
and session flow against the server. The offline replay queue is implemented in
the reference firmware but not yet in the shipping sketch, so the terminal is
carried as In Progress. The server side of offline replay is complete and
tested — see Form 3, test 9.

### Integration Readiness Checklist

- [x] **Database Ready** — 19 migrations apply cleanly to an empty database; 60 tables, 13 triggers verified present
- [x] **Authentication Ready** — administrator and teacher sign-in verified against the running server
- [x] **Core Functionalities Ready** — session open, tap in, tap out, session close verified end to end
- [x] **User Interfaces Ready** — all 20 administrator modules return HTTP 200 with live data
- [x] **Repository Updated** — all Week 5 work committed to `claude/week5-activity-r41f2a`

---

## Activity 2 · Full System Integration

### Form 2 — System Integration Checklist

| Component | Status | Remarks |
|---|---|---|
| User Interface Modules | Integrated | All 20 administrator modules and the teacher portal served HTTP 200 with live data. 56 views, 164 web routes |
| Authentication Module | Integrated | Sign-in, CSRF, role separation, idle timeout and forced password change all exercised against the running server |
| Database Module | Integrated | 19 migrations applied to an empty database. Application runs under least-privilege credentials |
| Reporting Module | Integrated | All 15 report types generated in all 3 formats — 45 of 45 exports produced valid files |
| Notification Module | Integrated | Dashboard alerts raised from live data |
| IoT Device API | Integrated | Claim, time, heartbeat, status, sync and the attendance start/tap/end path driven over HTTP with real HMAC-signed requests |
| Realtime Server | Integrated | WebSocket reached from the browser; status pill reports "Live". Sequence replay covered by the suite |

### Integration Verification

| Verification Item | Result | Evidence |
|---|---|---|
| Data Flow Verified | **Pass** | A card tap produced an attendance row carrying the correct student, section, subject, teacher and classroom snapshot, and appeared on the administrator screens |
| Module Communication Verified | **Pass** | Device API → attendance engine → database → realtime server → browser, observed end to end in one session |
| Database Transactions Verified | **Pass** | 50 simultaneous taps of one card → exactly one row. 4 tap requests carrying 3 request ids → exactly 3 rows |
| User Access Verified | **Pass** | Administrator reached all 20 modules; unauthenticated requests redirected; unsigned device requests refused |
| Security Controls Verified | **Pass** | Invalid HMAC → 401 `SIGNATURE_INVALID`. Stale timestamp → 401 `TIMESTAMP_EXPIRED`. Unknown card → 404 `RFID_UNKNOWN`. Key rotation required password re-confirmation |
| Record Immutability Verified | **Pass** | All three layers tested; every attempt to alter or delete a log or attendance row was refused |

### Environment verified on

| Component | Version / Configuration |
|---|---|
| PHP | 8.4.19, no Composer dependencies |
| Database | MariaDB 10.11.14, READ-COMMITTED, STRICT_TRANS_TABLES, utf8mb4 |
| Database privileges | `lsiams_app` holds SELECT and INSERT database-wide, UPDATE and DELETE per table only |
| Realtime | WebSocket server on port 8443 |
| Terminal | `DEMO-DEV-0001`, real API key and HMAC secret, claimed with a single-use token |

### Required Evidence

- [x] Integration screenshots — 15 captures in [`screenshots/`](screenshots/)
- [x] Running system screenshot — live session ATT-2026-000102 with 18 students recorded
- [x] Repository commit evidence — branch `claude/week5-activity-r41f2a`

---

## Activity 3 · Preliminary Functionality Checking

### Form 3 — Preliminary Functionality Test Report

| Functionality | Expected Result | Actual Result | Status |
|---|---|---|---|
| User Login | Valid credentials open a session; invalid refused | Administrator signed in (HTTP 303). A request without a CSRF token refused with HTTP 419 | **Pass** |
| Data Entry | A new student is stored and retrievable | Student WK5-0001 registered, HTTP 201, `student_id` 331 | **Pass** |
| Data Processing | A tap is resolved into the correct intent by the server | Resolved as TIME IN; second tap 45 min later as TIME OUT with duration; third refused as `ALREADY_COMPLETE` | **Pass** |
| Data Retrieval | A stored record is found and shown in full | Found by number; detail page showed name, number, guardian. 18-student roster listed with live status | **Pass** |
| Report Generation | Reports build and export in every format | 15 types × 3 formats = 45 of 45 valid files (PDF up to 85 pages, valid XLSX, UTF-8 CSV) | **Pass** |
| Session Control | A session opens only on a verified fingerprint | ATT-2026-000102 opened by fingerprint for Antonio Cruz, MATH-7, G7-RIZAL. A tap before any session was refused with `SESSION_NOT_OPEN` | **Pass** |
| Concurrency Safety | Simultaneous taps cannot double-record | 50 concurrent taps → exactly 1 row, 49 logged as duplicates | **Pass** |
| Idempotent Retry | A retried request lands exactly once | 4 requests carrying 3 request ids → exactly 3 rows | **Pass** |
| Offline Replay | A queued batch reconciles exactly once | 25 records accepted; replay accepted nothing new and preserved original timestamps | **Pass** |
| Session Close | Absences and auto time-outs resolve on close | Roster of 30 fully accounted for: 3 present, 27 absent, 3 auto-stamped. Closing twice refused | **Pass** |
| Performance | Tap processing stays inside budget | p50 6.5 ms, p95 7.7 ms, max 18.1 ms; 148 taps/second. Budget 800 ms | **Pass** |
| Security Controls | Forged or stale requests refused | Invalid signature → 401. Timestamp outside ±30 s → 401. Unknown card → 404 | **Pass** |

**Automated suite: 44 cases passed, 0 failed, in 1.5 s, across 11 groups.**

### Issues Encountered

Seven defects were found during integration testing. All seven were corrected
and re-verified.

| Issue | Severity | Corrective Action |
|---|---|---|
| The `doctor` diagnostic aborted on any correctly hardened deployment — it tried to CREATE the migrations table, but the deployment guide gives the application no DDL rights. The one command an administrator runs when something is wrong was the one that died. | **High** | Made `doctor` read-only as documented; it now reports an unreadable migration history as a diagnosis. Verified clean under production privileges |
| A terminal whose clock drifted past ±30 s had no way to recover. The documented cure, `GET /api/device/time`, sits behind that same check. The middleware attached the server clock to the rejection, but the 401 handler discarded it. | **High** | The 401 response now carries its context through. Verified by driving a terminal 35 hours out of sync: it self-corrected from the rejection and completed the full tap flow |
| On demo data no teacher could open a session on any terminal. The seeder wrote fingerprint templates but never bound them to a sensor, and verification reads that binding. | **High** | The seeder now binds every teacher to every terminal. Rebuilt from empty: 35 bindings created, session-open flow verified |
| The security log and login history could be silently rewritten — both had a delete trigger but no update trigger, so "immutable three times over" held only for the audit log. | Medium | Added migration 019 with the two missing BEFORE UPDATE triggers. All three logs now refuse modification while still accepting appends |
| The deployment guide's database hardening could not be applied as written; its REVOKE statements fail with `ERROR 1147`, leaving an administrator believing a layer was in place when it was not. | Medium | Rewrote the guide to grant the narrow privilege instead of revoking the broad one, with a generator query and a verification step. Applied from a clean user and confirmed |
| The shipped schema file was several migrations behind — a phpMyAdmin install got no `fingerprint_slots` table and none of the columns from migrations 015–018. | Medium | Regenerated: 58 → 60 tables, 11 → 13 triggers, now matching the migrations |
| The documented way to run one test group did not work: the README gave `--group=`, which the runner ignores. | Low | Corrected to `--only=`. Both forms tested to confirm the difference |

### Tester Information

| | |
|---|---|
| **Conducted By** | [Group Leader] and project team |
| **Date** | 22 August 2026 |
| **Method** | Automated suite against a real database, plus HTTP-level exercise of the device API and every administrator screen on a running server |
| **Result** | 12 of 12 functionality areas Pass · 44 of 44 automated cases pass · 7 defects found, 7 fixed |

---

## Activity 4 · Alpha Version Validation

### Form 4 — Alpha Version Readiness Checklist

**Functional Requirements**

- [x] Core Functionalities Implemented — session control, tap capture, status resolution, reporting
- [x] User Authentication Functional — password sign-in for people, HMAC request signing for terminals
- [x] Database Connectivity Functional — running under least-privilege credentials
- [x] User Interfaces Functional — 20 administrator modules and the teacher portal
- [x] Major Workflows Functional — open session, tap in, tap out, close session, generate report

**System Stability**

- [x] No Critical Errors Encountered — after the seven defects in Form 3 were fixed and re-verified
- [x] Transactions Completed Successfully — 44 of 44 automated cases pass, including the concurrency races
- [x] Data Saved Correctly — identity fields immutable; times and statuses correctable with a recorded reason
- [x] Reports Generated Correctly — 45 of 45 exports produced valid, openable files

**Alpha Version Status**

- [x] **Approved for Prototype Presentation**
- [ ] Requires Additional Revision

### Remarks

The Alpha Version carries the complete attendance path end to end: a teacher
opens a register with their fingerprint on a classroom terminal, students tap in
and out, the server decides each tap's meaning, the record is written once and
cannot be re-attributed, and the result reaches both the administrator screens
and fifteen kinds of report.

Integration testing was worth doing rather than assuming. Seven defects
surfaced, three of them serious: the diagnostic command could not run on a
correctly secured deployment, a terminal with a drifted clock had no way back,
and on demo data no session could be opened at all — which would have stopped
the Week 6 demonstration at its first step. All seven are fixed and re-verified.

One item is deliberately carried forward rather than claimed complete: the
shipping terminal sketch does not yet implement the offline replay queue,
although the server side does and is tested.

---

## Activity 5 · Prototype Presentation Preparation

### Form 5 — Prototype Presentation Preparation Checklist

| Requirement | Completed |
|---|---|
| Project Overview Slides | ✅ Slides 1–3 |
| Problem Statement Slides | ✅ Slide 4 |
| Objectives Slides | ✅ Slide 5 |
| System Architecture Slides | ✅ Slides 6–7 |
| Database Design Slides | ✅ Slide 8 |
| System Features Slides | ✅ Slides 9–11 |
| Demonstration Script | ✅ Slides 12–13 + full script |
| System Screenshots | ✅ 15 captures |
| Team Presentation Roles Assigned | ✅ see below |

### Presentation Roles

| Member | Assigned Task |
|---|---|
| [Group Leader] | Opening, problem statement, objectives (slides 1–5); fields adviser questions |
| [Member 2] | System architecture and database design (slides 6–8) |
| [Member 3] | System features and the security model (slides 9–11) |
| [Member 4] | Live demonstration: opens the session by fingerprint and performs the taps |
| [Member 5] | Integration testing results and remaining work (slides 14–16); operates the slides |

### Demonstration Flow (8 minutes)

| # | Step | What the audience sees |
|---|---|---|
| 1 | Sign in as administrator | Dashboard with live counts |
| 2 | Show the schedule and the terminal | Terminal bound to Room 101 and claimed; schedule window open |
| 3 | Teacher presents fingerprint | Session opens — terminal names teacher, subject, section |
| 4 | Students tap their cards | Rows appear live; the pill reads "Live" |
| 5 | Tap the same card again | Refused: "TOO EARLY TO EXIT — 0/20 min" |
| 6 | Tap an unregistered card | Refused: "UNKNOWN CARD", and logged |
| 7 | Close the session | Absences resolved; roster fully accounted for |
| 8 | Generate a report | PDF downloads, produced without any third-party library |

The word-for-word script, with a fallback for every step, is in
[`DEMONSTRATION-SCRIPT.md`](DEMONSTRATION-SCRIPT.md).

---

## Activity 6 · System Integration Documentation

### Form 6 — System Integration Report

**Integrated Modules**

| Module | Status | Scale |
|---|---|---|
| Database schema, migrations and triggers | Integrated | 60 tables, 13 triggers |
| Core framework and security middleware | Integrated | 13 middleware |
| Authentication, roles and permissions | Integrated | 2 roles |
| Academic structure and scheduling | Integrated | 6 modules |
| Student, RFID card and fingerprint management | Integrated | 3 modules |
| IoT device registry and provisioning | Integrated | 5 terminals |
| Attendance engine and status resolver | Integrated | 7 final statuses |
| Device API | Integrated | 47 API routes |
| Realtime server | Integrated | 3 transports |
| Reporting and export | Integrated | 15 types × 3 formats |
| Web interfaces | Integrated | 56 views, 164 routes |
| Maintenance worker | Integrated | 4 sweeps |

### Integration Summary

The modules built in Weeks 3 and 4 were brought together in dependency order,
from the database upward, and the combined system was installed from an empty
database to confirm the sequence actually works rather than only appearing to.
Nineteen migrations applied cleanly, reference and demonstration data seeded,
and the application was then switched to the least-privilege credentials a real
deployment uses, so that integration was tested under production conditions
rather than as an administrator.

Integration was verified along the full path a real tap travels: an ESP32
terminal authenticates with a per-request HMAC signature, the server decides
whether the tap is an arrival or a departure, the attendance engine writes one
row under a unique key that makes a double-record impossible, and the event
reaches the administrator screens through the realtime server. Every one of
those hops was exercised over HTTP against the running system with genuinely
signed requests, not simulated.

Roughly 49,000 lines of PHP across 35 services, 25 controllers and 56 views now
operate as one system, with no third-party runtime dependencies.

### Preliminary Testing Summary

Three kinds of checking were done, and all three had to agree before a line in
Form 3 was marked Pass.

- The automated concurrency and correctness suite: 44 cases across 11 groups,
  all passing, run against a real MariaDB server rather than a mock. The central
  case fires 50 simultaneous taps of one card and asserts a single row survives.
- The device API driven over HTTP with real HMAC-signed requests, including the
  refusals — forged signature, stale timestamp, unknown card, premature exit.
- Every administrator screen and every report fetched from the running server:
  20 modules returned HTTP 200 with live data, and all 45 report exports
  produced valid files.

**Measured performance:** tap processing p50 6.5 ms, p95 7.7 ms, maximum
18.1 ms, against a budget of 800 ms; 148 taps per second sustained.

Seven defects were found and all seven were fixed and re-verified. Three would
have been visible during the Week 6 presentation itself.

### Enhancements Planned Before Week 6

| # | Enhancement | Reason |
|---|---|---|
| 1 | Implement the offline replay queue in the shipping terminal sketch | The server side is complete and tested; the terminal still needs it before a power cut can be survived without losing taps |
| 2 | Rehearse the demonstration on the physical ESP32 hardware | Every result in this report was produced against the server; the hardware path needs its own dry run |
| 3 | Generate a TLS certificate for the realtime server | It currently runs plaintext on the bench |
| 4 | Add a regression test for the fingerprint-to-terminal binding | The seeder defect was invisible to the existing suite |
| 5 | Re-run the full suite on the presentation machine the day before | Confirms the environment the demonstration will actually run on |

Prepared by **[Group Leader]**, Group Leader — Reviewed by **[Project Adviser]**, Project Adviser

---

## Activity 7 · Adviser Validation

### Form 7 — Adviser Validation Record

To be completed by the project adviser after the Alpha Version has been
demonstrated and the prototype materials presented.

| Evaluation Area | Status |
|---|---|
| Alpha Version Functionality | Approved / For Revision |
| Module Integration | Approved / For Revision |
| Prototype Presentation Materials | Approved / For Revision |
| Demonstration Flow | Approved / For Revision |
| Readiness for Week 6 Presentation | Approved / For Revision |

**Adviser Recommendations**

<br><br><br>

**Required Revisions**

<br><br><br>

Adviser Signature: ________________________    Date: ________________

---

## Week 5 Submission Checklist

| Required Submission | Status |
|---|---|
| Form 1 — Module Integration Plan | Complete |
| Form 2 — System Integration Checklist | Complete |
| Form 3 — Preliminary Functionality Test Report | Complete |
| Form 4 — Alpha Version Readiness Checklist | Complete |
| Form 5 — Prototype Presentation Preparation Checklist | Complete |
| Form 6 — System Integration Report | Complete |
| Form 7 — Adviser Validation Record | Awaiting adviser |
| Alpha Version Source Code | Pushed to `claude/week5-activity-r41f2a` |
| Prototype Presentation Slides | 17 slides |
| Supporting Screenshots and Evidence | 15 screenshots + full test evidence |
