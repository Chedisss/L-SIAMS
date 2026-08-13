# API

Two authentication models live in this system and they never mix.

| | Device API | Browser API |
|---|---|---|
| **Used by** | ESP32 terminals | The web front-end |
| **Paths** | `/api/device/*`, `/api/attendance/*`, `/api/fingerprint/*`, `/api/rfid/*` | everything else under `/api` |
| **Authentication** | HMAC-SHA256 signature per request | Session cookie |
| **CSRF token** | none, and none needed | required on every state-changing call |
| **Replay defence** | timestamp window + single-use nonce | same-origin + CSRF |

The device API has no cookies and no ambient authority, so there is nothing for
a forged cross-site request to ride on — a CSRF token would protect nothing. The
signature covers the method, path, body, timestamp and nonce, which is the
stronger guarantee. The browser API is the reverse: it *has* ambient authority
in the form of a session cookie, so it needs the CSRF token.

All responses are JSON with a consistent envelope:

```json
{
  "success": true,
  "code": "TIME_IN_RECORDED",
  "message": "Time in recorded for Dela Cruz, Juan.",
  "data": { }
}
```

On failure, `success` is `false`, `code` carries a stable machine-readable
identifier, and `message` carries text safe to show a person. **Error messages
never contain key material, hashes, SQL, stack traces or file paths.**

---

## Device API

### Request signing

Every device request carries five headers:

| Header | Contents |
|---|---|
| `X-LSIAMS-Device-Id` | The device's public identifier, e.g. `DEV-2026-0001` |
| `X-LSIAMS-Api-Key` | The API key, formatted `{key_id}.{secret}` |
| `X-LSIAMS-Timestamp` | Unix seconds |
| `X-LSIAMS-Nonce` | A fresh random value, never reused by this device |
| `X-LSIAMS-Signature` | Lowercase hex HMAC-SHA256, computed below |

Plus, on any request that writes attendance:

| Header | Contents |
|---|---|
| `X-LSIAMS-Request-Id` | A UUIDv4 generated once and reused across retries |

The signature is computed over a canonical string built from exactly six lines,
joined with `\n`:

```
{METHOD}\n{path}\n{device_id}\n{timestamp}\n{nonce}\n{sha256_hex(body)}
```

- `METHOD` is uppercase (`POST`).
- `path` is the request path only — no query string, no scheme, no host.
- `sha256_hex(body)` is the lowercase hex SHA-256 of the raw request body. For a
  request with no body, it is the SHA-256 of the empty string
  (`e3b0c442...b855`), not an empty line.

```
signature = hex(hmac_sha256(canonical_string, hmac_secret))
```

Worked example:

```
POST
/api/attendance/tap
DEV-2026-0001
1774000000
7f3a91c4e8b25d06
a7c1...  ← sha256 of {"rfid_uid":"04A7C1935D","request_id":"…"}
```

The signature is compared with `hash_equals()`. A comparison that returned early
on the first differing byte would leak the correct signature one byte at a time
to anyone willing to measure.

### What the server checks, in order

The device middleware runs these before any attendance logic sees the request.
Every one of them is a hard stop.

1. **Source address** — the caller must be inside `TRUSTED_DEVICE_CIDRS`.
   A stolen key is useless from the wrong subnet.
2. **Rate limit** — 60 requests per minute per device, burst 30.
3. **Credentials present** — the device id and API key headers must be there,
   or `DEVICE_UNKNOWN`.
4. **Key valid** — split at the dot, look up `key_id`, compare
   `SHA-256(secret‖pepper)` with `hash_equals`, then check the key's lifecycle
   state → `API_KEY_INVALID`.
5. **Device state** — must be known, claimed, not disabled, not blocked and not
   locked → `DEVICE_UNKNOWN`, `DEVICE_UNCLAIMED`, `DEVICE_DISABLED`,
   `DEVICE_BLOCKED`, `DEVICE_LOCKED`.
6. **IP allowlist** — if the device has one, the source must match, or the
   request is refused and logged as an anomaly.
7. **Timestamp** — within ±30 s of server time → `TIMESTAMP_EXPIRED`.
8. **Nonce** — inserted against a UNIQUE key, so reuse fails at the database
   rather than in a check that could race → `REPLAY_DETECTED`.
9. **Signature** — recomputed and compared → `SIGNATURE_INVALID`. Repeated
   failures auto-revoke the key.
10. **Idempotency** — if `X-LSIAMS-Request-Id` has been seen in the last 24 h,
    the *original* response is replayed and the handler never runs again.

### Endpoints

#### `POST /api/device/claim`

The one device endpoint that runs before the terminal has usable credentials. It
authenticates with the single-use claim token from the provisioning file instead
of a signature.

```json
{ "claim_token": "…", "device_id": "DEV-2026-0001", "mac_address": "AA:BB:CC:DD:EE:01" }
```

The token is consumed on success and can never be presented again. A terminal
that needs to be re-flashed needs a new provisioning file.

#### `POST /api/device/heartbeat`

```json
{ "firmware": "1.0.0", "wifi_signal": -58, "queue": 0, "uptime": 84213, "battery": null, "free_heap": 142000 }
```

Returns the server time so the terminal can correct its clock, and any pending
instruction. Missing heartbeats are what mark a device offline.

#### `POST /api/device/sync`

Replays the terminal's offline queue, oldest first. Each queued item carries the
`request_id` and the original `timestamp` recorded when the tap happened.

Duplicates are counted as handled rather than treated as errors — a terminal
that synced successfully but lost the response will retry, and that must be
harmless.

```json
{ "accepted": 12, "duplicate": 3, "rejected": [] }
```

#### `GET /api/device/time`

Server time, for clock correction. The most common cause of
`SIGNATURE_INVALID` in the field is drift.

#### `POST /api/attendance/start`

Fingerprint verification. **This is the only thing that opens a session** — no
password, no button, no admin action on the web side.

```json
{ "fingerprint_id": 3, "confidence": 142, "schedule_id": null }
```

The server checks that the slot maps to a teacher, that the teacher is active,
and that they are actually scheduled to teach in that classroom at that time.
Failures return `FINGERPRINT_UNKNOWN` (the slot maps to nobody),
`FINGERPRINT_DISABLED`, `TEACHER_INACTIVE`, `NO_ACTIVE_SCHEDULE` (nothing
scheduled for that teacher in that room at that time) or `SESSION_ALREADY_OPEN`.
Success returns `FINGERPRINT_VERIFIED` followed by `SESSION_OPENED`.

#### `POST /api/attendance/tap`

The main endpoint. `/api/rfid/scan` and `/api/attendance/record` are aliases
that resolve identically — the latter kept so an older firmware build keeps
working across a server upgrade.

```json
{
  "rfid_uid": "04A7C1935D",
  "request_id": "3f8b1c6e-…",
  "timestamp": "2026-08-05T08:03:11+08:00",
  "intent": null
}
```

`intent` is optional and normally omitted. **The server decides whether a tap is
a time-in or a time-out**, from the session state, the schedule windows and the
device's configured role. A terminal configured as entry-only or exit-only has
its role honoured; otherwise the student's current state decides.

`timestamp` is honoured **only for queued replays**. For a live tap the server
uses its own clock, so a terminal with a drifting RTC cannot shift a student
between Present and Late.

Successful response:

```json
{
  "success": true,
  "code": "TIME_IN_RECORDED",
  "data": {
    "intent": "time_in",
    "student": "Dela Cruz, Juan",
    "status": "Present",
    "display_line_1": "TIME IN — PRESENT",
    "display_line_2": "DELA CRUZ, J.",
    "display_line_3": "08:03  PRESENT",
    "feedback": "success"
  }
}
```

The `display_line_*` and `feedback` fields exist so the terminal renders what
the server decided rather than composing its own interpretation. The firmware
does not know what "Late" means; it just prints line 1.

#### `GET /api/fingerprint/enrollment`

Asked every two seconds by an idle terminal. Answers with the enrolment the
Fingerprints page has queued for *this* terminal, if any.

```json
{
  "success": true,
  "data": {
    "enrollment": {
      "request_id": 42,
      "sensor_template_id": 7,
      "teacher_name": "Grace Villanueva",
      "employee_number": "EMP-0007",
      "stage": "ready"
    },
    "poll_seconds": 2,
    "display_line_1": "ENROLL FINGER",
    "display_line_2": "Villanueva"
  }
}
```

`enrollment` is `null` when there is nothing to do. Reading this endpoint moves
the request from `pending` to `scanning`, which is how the browser knows the
terminal has heard.

`discard_slots` lists templates the sensor is holding that nothing owns — a
registration captured and then abandoned. Delete each, then confirm.

#### `POST /api/fingerprint/enrollment/discarded`

```json
{ "sensor_template_id": 9 }
```

The slot is only returned to circulation on this confirmation, never on the
instruction alone: freeing it earlier would let the delete land after the next
person had been enrolled into it. A slot already empty when the delete runs is
the expected result of a lost confirmation — report it as success.

#### `POST /api/fingerprint/enrollment/progress`

```json
{ "request_id": 42, "stage": "place_finger" }
```

`stage` is one of `waiting_for_device`, `ready`, `place_finger`, `remove_finger`,
`place_again`, `storing`, `done`. Each report restarts the request's expiry
clock, so the timeout measures the gap between steps rather than the whole
capture.

#### `POST /api/fingerprint/enrollment/complete`

```json
{ "request_id": 42, "sensor_template_id": 7, "quality_score": 168, "sample_count": 2 }
```

`sensor_template_id` is the slot the sensor **actually** wrote, not the slot that
was requested. If the two differ the enrolment is discarded and the request is
marked failed — binding a teacher to a slot the sensor did not use would point
at whatever finger already occupied it.

#### `POST /api/fingerprint/enrollment/failed`

```json
{ "request_id": 42, "reason": "The two scans did not match." }
```

The reason is shown verbatim in the enrolment wizard, so it is written for the
administrator standing at the screen rather than for a log.

### Error codes

Rejections are business outcomes, not failures. Each returns HTTP 200 with
`success: false` where the tap was understood but refused, and an appropriate
4xx where the request itself was malformed or unauthenticated.

| Code | Meaning |
|---|---|
| `RFID_UNKNOWN` | Card is not in the system. Logged to `unknown_rfid_logs`. |
| `RFID_UNASSIGNED` | Card exists but belongs to no student. |
| `RFID_DISABLED` | Card is lost, blacklisted or replaced. |
| `STUDENT_INACTIVE` | Student is archived, transferred or graduated. |
| `SECTION_MISMATCH` | Student is not in the section this session is for. |
| `NOT_ENROLLED_IN_SUBJECT` | Student is not enrolled in this subject. |
| `SESSION_NOT_OPEN` | No session is open on this terminal. |
| `SESSION_CLOSED` | The session closed between the tap and its arrival. |
| `TIME_IN_NOT_YET_OPEN` | Before the time-in window opens. |
| `TIME_IN_CLOSED` | After the time-in window closes. |
| `TIME_OUT_CLOSED` | After the time-out window closes. |
| `DUPLICATE_TIME_IN` | Already timed in to this session. |
| `MINIMUM_DWELL_NOT_MET` | Tapping out before the minimum stay has elapsed. |
| `ALREADY_COMPLETE` | Both taps already recorded. |
| `DEVICE_CLASSROOM_MISMATCH` | Terminal is not the one assigned to this session. |
| `ENROLLMENT_IN_PROGRESS` | That terminal is already enrolling somebody. One at a time. |
| `ENROLLMENT_NOT_OPEN` | The request was cancelled, completed or expired before the report arrived. |
| `SLOT_MISMATCH` | The sensor wrote a different slot than the one allocated; the enrolment was discarded. |
| `WRONG_DEVICE` | The request belongs to another terminal. |
| `DUPLICATE_REQUEST` | Idempotency hit — the original response is replayed. |
| `TIMESTAMP_EXPIRED` | Outside the ±30 s window. Check the clock. |
| `REPLAY_DETECTED` | Nonce reused. |
| `SIGNATURE_INVALID` | HMAC did not verify. |
| `API_KEY_INVALID` | Key unknown, revoked, expired or suspended. |
| `DEVICE_UNKNOWN` / `DEVICE_UNCLAIMED` / `DEVICE_DISABLED` / `DEVICE_BLOCKED` / `DEVICE_LOCKED` | Device lifecycle states. |
| `SESSION_ALREADY_OPEN` | A session is already open on this classroom or device. |
| `SESSION_ALREADY_CLOSED` | The session was closed already; closing twice is refused, not double-counted. |
| `FINGERPRINT_UNKNOWN` / `FINGERPRINT_DISABLED` | Sensor slot maps to nobody, or the enrolment is disabled. |
| `NO_ACTIVE_SCHEDULE` | The teacher has nothing scheduled in this room now. |
| `TEACHER_INACTIVE` | The teacher's record is inactive or archived. |

---

## Browser API

Session-cookie authenticated, CSRF-protected, and subject to the same
server-authoritative idle timeout as the rest of the web application.

### The passive-request rule

Any request that is *not* a deliberate user action must carry:

```
X-Passive-Request: true
```

Polling, SSE, WebSocket ticket requests, chart refreshes and the notification
badge all set it, and the server does not extend the idle timer for them. This
is the whole reason the 10-minute timeout means anything: without it, an open
dashboard would refresh itself forever and no session would ever expire.

The front-end helper `LS.http.poll()` sets the header for you. Use it for
anything the user did not click.

### Endpoints

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/health` | Liveness and server clock. Unauthenticated on purpose, discloses nothing else. |
| `GET` | `/api/attendance/live` | Recent taps for the live feed. |
| `GET` | `/api/attendance/session/{id}/summary` | Live counters for one session. |
| `POST` | `/api/realtime/ticket` | Short-lived single-use WebSocket ticket. |
| `GET` | `/api/realtime/replay?after={seq}` | Events after a sequence number. |
| `GET` | `/api/realtime/poll` | Long-poll fallback. |
| `GET` | `/api/realtime/stream` | SSE fallback. |
| `GET` | `/api/dashboard/teacher` | Teacher dashboard payload. |
| `GET` | `/api/dashboard/admin` | Administrator dashboard payload. |
| `GET` | `/api/analytics?chart={name}` | One analytics dataset, or `all`. |
| `GET` | `/api/devices/status` | Fleet health. |
| `GET` | `/api/security/summary` | Security dashboard. |
| `GET` | `/api/search?q=` | Global search. |
| `GET` | `/api/subjects/assignable` | Subjects a given teacher may be assigned. |
| `GET` | `/api/sections/assignable` | Sections a given teacher may be assigned. |
| `GET` | `/api/classrooms/assignable` | Classrooms free at a given time. |
| `GET` | `/api/teachers/{id}/constraints` | Everything constraining one teacher. |
| `POST` | `/api/schedules/check-conflict` | Pre-flight schedule conflict check. |

The four `assignable` endpoints exist to drive cascading dropdowns. **They are a
convenience, not a control.** Every assignment is re-validated server-side by
`TeacherSubjectAssignmentValidator` and `TeacherSectionAssignmentValidator`
before anything is written, so a hand-crafted POST bypassing the dropdowns gains
nothing.

### Realtime transport

Three tiers, degrading automatically:

1. **WebSocket** (`wss://host:8443`) — authenticated with a single-use ticket
   obtained from `POST /api/realtime/ticket`. The ticket is short-lived and
   consumed on connect, so a URL captured from a log or a browser history is
   already spent.
2. **Server-Sent Events** (`/api/realtime/stream`).
3. **Long polling** (`/api/realtime/poll`).

Every event carries a monotonic per-channel `sequence`. On reconnect the client
sends the last sequence it saw and the server replays everything after it, so a
dropped connection loses no events rather than silently skipping them.

Channels are permission-scoped server-side: a teacher subscribing to
`section.*` receives only their own sections, whatever they ask for.

---

## Rate limits

| Bucket | Limit |
|---|---|
| Device | 60 / minute, burst 30 |
| Login | 10 / 5 minutes per IP + username |
| Browser API | 300 / minute per user |
| Web pages | 600 / minute per user |

Exceeding a limit returns HTTP 429 with `RATE_LIMIT_EXCEEDED` and a
`Retry-After` header. Login limiting is keyed on both IP and username, so neither spraying one
password across many accounts nor hammering one account goes unnoticed.
