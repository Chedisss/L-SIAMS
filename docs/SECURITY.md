# Security model

This describes what the system defends against, how, and — where a defence has
limits — what those limits are. It is written for whoever has to maintain the
system after the people who built it have moved on.

The organising principle is that **security is not a layer this system has, it
is a property of how every request is handled.** There is no code path that
reaches the database without passing through the middleware chain, and no
attendance write that bypasses the tap engine.

---

## 1. Threats and the defences against them

### A student taps their friend's card to mark them present

Not defended against, and deliberately so — this is the honest limitation of any
card-based system, and no software control can distinguish a card presented by
its owner from the same card presented by someone else.

What the system does instead is make it *visible*: the tap is recorded with the
device, timestamp and session, and the reports surface patterns (a student whose
card is always tapped at exactly the same second as another's). Schools that need
certainty need biometric verification for students, which the hardware in this
build does not have.

Stated plainly so nobody assumes a guarantee that is not there.

### A teacher opens a session for a class they do not teach

Blocked. A session opens only on fingerprint verification, and the server then
checks that the identified teacher is scheduled to teach *in that classroom, at
that time*. There is no override, no admin button and no API that opens a session
some other way. Failures return `NO_ACTIVE_SCHEDULE` and are logged.

### Someone replays a captured tap request

Blocked three ways. Every device request carries a timestamp checked against a
±30 s window, and a nonce inserted against a UNIQUE key — reuse fails at the
database, not in an application check that could race. Attendance writes
additionally carry a UUIDv4 `request_id` recorded for 24 h; a repeat replays the
*original response* rather than executing again.

### Someone forges a tap from a laptop on the school network

Blocked. Every device request is HMAC-SHA256 signed over method, path, device id,
timestamp, nonce and a SHA-256 of the body. Without the device's HMAC secret the
signature cannot be produced, and the secret is stored encrypted (AES-256-GCM
under `APP_KEY`) rather than in plaintext.

Beyond that, requests from outside `TRUSTED_DEVICE_CIDRS` are refused before
authentication runs, and a device with an IP allowlist configured is refused from
any other address, which is logged as `API_KEY_IP_ANOMALY`.

### A terminal is stolen and its firmware dumped

Contained, not prevented. An attacker who extracts the flash gets that one
device's key and HMAC secret — nothing else. They cannot derive another device's
credentials, because keys are independent 256-bit CSPRNG values with no shared
derivation.

Revoking that device's key from the Devices page stops it immediately. The IP
allowlist means the stolen terminal is useless outside the school network even
before anyone notices.

What an attacker *could* do before revocation is submit taps for that classroom.
They cannot forge attendance for a student in another section — the section check
rejects it — and they cannot open a session, because that needs a fingerprint the
sensor validates.

### A tap arrives 50 times simultaneously

Handled at the schema. `UNIQUE KEY uq_attendance_session_student (session_id,
student_id)` makes a duplicate structurally impossible; the second insert fails
with error 1062 and the application translates that into `DUPLICATE_TIME_IN`.

This is the difference between a system that is correct under load and one that
merely appears to be. A `SELECT` followed by an `INSERT` would let two concurrent
requests both see "no record" and both insert. There is no amount of application
care that fixes that; the constraint has to be in the database. The concurrency
test suite fires exactly this case and asserts one row.

### An administrator edits attendance to cover something up

Constrained and recorded. Attendance rows cannot be deleted — a `BEFORE DELETE`
trigger raises `SQLSTATE 45000` and the application's database user has no
`DELETE` privilege on the table.

They can be *corrected*, because genuine mistakes happen, but:

- a `BEFORE UPDATE` trigger rejects any change to the student, session, section,
  grade level, subject, teacher, classroom, card UID, request id or creation
  time — a record can never be re-attributed to somebody else;
- every changed field is written to `attendance_modifications` with the
  administrator, the old value, the new value and a reason;
- a trigger rejects a blank reason, because `NOT NULL` alone would accept `''`;
- the correction is also written to the audit log, which cannot be modified or
  deleted at all.

An administrator with database credentials still cannot quietly rewrite history,
because the application user they would be using holds no `UPDATE` on
`audit_logs` and no `DELETE` anywhere that matters.

### Someone deletes the audit log to hide their tracks

Blocked at three independent layers, any one of which would suffice:

1. No update or delete method exists on `AuditService`. There is no code to call.
2. `BEFORE UPDATE` and `BEFORE DELETE` triggers raise `SQLSTATE 45000`.
3. The application's database user is granted only `INSERT` and `SELECT` on
   `audit_logs`, `security_logs` and `login_history`.

Layer 3 is the one that survives an application compromise: an attacker running
arbitrary SQL as `lsiams_app` still cannot delete a log row.

Defeating all three requires the MySQL root credential, which the application
does not hold and which should not live on the same machine.

### Brute force against a login

Rate-limited on both the IP and the username, so neither spraying one password
across many accounts nor hammering one account passes unnoticed. Ten attempts per
five minutes; then the account locks and `ACCOUNT_LOCKED` is logged.

Passwords are bcrypt cost 12, minimum 12 characters, with upper, lower, digit and
symbol required, checked against a blocklist and against the user's own name,
username and email. The last five hashes are kept so a password cannot be
recycled.

Login responses are deliberately uniform: a wrong username and a wrong password
produce the same message and comparable timing, so the form cannot be used to
enumerate valid accounts.

### A session cookie is stolen

Limited. The cookie is `__Host-` prefixed, `Secure`, `HttpOnly`, `SameSite=Strict`
— so it is not readable from JavaScript, not sent cross-site, and not settable by
a subdomain.

The session is additionally bound to the user agent and to the IP prefix (/24 for
IPv4, /64 for IPv6). A mismatch is treated as
`SESSION_HIJACK_SUSPECTED`, terminates the session and alerts.

An attacker on the same /24 with the same user agent string could still use a
stolen cookie until it idles out. That is why the idle timeout is short and why
it cannot be extended by background traffic.

### Someone leaves a dashboard open to keep their session alive

Blocked, and this is the specific reason the passive-request rule exists. The
10-minute idle timeout is enforced server-side from `last_activity_at`. Every
background request — polling, SSE, WebSocket traffic, chart refreshes, the
notification badge — carries `X-Passive-Request: true`, and the middleware does
not extend the timer for it. A structural allowlist of passive paths backs the
header up, so a client that forgets to set it still cannot extend the session.

If passive refreshes reset the timer, the session would never expire and the
control would not exist.

### SQL injection

Every database access goes through prepared statements with bound parameters.
There is no string concatenation of user input into SQL anywhere in the codebase.

Where a query needs a dynamic identifier — a sort column, a direction — the value
is checked against an allowlist of known column names rather than interpolated.
`LIMIT` and `OFFSET` are cast to integers.

### Stored XSS

All output is escaped through `e()`, which is `htmlspecialchars` with
`ENT_QUOTES | ENT_SUBSTITUTE` and UTF-8. There is no "trusted HTML" escape hatch
in the templates.

The Content-Security-Policy forbids inline event handlers and external script
sources, so even an escape failure has no obvious path to execution.

### A PHP file uploaded as a student photo

Blocked at four points: extension allowlist, declared MIME check, actual content
inspection, and re-encoding through GD — which turns any file that is not really
an image into a failure, and strips any payload smuggled inside one that is.

The stored filename is randomised, so a guessed path cannot reach an upload. And
`public/uploads/.htaccess` sets `php_flag engine off`, with the equivalent
`location` block in the nginx configuration, so even a file that somehow arrived
would be served as bytes rather than executed.

### CSV formula injection

A cell beginning `=`, `+`, `-`, `@`, tab or carriage return is prefixed with a
single quote in `CsvWriter::sanitize()`. Without this, a student "named"
`=cmd|'/c calc'!A1` would execute on the machine of whoever opened the export.

The attack is against the person reading the report, not the server, which is
exactly why it is easy to forget.

### A backup is stolen

Backups are gzip-compressed then AES-256-GCM encrypted under `APP_KEY`. A stolen
backup file without `.env` is ciphertext.

The corollary matters as much: **`APP_KEY` must be stored separately from the
backups**, or a single stolen disk yields both. If `APP_KEY` is lost, the backups
are unrecoverable — there is no recovery mechanism, by design.

### Timing attacks against secret comparison

Two patterns are used, and the distinction matters.

**Where a presented secret is compared against a known value in PHP, the
comparison is `hash_equals()`** — API key secrets (`Crypto::verifySecret`), HMAC
signatures (`Crypto::verifyHmac`), CSRF tokens, realtime ticket signatures, and
the user-agent binding hash.

`==` and `===` return as soon as bytes differ, which leaks the correct value one
byte at a time to anyone able to measure. On a LAN, where round-trip variance is
microseconds, that is a practical attack rather than a theoretical one.

**Where a secret identifies a row, it is looked up by its SHA-256 in an indexed
column** and never compared in PHP at all — session tokens, device claim tokens
and realtime tickets. `WHERE token_hash = :hash` either finds the row or does
not.

This is not a weaker choice. The value being matched is a hash of the presented
token, so an attacker cannot extend a guess one byte at a time the way they could
against a raw-secret comparison — doing so would require finding SHA-256
preimages. It also means a database dump yields hashes rather than usable
tokens, which a plaintext-token column would not.

Neither pattern ever stores or compares the raw secret.

### A teacher grants themselves a subject outside their department

Blocked server-side. `TeacherSubjectAssignmentValidator::assertAssignable()`
rejects it and logs `CROSS_DEPARTMENT_ASSIGNMENT_BLOCKED`; the section equivalent
logs `GRADE_LEVEL_MISMATCH_BLOCKED`.

The filtered dropdowns in the interface are a convenience. **The validators are
the control.** A hand-crafted POST that skips the interface hits exactly the same
check.

### A teacher reads another teacher's data

Blocked by scoping rather than by hiding. Teacher-facing queries force
`teacher_id` from the session and ignore whatever the request supplies; the
session-detail page re-checks ownership before rendering; report filters are
overridden server-side; realtime channels are permission-scoped so a teacher
subscribing to `section.*` receives only their own sections.

The teacher permission set is deliberately narrow — attendance, schedules,
reports and their own profile. Nothing about other teachers, devices, security
logs, users or settings.

---

## 2. What is logged

Three separate, permanent records:

| | Contents |
|---|---|
| **Audit log** | Every state change: who, what, when, from where, old value, new value |
| **Security log** | Every refused or suspicious request, with a severity |
| **Login history** | Every authentication attempt, successful or not |

None of them can be edited or deleted, by anyone, through the application.

Security events carry a severity that feeds the risk score on the Security
Center. The vocabulary includes `SIGNATURE_INVALID`, `REPLAY_DETECTED`,
`TIMESTAMP_EXPIRED`, `API_KEY_IP_ANOMALY`, `DEVICE_SPOOFING`, `CSRF_FAILURE`,
`SESSION_HIJACK_SUSPECTED`, `PERMISSION_VIOLATION`, `SQL_INJECTION_ATTEMPT`,
`XSS_ATTEMPT`, `MALICIOUS_UPLOAD`, `UNAUTHORIZED_CHANNEL_SUBSCRIBE`,
`CROSS_DEPARTMENT_ASSIGNMENT_BLOCKED` and `UNKNOWN_RFID`, among others.

### What is never logged

- API keys, HMAC secrets, claim tokens, session tokens, CSRF tokens — not in the
  audit log, not in the security log, not in a value field, not in an error
  message, not in a URL.
- Passwords, in any form, at any point.
- Fingerprint templates. The database stores a slot number on the sensor and
  nothing else. **No biometric data ever reaches this system**, which reduces its
  exposure to a template breach to zero rather than to "encrypted".

Keys never appear in URLs or query strings either, so they cannot end up in a
web-server access log, a proxy log or a browser history.

---

## 3. Credential handling

### Human passwords — bcrypt

Cost 12, 12-character minimum, four character classes, blocklist-checked, and
checked against the user's own identifiers. History depth 5. Never stored in
plaintext, never emailed, never recoverable — a forgotten password is reset, not
retrieved.

A generated password is displayed once, on screen, at creation. There is no
second chance to see it.

### Device keys — SHA-256, deliberately

This looks wrong at first glance and is worth understanding, because someone will
eventually "fix" it.

A device API key is 256 bits from a CSPRNG. Brute-forcing it is not a matter of
being slow enough — it is computationally impossible regardless of the hash.
Bcrypt exists to make *low-entropy* secrets expensive to guess; against a
256-bit random value it buys nothing.

What it would cost is real: bcrypt at cost 12 takes roughly 250 ms, on every
signed request. At classroom tap rates — dozens of taps a minute across several
terminals, plus heartbeats — that alone would sink the system.

So device keys use `SHA-256(secret‖pepper)`, with the pepper in `.env` rather
than the database, so a database-only compromise does not yield offline-crackable
material. Human passwords, which are low-entropy by nature, stay on bcrypt. The
reasoning is documented at the call site in `app/Core/Crypto.php`.

### Key lifecycle

- Generated with `random_bytes()`. **`rand()`, `mt_rand()` and `uniqid()` appear
  nowhere in the key path** — `php bin/console security:audit-keys` greps for
  them and samples 1,000 generated keys for uniqueness.
- Displayed exactly once, immediately after generation. Never again, to anyone,
  including the administrator who created it.
- Stored as a hash plus the last four characters, which is enough to identify a
  key in a list without disclosing it.
- Rotatable with a grace window, so a terminal can be reflashed without downtime;
  the old key auto-revokes when the window closes.
- Revocable immediately, which takes effect on the next request.
- Auto-revoked after repeated signature failures.

---

## 4. The middleware chain

Every web request passes through, in order:

```
security-headers → network-allowlist → rate-limit → audit-context
    → auth → session-timeout → force-password → csrf → role/permission
```

Every device request passes through:

```
security-headers → network-allowlist:device → audit-context
    → rate-limit:device → device-auth → idempotency
```

The order is the security contract and is documented in `routes/web.php` and
`routes/api.php` alongside the routes. Two orderings matter in particular:

- **`network-allowlist` before `auth`** — a request from an untrusted subnet is
  refused before any credential is examined, so a stolen credential cannot even
  be tested from the wrong network.
- **`idempotency` before the controller** — a retried tap is answered from the
  stored response and never reaches the attendance engine at all.

---

## 5. Known limitations

Stated plainly, because a security document that claims completeness is not
trustworthy.

1. **Card sharing cannot be detected.** See the first threat above. The hardware
   would need student biometrics.
2. **A compromised terminal can submit taps for its own classroom** until its key
   is revoked. It cannot open sessions or affect other sections.
3. **Session binding is to an IP prefix, not an address**, so mobile clients keep
   working across minor network changes. An attacker on the same /24 with the
   same user agent could use a stolen cookie until it idles out.
4. **There is no two-factor authentication.** The schema has columns reserved for
   it (`two_factor_enabled`, `two_factor_secret`) but no implementation. For a
   LAN-only system with network allowlisting this was judged acceptable; a school
   exposing the system beyond its own network should implement it first.
5. **`APP_KEY` is a single point of failure.** It encrypts HMAC secrets and
   backups. Lose it and the backups are unrecoverable; leak it together with a
   database dump and the HMAC secrets are exposed.
6. **The audit log can be truncated by anyone holding the MySQL root
   credential.** The application cannot defend against a credential it does not
   hold. Off-machine backups are the mitigation.
7. **No intrusion detection.** The system logs and scores security events but
   does not act on them beyond rate limiting, account locking and API-key
   auto-revocation. Someone has to read the Security Center.

---

## 6. Operational checklist

- Review the Security Center weekly. Repeated `SIGNATURE_INVALID` from one device
  is usually clock drift; from many is worth investigating.
- Rotate device API keys on the schedule the Devices page flags (90 days by
  default).
- Revoke a terminal's key the moment it goes missing. Do not wait to see if it
  turns up.
- Verify a backup restore on a scratch database once a term.
- Run `php bin/console security:audit-keys` after any change to the key path.
- Keep `APP_KEY` backed up somewhere other than alongside the backups.
- Check that the immutability `REVOKE`s are still in force after any database
  maintenance — a restore from a full dump can quietly reinstate privileges.
