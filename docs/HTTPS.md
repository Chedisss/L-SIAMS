# Turning on HTTPS

Everything L-SIAMS sends between a browser and the server, or between a
terminal and the server, currently crosses the network in clear text. On a
school Wi-Fi network that means anyone with a laptop and freely available
software can read:

- administrator and teacher passwords, at the moment they log in
- every card tap, with the student's name and section
- the session cookie, which is enough to *become* a logged-in administrator
  without knowing any password at all

None of that requires breaking anything. It is simply what unencrypted traffic
is. This document turns it off.

It takes about twenty minutes, and most of that is walking to the terminals.

---

## What changes

The one thing to understand before starting, because it surprises people:

> **`start.bat` stops being how you run the system.**

`start.bat` runs PHP's own small web server. That server has no support for
HTTPS and never will. Once you switch, **Apache** serves L-SIAMS — the Apache
that is already in XAMPP, which most installations have been leaving switched
off.

After the switch:

But `start.bat` does not go away, because it starts **three** things and Apache
replaces only one of them:

| | Before | After |
|---|---|---|
| The site | `start.bat` (PHP's built-in server) | **Apache**, from the XAMPP Control Panel |
| The worker | `start.bat` | `start.bat` — unchanged |
| Live dashboards | `start.bat` | `start.bat` — unchanged |
| The database | XAMPP Control Panel | XAMPP Control Panel — unchanged |

So the daily routine becomes **three clicks instead of two**:

1. XAMPP Control Panel → **Start MySQL**
2. XAMPP Control Panel → **Start Apache**
3. Double-click **`start.bat`**

`start.bat` notices that `.env` says `https://`, skips its own web server —
which cannot do TLS and would otherwise put a second, unencrypted copy of the
whole system on port 8080 — and starts just the worker and the realtime server.
It says so when it does, and opens the browser at the right address.

`stop.bat` still closes the worker and the realtime server. Apache and MySQL are
stopped from the Control Panel.

> **The worker is not optional.** It closes expired attendance sessions, marks
> devices offline and runs retention. A school that only starts Apache gets a
> site that looks perfectly healthy and quietly stops closing sessions.

---

## Step 0 — decide what address the server answers to

This is the only decision in the process, and it is worth two minutes because
redoing it later means re-flashing terminals.

A certificate is issued *for a specific name*. A browser opening
`https://192.168.1.10` will reject a certificate issued for
`attendance.school.local`, and vice versa — so the name on the certificate has
to be the name people actually type.

**If the server's IP address can change, fix it first.** A certificate for
`192.168.1.10` becomes useless the day the DHCP lease moves the server to
`192.168.1.23`. Ask whoever manages the router for a *DHCP reservation* (also
called a static lease) for the server's MAC address, or set a static IP on the
server itself. This is worth doing regardless of HTTPS — the terminals are
configured with that address too.

Then pick one:

- **IP address only** — simplest, works everywhere, nothing else to configure.
  Terminals and browsers both use `https://192.168.1.10`.
- **IP address and a hostname** — nicer to type, but the hostname has to
  resolve. That means either an entry on your router's DNS, or a line in the
  `hosts` file of every PC that uses it. If you are not sure, use the IP.

You can put both on the certificate, and the default does. Nothing is lost by
including a hostname you decide not to use later.

---

## Step 1 — issue the certificate

In the project folder:

```
console.bat tls:generate
```

With no arguments it works out the names from `APP_URL` and from the machine
itself, and prints what it chose. To name them yourself:

```
console.bat tls:generate --ip=192.168.1.10 --host=attendance.school.local
```

Both options can be repeated. `localhost` and `127.0.0.1` are always included.

This creates two things in `storage\tls`:

- **the school's root certificate** (`ca.crt`) — this is what browsers and
  terminals are told to trust. It lasts ten years.
- **the server certificate** (`server.crt`) — what Apache presents. It lasts
  about two years and three months.

> **The root is created once and reused.** Renewing the server certificate
> later does *not* change the root, which is why renewal does not mean
> re-flashing every terminal. Only `tls:generate --new-ca` replaces the root,
> it asks you to confirm, and it should essentially never be needed.

---

## Step 2 — configure Apache

```
console.bat tls:apache
```

This writes `storage\tls\lsiams-ssl.conf` with the correct paths already filled
in, and prints the two lines you need. Then:

1. Open `C:\xampp\apache\conf\httpd.conf` in Notepad.
2. Find the line `Include conf/extra/httpd-ssl.conf` and put a `#` in front of
   it. This is XAMPP's own SSL setup, with its own certificate; leaving it on
   means two things fighting over port 443.
3. At the very bottom of the file, add the `Include` line that `tls:apache`
   printed.
4. Save.
5. In the XAMPP Control Panel, **Stop** Apache if it is running, then **Start**
   it.

If Apache refuses to start, click its **Logs → Apache (error.log)** button in
the Control Panel. Nearly always it is one of: the `#` in step 2 was missed
(port 443 already in use), or the `Include` path has a typo.

---

## Step 3 — point the system at its new address

Open `.env` and set:

```
APP_URL=https://192.168.1.10
```

Use exactly the address you will type in the browser, with `https://` and no
trailing slash. If you are using a port other than 443, include it.

In the same file, turn on TLS for the live dashboards:

```
REALTIME_TLS_ENABLED=true
```

The realtime server is a second listener on its own port, and switching the site
to HTTPS does not switch it. A browser on an `https://` page **refuses** a
`ws://` socket as mixed content, and reports that only in its own developer
console — so the symptom that reaches you is "the dashboard stopped updating",
with nothing wrong anywhere in this system. It picks up the certificate you
just issued on its own; there is nothing else to set.

This matters more than it looks. `APP_URL` is what the system uses to build
links, and — because the session cookie is marked `Secure` — getting it wrong
produces a login page that accepts your password and then returns you to the
login page, with nothing in the log to explain why.

Now check the whole thing:

```
console.bat doctor
```

The **Transport security** section should be all ticks, ending with
`HTTPS answers and validates`. That last line is the one that proves Apache
actually picked up the certificate.

---

## Step 4 — trust the root on the PCs and phones

Until you do this, every browser shows a full-page warning. The warning is
correct: the browser has never heard of your school's root and is right to say
so. You are about to tell it.

Send `storage\tls\ca.crt` to each machine. **This file is public and safe to
email, copy or put on a USB stick** — it is the certificate, not the key.

On **Windows**:

1. Double-click `ca.crt`.
2. **Install Certificate…**
3. Choose **Local Machine** (not Current User), then Next. Approve the
   administrator prompt.
4. Choose **Place all certificates in the following store** → **Browse** →
   **Trusted Root Certification Authorities** → OK.
5. Next, Finish. Accept the security warning — it is asking whether you trust
   your own school, and you do.
6. **Close and reopen the browser completely.**

Chrome and Edge use the Windows store, so this covers both.

**Firefox keeps its own list.** If the school uses Firefox: Settings → Privacy
& Security → Certificates → View Certificates → Authorities → Import → pick
`ca.crt` → tick *Trust this CA to identify websites*.

On **Android**: Settings → Security → Encryption & credentials → Install a
certificate → CA certificate. On **iPhone**: open the file, install the
profile, then Settings → General → About → Certificate Trust Settings and turn
it on.

---

## Step 5 — the terminals

Each terminal has to be told the same root, and then pointed at the new
address. This means re-flashing, so do it once and do it properly.

1. Copy `storage\tls\ls_root_ca.h` into the sketch folder, next to
   `L_SIAMS_Bench.ino`. **Do not open or edit it** — the sketch finds it
   automatically and there is nothing to configure.
2. In `L_SIAMS_Bench.ino`, change `LS_SERVER_URL` to the https address:

   ```cpp
   #define LS_SERVER_URL   "https://192.168.1.10"
   ```

   Note the port: if you are on 443 there is no `:8080` any more.
3. Upload to the board.
4. Open the Serial Monitor at 115200 and watch it start. You should see the
   clock estimate line, then normal operation.

The sketch will refuse to run if `LS_SERVER_URL` is `https://` and the root
file is missing, and will say exactly that rather than failing at the first
card tap. It also prints a notice for the reverse mistake — root installed but
the URL never changed — so a half-finished migration cannot go unnoticed.

**Why the board needs the root at all:** without it the terminal would accept a
certificate from *anything* that answered at that address. Encrypting traffic
and then handing it to whoever picked up is not security, and it looks
identical to a working system from both ends.

---

## Renewing, about two years from now

`console.bat doctor` starts warning 30 days before the server certificate
expires. When it does:

```
console.bat tls:generate
```

Then restart Apache. **That is the whole procedure.** No terminal needs
touching, no PC needs the root reinstalling, because the root has not changed —
only the certificate it signed.

If you ever let it expire, nothing is damaged. Everything stops at once —
browsers and terminals together — and the same two steps bring it all back.

---

## What to back up

Add `storage\tls\` to whatever you back up, **together with `.env`**.

The root's private key is stored encrypted under `APP_KEY` from `.env`. That is
deliberate: someone who copies the folder off the server cannot use the root to
impersonate the school without also having `.env`. The cost is that the two
files belong together — with `storage\tls\` but no `.env`, the root cannot sign
a renewal, and renewing would then mean generating a new root and re-flashing
every terminal.

`console.bat doctor` checks this on every run and reports
`Root key readable` if the pairing is intact.

---

## If something goes wrong

**Browser: "Your connection is not private" / NET::ERR_CERT_AUTHORITY_INVALID**
The root is not installed on this machine, or the browser was not fully
restarted after installing it. Firefox needs its own import (Step 4).

**Browser: "NET::ERR_CERT_COMMON_NAME_INVALID"**
The address you typed is not on the certificate. Run `console.bat doctor` —
`Certificate covers APP_URL` will list what the certificate actually says.
Reissue with `--host=` or `--ip=` for the address you want.

**Apache will not start.**
Port 443 is in use, almost always because XAMPP's own
`Include conf/extra/httpd-ssl.conf` was not commented out (Step 2). Skype and
VMware have also been known to hold 443.

**The home page loads but every other page gives 404 Not Found.**
Apache is serving files but not routing through the front controller, which
means `mod_rewrite` is off. In `C:\xampp\apache\conf\httpd.conf` find
`#LoadModule rewrite_module modules/mod_rewrite.so` and remove the `#`, then
restart Apache. The generated config already sets `AllowOverride All`, which is
the other half of what `public\.htaccess` needs.

**The login page accepts the password and returns to the login page.**
`APP_URL` does not match what the browser is using. The session cookie is
`Secure` and scoped to that address, so a mismatch means the browser is told to
store a cookie it then will not send back. Fix `APP_URL` in `.env` (Step 3).

**The dashboards stopped updating live, but everything else works.**
`REALTIME_TLS_ENABLED` is still `false`, so the page is being handed a `ws://`
address that the browser refuses on an HTTPS page. Set it to `true` in `.env`,
restart `start.bat`, and re-run `console.bat doctor` — there is a check for
exactly this.

**Sessions stopped closing on their own; devices show online when they are not.**
The worker is not running. Apache does not start it — `start.bat` does. Run
`start.bat` as well as starting Apache.

**A terminal stopped connecting after the switch.**
Open the Serial Monitor. The sketch names the cause. The two usual ones are
`LS_SERVER_URL` still carrying the old `:8080` port, and the root file not
having been copied into the sketch folder before uploading.

**A terminal that worked has stopped after a power cut, months later.**
This is the one case where the board's own clock matters: it needs a rough idea
of the date to judge whether a certificate is in its validity window. The
sketch keeps the last confirmed time in flash for exactly this and falls back
to its build date, so it should recover on its own. If it does not, re-flashing
with a current build resets the estimate.

---

## What this does not protect against

Worth being clear, so nobody assumes more than is true.

- Someone who is **already an administrator** on the server can read
  everything. HTTPS protects data crossing the network, not data at rest.
- Someone who installs the school's root on their own machine **and** obtains
  the server's private key could impersonate the server. Guard
  `storage\tls\server.key` as carefully as `.env`.
- The database itself is not encrypted by this. Passwords are bcrypt hashes,
  terminal secrets and fingerprint templates are encrypted under `APP_KEY`, and
  backups are encrypted — but student names and attendance records sit in
  MariaDB in the clear, as they must for the system to query them.
- HTTPS does not stop a person who is logged in from doing anything they are
  allowed to do. That is what roles, permissions and the audit log are for.
