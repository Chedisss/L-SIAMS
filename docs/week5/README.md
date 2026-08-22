# Week 5 — System Integration and Prototype Preparation

The Alpha Version of L-SIAMS, the record of how it was verified, and the
materials for the Week 6 prototype presentation.

| File | What it is |
|---|---|
| [`WEEK-5-Accomplished-Forms.docx`](WEEK-5-Accomplished-Forms.docx) | The official WEEK 5 activity template with Forms 1–7 filled in — original layout, header and CCIT logos untouched. The document to submit |
| [`WEEK-5-REPORT.md`](WEEK-5-REPORT.md) | The same content in Markdown, readable in the repository |
| [`TEST-EVIDENCE.md`](TEST-EVIDENCE.md) | Raw output from every check, with the commands that produced it |
| [`DEMONSTRATION-SCRIPT.md`](DEMONSTRATION-SCRIPT.md) | Word-for-word script for the Week 6 demonstration, with fallbacks |
| [`L-SIAMS-Prototype-Presentation.pptx`](L-SIAMS-Prototype-Presentation.pptx) | 17 slides, with speaker notes on every one |
| [`screenshots/`](screenshots/) | 15 captures of the running Alpha Version |

## What was verified, and how

Nothing here was marked Pass on the strength of reading the source. The Alpha
Version was installed from an empty database on MariaDB 10.11 and PHP 8.4 and
then exercised three ways:

- the 44-case concurrency and correctness suite, against a real database;
- the device API over HTTP, with genuinely HMAC-signed requests;
- every administrator screen and every report export, fetched from the running
  server.

**Result: 44 of 44 automated cases pass, 45 of 45 report exports produce valid
files, 20 of 20 administrator modules serve live data.**

## Seven defects were found, and all seven were fixed

Integration testing was worth doing rather than assuming. Three of these would
have been visible during the Week 6 presentation itself.

| Severity | Defect | Fix |
|---|---|---|
| High | On demo data no teacher could open a session on any terminal — the seeder never bound fingerprints to a sensor | `database/seeders/DemoSeeder.php` |
| High | A terminal whose clock drifted past ±30 s had no way to recover: the 401 discarded the server clock it carried | `app/Core/App.php` |
| High | `doctor` aborted on any correctly hardened deployment — it needed DDL rights the deployment guide withholds | `bin/console` |
| Medium | `security_logs` and `login_history` had no BEFORE UPDATE trigger, so they could be silently rewritten | migration `019` |
| Medium | The deployment guide's database hardening could not be applied as written (`ERROR 1147`) | `docs/DEPLOYMENT.md` |
| Medium | The shipped schema file was four migrations behind | `database/lsiams_schema.sql` |
| Low | The README's documented way to run one test group silently ran all of them | `README.md` |

Each is described in full, with the reasoning, in
[`WEEK-5-REPORT.md`](WEEK-5-REPORT.md) and evidenced in
[`TEST-EVIDENCE.md`](TEST-EVIDENCE.md).

## Reproducing the verification

```bash
cp .env.example .env          # set DB credentials
php bin/console install       # migrate + seed + create an administrator
php bin/console seed --demo   # a small complete school to demonstrate against

php tests/concurrency/run.php # the 44-case suite
php bin/console doctor        # read-only diagnosis of the installation
```

The suite creates and destroys test data prefixed `TEST-CONC-`. Point it at a
test database, never at a school's live data.
