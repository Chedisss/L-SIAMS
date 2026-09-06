# Moving L-SIAMS to another PC

The system lives in three separate places, and moving house means carrying all
three. Take two and leave one, and it will start — it just will not work, in
ways that look like unrelated hardware faults.

| What | Where it lives | Carried by `git` or `update.bat`? |
|---|---|---|
| The code | the project folder | **yes** |
| The database | MySQL, inside XAMPP | no |
| The keys | `.env` in the project folder | **no** — deliberately |

`.env` is deliberately not in version control, because it holds secrets. That
is correct, and it is also exactly why it gets left behind.

> **Only the address changing?** A new router, or a renumbered network, with the
> server staying on the same machine — skip to
> [Changing the server's address without moving the PC](#changing-the-servers-address-without-moving-the-pc).
> Nothing needs carrying, and the school's root certificate survives untouched.

---

## The one thing that cannot be undone

**`APP_KEY` in `.env` encrypts data inside the database.** Three kinds:

- every terminal's **HMAC secret** — what signs each device request
- every **fingerprint template** — the copies that sync between terminals
- every **encrypted backup** you have ever taken

Those values are encrypted with AES-256-GCM. There is no recovery, no reset,
and no support route: a wrong key does not produce wrong data, it produces
nothing at all. If you copy the database to a new PC and run `key:generate`
there, all three become permanently unreadable.

The symptoms do not point at the cause. Terminals stop authenticating, and
fingerprints stop matching, and each looks like its own fault at its own
device. Double-click **`doctor.bat`** — it decrypts one sample of each and says
so plainly if the key no longer fits.

> **Take `.env` first, before anything else.** It is a small text file and it
> is the only irreplaceable part. The database can be re-entered by hand if it
> has to be; the key cannot be reconstructed from anything, anywhere.

---

## Moving with your data

Do this when the old PC still works and you want the teachers, students,
sections, schedules, cards and fingerprints to come across.

### On the old PC

1. **Back up the database.**
   ```
   php bin\console backup
   ```
   The file lands in `storage/backups`. It is encrypted with `APP_KEY`, so it
   is useless on its own — which is the point of step 2.

2. **Copy `.env`.** Onto a USB stick, or anywhere you can reach from the new
   machine. This is the step people skip.

3. **Copy `storage/uploads`** if you want the student and teacher photos.

### On the new PC

4. **Install XAMPP**, start Apache and MySQL, and get the project folder in
   place — clone it, or unzip it and run `update.bat` once to link it.

5. **Put the old `.env` in the project folder.** Do **not** run
   `key:generate`. If you already have, replace the whole file with the old
   one; the keys must match the data exactly.

6. **Update the addresses in `.env`** if the new PC differs — database
   credentials, and `APP_URL` if you set one. Leave every `*_KEY`, `*_SECRET`
   and `*_PEPPER` line untouched.

7. **Restore the database.**
   ```
   php bin\console backup:decrypt storage\backups\<file>.sql.gz.lsiams
   php bin\console db:import <the .sql it produced> --fresh
   php bin\console migrate
   ```

8. **Check it landed.**
   ```
   doctor.bat
   ```
   The **Encryption key** section must show a tick. A cross there means the
   `.env` and the database do not belong together — go back to step 5.

### The terminals

Each ESP32 has the server's address compiled into it, and that address has
almost certainly changed.

9. Run `ipconfig` on the new PC and note the **IPv4 Address**.

10. In each terminal's sketch, set `LS_SERVER_URL` to it. On an installation
    serving HTTPS that is the address on its own — port 443 is implied:
    ```cpp
    #define LS_SERVER_URL   "https://192.168.1.193"
    ```
    On one still on plain HTTP it carries the scheme and the port:
    ```cpp
    #define LS_SERVER_URL   "http://192.168.1.193:8080"
    ```
    Then upload. Every other value stays as it was — the device ID, the API
    key and the HMAC secret all still match, because the database came with
    you. On an HTTPS installation `ls_root_ca.h` stays as it was too: the
    root certificate belongs to the school, not to the machine, and moving
    house does not change it.

11. **Reserve that address in the router** (DHCP → Address Reservation, tied
    to the PC's MAC). A DHCP lease moves on its own after a router restart,
    and when it does every terminal in the building stops at once with no
    apparent cause.

---

## Changing the server's address without moving the PC

A new router, a renumbered network, a move from `192.168.1.x` to
`192.168.100.x`. The machine stays where it is and everything on it is intact;
what changes is the address four separate things were told to expect.

**The reassuring part first.** The school's **root certificate does not
change.** It is issued to the school, not to an address, and `tls:generate`
deliberately reuses an existing one. So no staff PC re-installs `ca.crt`, no
phone re-trusts anything, and no terminal gets a new `ls_root_ca.h`. Only the
server certificate — the one Apache presents — is reissued.

### Before you swap the router

**1. Reserve the new address.** DHCP reservation or a static IP, tied to the
server's MAC. Do this first: everything below compiles that address into
terminals, and a lease that moves later means doing it all again.

**2. Widen the allowlist while you can still reach the site.** In `.env`, list
both ranges:

```
TRUSTED_WEB_CIDRS=192.168.1.0/24,192.168.100.0/24
TRUSTED_DEVICE_CIDRS=192.168.1.0/24,192.168.100.0/24
```

> **Do this before the move, not after.** A request from outside these ranges
> is refused **before authentication**, so a browser on the new subnet cannot
> reach the login page, let alone the settings that would fix it. Both ranges
> together cost nothing during the transition; trim the old one once the new
> network is settled.

### On the server, after the move

**3.** In `.env`, point the application at the new address:

```
APP_URL=https://192.168.100.7
REALTIME_WS_PUBLIC_URL=wss://192.168.100.7:8443
```

The realtime line is easy to miss and fails quietly: a browser on an `https://`
page refuses a socket at the wrong address, and the only symptom is dashboards
that stop updating.

**4.** Reissue the server certificate for the new address and rewrite the
Apache configuration:

```
console.bat tls:generate --ip=192.168.100.7
console.bat tls:apache
```

Restart Apache from the XAMPP Control Panel. Do **not** pass `--new-ca`; that
replaces the root and invalidates every installed copy of it, which is the one
outcome this procedure exists to avoid.

**5. Check the Windows firewall profile.** The `netsh` rules are written
against ports, not addresses, so they survive the change. What does not survive
is the network's *classification*: Windows frequently treats a new router as a
**Public** network, and rules scoped to Private stop applying. The symptom is
distinctive — the server's own browser works perfectly while every other PC and
terminal times out. Set the new network to **Private** in Windows settings, or
re-add the rules for the Public profile.

**6.** Verify:

```
console.bat doctor
```

Two lines carry the answer: **Certificate covers APP_URL** must name the new
address, and **Reachable from the network** must reach it. A cross on the first
means step 4 did not run; a cross on the second, with a tick on the first,
points at the firewall profile in step 5.

### The terminals

Each ESP32 has the address compiled into it, so both need reflashing.

**7.** In each sketch:

```cpp
#define LS_SERVER_URL   "https://192.168.100.7"
```

If the new router also has a different Wi-Fi name or password, set
`LS_WIFI_SSID` and `LS_WIFI_PASS` in the same pass. **Leave `ls_root_ca.h`
alone** — it is still correct, and replacing it with a freshly generated one is
how people accidentally invalidate the root they did not need to touch.

### One thing to check afterwards

A terminal pinned to a specific address will be refused on the new subnet, and
the refusal is logged as `API_KEY_IP_ANOMALY` rather than anything that mentions
the move:

```sql
SELECT device_id, ip_allowlist FROM devices
 WHERE ip_allowlist IS NOT NULL AND ip_allowlist <> '';
```

An empty result means there is nothing to do. Any rows returned must be updated
to the new range or blanked.

Then tap a card in **and** out, and watch the dashboard. A tap that records and
appears live exercises all four moving parts — certificate, allowlist, firewall
and realtime — in one gesture.

---

## Starting fresh instead

Sometimes the old machine is gone, or the data was only ever a trial. Then
there is nothing to preserve and nothing to be careful about:

```
php bin\console install
```

That generates new keys, creates the schema and seeds the reference data. Be
clear-eyed about what it means: **the old database, if you still have one, is
now unreadable**, because the keys that opened it no longer exist. Do not run
this and then try to import an old backup.

Everything is re-entered from scratch — teachers, students, sections,
schedules, cards. And every terminal must be **registered again**, because the
old registrations lived in the old database:

- Devices → **Register terminal**, using the MAC the board prints at boot.
- Download the provisioning file. **That download is the only copy**; every
  fresh download rotates the key and revokes the previous one.
- Paste all four values into the sketch, along with the new `LS_SERVER_URL`,
  and upload.

Device IDs are issued in order from `DEV-{year}-0001`, so a fresh install
hands out `0001` again. **A terminal whose sketch says `DEV-2026-0002` while
the new database only knows `DEV-2026-0001` will be refused** with
`DEVICE_UNKNOWN` — that number is a strong hint that a sketch is still carrying
credentials from the previous installation.

Fingerprints must be enrolled again on the sensor. The templates were
encrypted with the old key.

---

## Quick check afterwards

```
doctor.bat
```

Read three sections:

- **Encryption key** — a cross means `.env` and the database do not match.
- **Database** — a cross on "All migrations applied" means run `migrate`.
- **Terminals** — each one's claim state, and whether its modules answered.

Then open the Serial Monitor on a terminal at 115200 and reset it. It reports
what it found, what it could reach, and what it could not, in that order.
