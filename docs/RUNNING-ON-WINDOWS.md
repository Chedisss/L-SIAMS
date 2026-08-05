# Running L-SIAMS on Windows

A step-by-step guide for a Windows machine with XAMPP. Follow it once and the
system is set up; after that, running it is a double-click.

This is the guide for **developing and demonstrating** — writing your capstone,
testing, showing the system to a panel. Putting it into a school for real is a
different job, covered in [DEPLOYMENT.md](DEPLOYMENT.md).

---

## Before you start

You need two things.

### 1. XAMPP

Download it from <https://www.apachefriends.org> and install it. Take the
defaults; installing to `C:\xampp` is easiest because the scripts look there
first.

XAMPP gives you three things this system uses:

| | |
|---|---|
| **PHP** | the language the system is written in |
| **MariaDB** | the database (XAMPP's Control Panel labels the button "MySQL" — same thing) |
| **phpMyAdmin** | a web page for looking inside the database |

You do **not** need Apache for this guide. `start.bat` runs its own small web
server, so there is no XAMPP configuration to do.

### 2. The project files

Put the project folder somewhere simple, like `C:\L-SIAMS`.

It does **not** need to go in `htdocs`. `start.bat` serves the site itself, and
keeping it out of `htdocs` is actually safer — nothing is exposed by Apache by
accident.

---

## First run — about five minutes

### Step 1 — Start the database

Open the **XAMPP Control Panel**. Next to **MySQL**, click **Start**.

Wait until the MySQL row turns green. That is the only thing you need running.

> If MySQL will not start, something else on your PC is already using port 3306
> — often a separately installed MySQL. Stop that service, or change the port in
> XAMPP and put the same port in the `.env` file (created in the next step).

### Step 2 — Double-click `start.bat`

Open `C:\L-SIAMS` and double-click **`start.bat`**. A black window opens and
walks through the setup. You will see it:

1. find PHP and check the version and extensions
2. create the `.env` configuration file
3. generate the cryptographic keys
4. create the `lsiams_db` database
5. apply the database schema (7 migrations)
6. load the reference data — roles, permissions, grade levels, departments

Then it stops and asks you to create your administrator account.

### Step 3 — Create your administrator account

It asks four things, in this order:

```
Full name: Juan Dela Cruz
Username:  admin
Email:     admin@school.local
Password:  (you will not see what you type)
Confirm password:
```

**The password rules are strict** — this is an attendance system, so it insists:

- at least **12 characters**
- at least one **UPPERCASE** letter
- at least one **lowercase** letter
- at least one **number**
- at least one **symbol** (`! @ # $ % & * ?` …)
- not your own name, username or email
- not on the common-password list

A password like `MySchool-2026!Pass` satisfies all of it.

If it refuses, it prints exactly which rule failed. Fix it and run `start.bat`
again.

> Nothing you type at the password prompt appears on screen — not even dots.
> That is normal. Type it and press Enter.

### Step 4 — The system starts

Three windows open and stay open:

| Window | What it does |
|---|---|
| **L-SIAMS web** | the site itself — this is the window `start.bat` becomes |
| **L-SIAMS worker** | closes sessions left open, marks offline terminals, tidies old data |
| **L-SIAMS realtime** | pushes live updates to the dashboards |

Your browser opens at **<http://localhost:8080>** by itself.

### Step 5 — Log in

Use the username and password from Step 3. You land on the dashboard.

It will be mostly empty, because there are no students yet. That is the next
step.

---

## Add sample data (recommended)

An empty system is hard to judge. This fills it with a small complete school so
every page has something real on it.

Open a Command Prompt in the project folder — Shift + right-click the folder in
Explorer, then "Open PowerShell window here" — and run:

```
console.bat seed --demo
```

That creates 7 teachers with fingerprints enrolled, 5 sections, ~140 students
with RFID cards, 5 terminals, 25 schedules, and **30 school days of attendance**
— around 3,000 records.

Refresh your browser. The dashboard, reports and analytics now have data.

> It refuses to run if the database already holds real students, so you cannot
> mix sample data into genuine records by accident.

The demo teachers can log in too — username is first initial + surname
(`mreyes`, `acruz`, `lbautista`), and the password is printed when the seeder
finishes. Log in as one to see the teacher's side, which is deliberately much
narrower than the administrator's.

---

## Every day after that

1. **XAMPP Control Panel → Start MySQL**
2. **Double-click `start.bat`**

That is all. It skips the whole setup because `.env` already exists, and goes
straight to starting the system.

To stop: **double-click `stop.bat`**, or just close the three windows. MySQL
keeps running until you stop it in the Control Panel.

---

## `console.bat` — the useful commands

Open a Command Prompt in the project folder (Shift + right-click the folder →
"Open PowerShell/Terminal here") and run:

| Command | What it does |
|---|---|
| `console.bat check` | check PHP, extensions, `.env` and the database in one screen |
| `console.bat seed --demo` | fill the system with sample data |
| `console.bat user:create-admin` | add another administrator |
| `console.bat backup` | take an encrypted backup right now |
| `console.bat migrate:status` | show which database changes have been applied |
| `console.bat security:audit-keys` | check the key-generation code for weak randomness |
| `console.bat` | list everything |

---

## When something goes wrong

Before anything else, run `console.bat check`. It prints PHP, the extensions,
`.env`, the folders and the database on one screen, and each failing line says
what to run to fix it.

**"Could not find php.exe"**
XAMPP is not installed, or not on `C:`, `D:` or `E:`. Install it, or add
`C:\xampp\php` to your PATH.

**"PHP is missing: …" or "Optional PHP extensions are off: …"**
An extension is switched off in `php.ini`. Turn it on like this:

1. Run `console.bat check` and read the **php.ini in use** line — edit *that*
   file, not any other `php.ini` on the PC. On a normal XAMPP install it is
   `C:\xampp\php\php.ini`.
2. Open it in Notepad. (Right-click Notepad → **Run as administrator** if
   Windows refuses to save.)
3. Press **Ctrl + F** and search for `extension=zip` — or `extension=gd`,
   whichever was named.
4. You will find a line like `;extension=zip`. **Delete the semicolon** at the
   very start so it reads `extension=zip`.
5. Repeat for each extension the message listed.
6. **Save** (Ctrl + S) and close Notepad.
7. Close the L-SIAMS window and double-click `start.bat` again.

The two optional ones are not worth panicking about:

| Off | What stops working | What still works |
|---|---|---|
| `zip` | Excel `.xlsx` export and import, device provisioning bundles | reports as CSV and PDF, adding devices one at a time |
| `gd` | uploading a profile photo | everything else on the profile page |

Attendance, RFID, fingerprints, schedules, dashboards and the reports you need
for a defence do not depend on either. If they are off, `start.bat` says so and
carries on, and the affected pages explain themselves when you use them.

**"Cannot connect to MySQL"**
MySQL is not started. XAMPP Control Panel → Start next to MySQL. If it is green
and this still happens, check `DB_USER` and `DB_PASS` in the `.env` file —
XAMPP's default is user `root` with an empty password.

**The browser says "can't reach this page"**
The web window closed. Look for an error in it, then run `start.bat` again.

**Port 8080 is already in use**
Something else is using it. Open `start.bat` in Notepad and change
`set "WEB_PORT=8080"` to `8081`, then change `APP_URL` in `.env` to match.

**Login says the password is wrong and you are sure it is not**
Check `SESSION_COOKIE_SECURE=false` in `.env`. A "Secure" cookie is never sent
over `http://`, so login fails silently if that is set to true locally.

**Everything freezes when a page is open**
Check `REALTIME_SSE_ENABLED=false` in `.env`. The small development web server
handles one request at a time, and a live-updates stream would hold it open
forever.

**You forgot the administrator password**
Create another administrator: `console.bat user:create-admin`. There is no way
to read the old one — passwords are stored as bcrypt hashes, which is the point.

---

## What to say if you are asked "how does it run?"

Useful for a defence panel:

- **PHP** serves the web application; **MariaDB** stores the data.
- `start.bat` runs three processes: the **web server**, a **maintenance worker**
  that closes abandoned sessions and tidies old data, and a **realtime server**
  that pushes live updates to open dashboards.
- The classroom terminals are **ESP32** boards that talk to the web application
  over the school's own network — every request they send is **signed**, so a
  laptop on the same network cannot forge attendance.
- Nothing needs the internet. The whole system runs on one machine inside the
  school.

---

## Important: this setup is for development

`start.bat` is built for one person on one machine. It uses PHP's small
built-in web server, which handles **one request at a time**, and the local
configuration turns off three protections that only make sense to relax on your
own PC — they are each labelled in `.env.local.example`:

| Relaxed locally | Why | Why it matters in a school |
|---|---|---|
| Session cookie not marked Secure | a Secure cookie is never sent over `http://`, so login would fail | over HTTPS this must be on, or the cookie can be read in transit |
| Realtime server runs without TLS | no certificate exists on a fresh PC | otherwise attendance events cross the network in the clear |
| Server-Sent Events off | one open stream blocks the single-threaded dev server | on a real server this is the fastest live-update path |

For an actual classroom installation — Apache or nginx, HTTPS, the worker and
realtime server running as services, database privileges locked down and
backups going off the machine — follow [DEPLOYMENT.md](DEPLOYMENT.md) instead.
