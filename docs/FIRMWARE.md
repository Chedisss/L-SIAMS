# Terminal firmware

The classroom terminal is an ESP32 with an RFID reader and a fingerprint sensor.
Its job is narrow on purpose: **read hardware, report to the server, do what the
server says.** It does not decide whether a tap is an arrival or a departure,
whether a student is late, or whether attendance should be recorded at all.
Those are server decisions, so a terminal with a wrong clock or modified
firmware cannot manufacture a status.

> **Which sketch:** `firmware/L_SIAMS_Bench`. The other directories under
> `firmware/` are diagnostics for when the terminal will not behave, and
> Deleted sketches: the OLED terminal and the three diagnostics are gone as of
> the rewrite; see
> [`firmware/README.md`](../firmware/README.md).

---

## 1. Hardware

### Required

| Component | Part | Notes |
|---|---|---|
| Controller | ESP32-WROOM-32 dev board | any 30-pin DOIT/DEVKIT board, 4 MB flash |
| RFID reader | MFRC522 (13.56 MHz) | SPI, **3.3 V** |
| Fingerprint sensor | AS608 (3.3 V) or R307 (5 V) | UART, 57600 baud |
| Power | 5 V 1 A supply or better | see the power note below |

That is the whole bill of materials. There is no display, buzzer, LED or button
on a working terminal: the Serial Monitor at 115200 is the console and the web
interface is the screen. The optional feedback hardware is described at the end
of this section and is not wired to anything in the shipping firmware.

### Wiring

**MFRC522 → ESP32** (SPI)

| MFRC522 | ESP32 |
|---|---|
| SDA / SS | GPIO 5 |
| SCK | GPIO 18 |
| MOSI | GPIO 23 |
| MISO | GPIO 19 |
| RST | GPIO 22 |
| 3.3V | 3V3 |
| GND | GND |

> The MFRC522 is a **3.3 V** part. Connecting it to 5 V destroys it. This is the
> single most common assembly mistake.

> RST is GPIO 22 because nothing else claims it. If you later add an SSD1306,
> its SCL wants 22 too — move RST to 27 and change `PIN_RFID_RST` to match.

#### Isolating a reader that answers `0x00` and `0xFF`

Alternating `0x00` and `0xFF` means MISO is **floating** — nothing is driving
it — and three things can cause that. Rule them out in this order, because
each step is cheaper than the one after it:

1. **Swap the module.** If a second MFRC522 behaves identically, the module was
   never the fault, and the remaining two are on the board's side.
2. **Move MISO to a different pin.** Change `PIN_RFID_MISO` to a free GPIO —
   21, 25, 26, 27, 32 and 33 are unused by this sketch — and move the wire to
   match. If the reading changes, GPIO 19 is damaged. If it does not, GPIO 19
   is fine and the fault is the wire or its joints.
3. **Replace the wire**, rather than re-seating it. A conductor broken inside
   the sheath still fits snugly and is invisible.

`PIN_RFID_SCK`, `PIN_RFID_MISO` and `PIN_RFID_MOSI` hold the ESP32's own VSPI
defaults, so writing them down changes nothing electrically — it only makes
step 2 possible. Do not use GPIO 34–39 for SCK or MOSI: they are input-only.
Avoid 0, 2, 12 and 15 entirely; they are strapping pins and change how the
board boots.

**Fingerprint sensor → ESP32** (UART2)

| Sensor | ESP32 |
|---|---|
| TX (green) | GPIO 16 — silkscreen RX2 |
| RX (white) | GPIO 17 — silkscreen TX2 |
| VCC (red) | **3V3** for a bare AS608 · **VIN** for an R307 |
| GND (black) | GND |

The sensor's TX goes to the ESP32's RX. Crossing these is the second most common
assembly mistake, and it fails silently — the sensor simply never answers.

> An R307 is an AS608 in a 5 V housing with its own regulator, so it wants VIN.
> A **bare AS608 has no regulator and 5 V destroys it.** If you are holding a
> small board with exposed components rather than a sealed cylinder, it is the
> 3.3 V part.

### Sharing the 3V3 pin

Both modules want 3.3 V and the ESP32 has one 3V3 pin, so they share it. Two
things go wrong there, and both present as a dead module:

**The joint.** Do not stack two solder joints on the same header pin — the upper
one carries all the mechanical strain and cracks, giving a connection that works
on the bench and fails when the board is moved. Join the two module wires to
each other, and run a single wire to the pin:

```
    MFRC522 VCC ──┐
                  ├── one wire ── ESP32 3V3
    AS608   VCC ──┘
             twist, solder, heatshrink
```

A female Dupont on the pin alongside one soldered wire is fine — they are not
competing for the same spot. Check the connector still seats on clean pin rather
than riding on a solder blob.

**The peaks.** Averages are comfortable; the bursts coincide.

| | Idle | Peak |
|---|---|---|
| MFRC522 | ~26 mA | ~100 mA (RF field driving) |
| AS608 | ~50 mA | ~150 mA (capture) |
| ESP32 | ~80–160 mA | ~500 mA (Wi-Fi transmit) |

A dip on that rail produces a brownout reset, an MFRC522 reporting a different
version on every read, and a sensor whose replies arrive corrupted — three
symptoms that each look like a separate fault. Fit **100 µF electrolytic +
100 nF ceramic across 3V3 and GND at each module**, and power the board from a
1 A wall supply rather than a laptop USB port.

### Optional feedback hardware

Not used by the shipping firmware. Wire it only if you are extending the sketch;
the pins are free and these are the assignments the earlier firmware used.

| Function | GPIO | Wiring |
|---|---|---|
| Buzzer | 25 | through a transistor if it draws more than 20 mA |
| Green LED | 26 | via 220 Ω |
| Red LED | 33 | via 220 Ω |
| Blue LED | 32 | via 220 Ω |
| Amber LED | 14 | via 220 Ω |
| Button | 4 | to GND, using the internal pull-up |
| SSD1306 OLED | SDA 21, SCL 22 | I²C at `0x3C` — collides with RST, see above |

---

## 2. Build environment

Arduino IDE 2.x or `arduino-cli`, with the ESP32 board package installed.

Libraries, by the exact name Library Manager shows:

| Library | Purpose |
|---|---|
| **MFRC522** by GithubCommunity | RFID reader — *not* MFRC522v2, different API |
| **Adafruit Fingerprint Sensor Library** by Adafruit | AS608 / R307 |
| **ArduinoJson** by Benoit Blanchon | request and response bodies — 6 or 7 both compile |

`WiFi`, `WiFiClientSecure`, `HTTPClient`, `SPI` and `mbedtls` come with the ESP32
core. HMAC-SHA256 uses `mbedtls/md.h` — the same primitive the server uses, so
there is no bespoke crypto in the firmware.

Board settings:

```
Board:            ESP32 Dev Module
Flash Size:       4MB (32Mb)
Partition Scheme: Default 4MB with spiffs
CPU Frequency:    240MHz
Upload Speed:     921600
```

Serial Monitor: **115200**.

---

## 3. Provisioning

### Step 1 — register the device on the server

Devices → **Register terminal**. Enter the name, MAC address and classroom.

The MAC has to be the board's own. If the terminal cannot join Wi-Fi yet and so
never prints it, read the MAC off the sticker on the board, or watch the
serial log — the terminal prints it as soon as it joins the Wi-Fi, on the line
after the IP. It reads the MAC out of the
radio without connecting to anything.

The response carries the device id, API key, HMAC secret and a single-use claim
token, **displayed exactly once**. Download the provisioning JSON at that moment.
Every fresh download rotates the key and secret, so use one file and do not
download again after you have flashed it.

For a batch, use Devices → **Bulk register** and download the ZIP of one JSON per
terminal. The same rule applies: that download is the only copy.

### Step 2 — put the values into `secrets.h`

Copy `firmware/L_SIAMS_Bench/L_SIAMS_Bench.ino` to `secrets.h` beside it and
edit the seven values at the top. Four come from the provisioning JSON, three
are your network and server:

```cpp
#define LS_WIFI_SSID    "StaffRoom-2G"
#define LS_WIFI_PASS    "your wifi password"
#define LS_SERVER_URL   "http://192.168.1.14:8080"
#define LS_DEVICE_ID    "DEV-2026-0007"
#define LS_API_KEY      "lsk_7Kq2mN4p.Xr9vB2eLd5..."
#define LS_HMAC_SECRET  "a41f...
#define LS_CLAIM_TOKEN  "…"
```

> The shipped values all read `PASTE_SOMETHING`, and that is deliberate. An
> earlier version used realistic examples, and one of them was not an example:
> the server issues `DEV-{year}-0001` to the first terminal registered, so
> `DEV-2026-0001` was simultaneously the placeholder and a real device id. A
> correctly configured board was told its device id was still a placeholder.
> A value nobody would legitimately hold cannot collide.

> **Not into the `.ino`.** `secrets.h` is gitignored; the sketch is tracked, and
> `update.bat` updates by `git reset --hard`, which replaces every tracked file
> with the official version. Credentials typed into the sketch survive until the
> next update and then revert to placeholders without a word — which presents as
> a terminal that worked yesterday and cannot find the Wi-Fi today.
>
> The sketch compiles with or without `secrets.h`; without one it falls back to
> the placeholders and refuses to start, naming the value that is unset.

`SERVER_URL` is the host PC's LAN address **with the port** — never `localhost`,
which to the ESP32 means the ESP32. On the PC:

```powershell
(Get-NetIPConfiguration | Where-Object {$_.IPv4DefaultGateway -ne $null}).IPv4Address.IPAddress
```

The sketch refuses to start with any placeholder still in place, and says which
one is unset rather than failing later in a way that looks like a network fault.
It also compares its own IP against `SERVER_URL` after joining and warns if they
are on different subnets — the commonest reason a terminal joins the Wi-Fi,
reports nothing, and shows as Offline with no error anywhere.

### Step 3 — flash

```bash
arduino-cli compile --fqbn esp32:esp32:esp32 firmware/L_SIAMS_Bench
arduino-cli upload  --fqbn esp32:esp32:esp32 -p /dev/ttyUSB0 firmware/L_SIAMS_Bench
```

Or open the sketch in the IDE and press Upload.

#### When the upload will not connect

```
A fatal error occurred: Failed to connect to ESP32: No serial data received.
```

The sketch compiled — this is the board refusing to enter its bootloader, and
it says nothing about your code. Work down this list; the first two account for
most of it.

1. **Close the Serial Monitor.** It holds the port open, and the uploader
   cannot have it at the same time.

2. **Hold the BOOT button.** Press Upload, wait for `Connecting......`, then
   hold BOOT until it starts writing. Some boards have no auto-reset circuit;
   others have one that a marginal supply defeats.

3. **Try a different USB cable.** Charge-only cables carry power and no data,
   and look identical to the one that works. A board that appears in Device
   Manager is not proof — it can enumerate and still be on a cable with
   degraded data lines.

4. **Unplug both modules and upload with a bare board.** They share the 3.3 V
   rail with the ESP32, and a module dragging that rail down during the reset
   pulse stops the bootloader from starting. If the upload then succeeds, the
   supply is the fault — not the upload.

5. **Check the port.** Tools → Port, and confirm it is the board and not
   another USB serial device.

6. **Lower the upload speed** to 115200 in Tools → Upload Speed. Long or thin
   cables fail at 921600 while working at the slower rate.

If step 4 is what fixes it, treat that as a finding rather than a workaround:
the same sagging rail is what makes the reader report a different version on
every boot, and it will keep doing so after the upload succeeds.

### Step 4 — first boot claims the device

On boot the terminal presents its claim token to `POST /api/device/claim`. The
server activates the device and **consumes the token** — it cannot be presented
again. The Devices page flips from *pending* to *active*, and the terminal is
live.

If claiming fails, the serial log says why. `HTTP -1` means the connection never
opened at all: the server is not running, the firewall is blocking the port, or
the address is wrong — not a credential problem. A token that was already used
or has expired says so explicitly; generate a fresh provisioning file from the
device's detail page and start again from step 2.

### Re-provisioning

Paste new values into the sketch and re-flash. The shipping firmware holds no
credentials in NVS, so there is nothing to wipe — which also means a stolen
terminal gives up its key to anyone who can read its flash. Revoke the device's
API key from the web interface if a terminal goes missing.

---

## 4. State machine

> **This section describes the deleted OLED terminal, not the
> shipping terminal.** The shipping sketch has no display and no explicit state
> machine — it claims, syncs its clock, then polls in one loop. The states below
> are the design the display build was written against, and the vocabulary the
> server still speaks in its responses. Read it as intent, not as what runs.

```
BOOT ─► PROVISION ─► CONNECTING ─► CLAIMING ─► AUTHENTICATING ─► READY
                          ▲                                        │
                          │                                        ▼
                       OFFLINE ◄──────────────────────────► SESSION_OPEN
                                                                   │
                                                    LOCKED ◄───────┘
```

| State | Meaning | Display |
|---|---|---|
| `BOOT` | Hardware initialisation | logo, firmware version |
| `PROVISION` | No credentials in NVS | prompt for the config JSON |
| `CONNECTING` | Joining Wi-Fi | SSID and signal |
| `CLAIMING` | Presenting the claim token | "Activating…" |
| `AUTHENTICATING` | First signed request | "Authenticating…" |
| `READY` | Online, no session open | clock, room, "Awaiting teacher" |
| `ENROLLING` | Capturing a fingerprint for the Fingerprints page | the prompt for each step |
| `SESSION_OPEN` | Accepting card taps | subject, section, live count |
| `OFFLINE` | Network lost, queueing locally | "OFFLINE", queue depth |
| `LOCKED` | Fingerprint lockout | "Locked", countdown |
| `ERROR` | Unrecoverable hardware fault | the fault |

The terminal accepts card taps in `SESSION_OPEN` and in `OFFLINE` (queueing
them). In `READY` a card tap is refused with "No session open" — a tap outside a
session has nothing to belong to.

---

## 4a. Fingerprint enrolment

Enrolment is started in the browser and performed by the terminal. Nobody types
a slot number anywhere.

### Where the scanning happens

A browser cannot read a finger, so the capture always happens at an R307. It
does **not** have to be a classroom terminal.

| | |
|---|---|
| **Enrolment scanner** | An ESP32 and R307 on the administrator's desk, registered under **IoT Devices → Register Device → Enrolment scanner**. Takes no classroom, records no attendance, exists only so the person being enrolled can put their finger down next to the computer the registration is being typed into. This is the normal answer. |
| **Classroom terminal** | Works too, and is what you use to re-enrol somebody who is already in the room. The teacher has to walk to it. |

Both appear in every enrolment picker, scanners first. Same firmware, same
provisioning, same claim — the only difference is the flag set at registration.

There are two entry points. Registering a teacher takes the fingerprint
**before** the record is created — a teacher without one can open no session, so
registering first would produce an account that exists and does nothing. The
Fingerprints page handles the other cases: re-enrolment, a replaced sensor, a
finger that stopped reading.

```
Admin: Teachers → Register Teacher → fill in → Scan fingerprint
  or:  Fingerprints → Enrol Fingerprint → pick teacher + terminal → Start
   │
   ├─ server allocates the next free sensor slot and opens a request
   │
Terminal: GET /api/fingerprint/enrollment          (every 2 s while idle)
   │      → { request_id, sensor_template_id, teacher_name }
   │
   ├─ "Place finger"        → POST …/progress  place_finger
   ├─ "Lift finger"         → POST …/progress  remove_finger
   ├─ "Same finger again"   → POST …/progress  place_again
   ├─ R307 createModel()    → POST …/progress  storing
   ├─ R307 storeModel(slot)
   │
   └─ POST …/complete { request_id, sensor_template_id }
          │
          └─ server records the enrolment; the browser, which has been polling
             throughout, shows each step and then "Enrolled".
```

Three properties are worth stating explicitly, because each of them replaces a
way the old typed-in workflow could go wrong:

**The server allocates the slot, not the device.** Only the server can see which
slots are in use across every terminal in the school. A terminal choosing for
itself would eventually collide with another terminal's numbering.

**The device echoes back the slot it actually wrote,** and the server discards
the enrolment if it differs from the one it asked for. A sensor that relocated
the template would otherwise leave a teacher bound to whatever finger already
occupied the requested slot.

**A refusal from the server deletes the template from the sensor.** Otherwise a
slot would hold a print that nothing on the server owns, and the next enrolment
allocated to that slot would silently match the wrong person.

Still no template on the network: it is built inside the R307 from two images
and stays in the sensor's flash. What crosses the wire is a slot number and a
quality score.

Only one enrolment can be open per terminal at a time, and a request expires
after three minutes without contact — the clock restarts on each step, so it is
the gap between steps rather than a budget for the whole capture.

### Reclaiming an abandoned capture

A print taken during registration exists in the sensor before any teacher does.
If the form is then abandoned — the browser closed, the tab left to time out —
that print sits in the flash occupying a slot nothing owns, and the next person
allocated it would be enrolled straight over the top.

The server cannot reach into the sensor, so it asks:

```
Terminal: GET /api/fingerprint/enrollment
   │      → { "discard_slots": [9], "enrollment": … }
   │
   ├─ finger.deleteModel(9)
   │
   └─ POST …/discarded { "sensor_template_id": 9 }
          │
          └─ only now is slot 9 handed out again
```

The slot stays reserved between the abandonment and the confirmation. Freeing it
on the instruction rather than the confirmation would let the delete land after
the next person had been enrolled into it, wiping the print just taken. A slot
that is already empty when the delete runs is the expected outcome of a lost
confirmation, not an error, so the terminal reports success either way.

Captures wait 30 minutes for their form to be finished
(`attendance.fingerprint.enrollment_hold_seconds`) before they are reclaimed —
longer than the capture timeout, because the person is typing a department in
rather than standing at a sensor.

---

## 5. What the terminal does, and does not do

### It does

- Read a card UID and post it, with a UUIDv4 `request_id` and its own timestamp.
- Read a fingerprint and post the sensor slot number.
- Capture a *new* fingerprint when the Fingerprints page asks it to, write the
  template to the slot the server allocated, and report back which slot it
  actually used.
- Sign every request with HMAC-SHA256 over the canonical string.
- Hold taps that could not be sent, and replay them oldest-first with their
  original timestamps and request ids, so a card presented at 08:05 is recorded
  at 08:05 and not at whatever time the network came back.
- Heartbeat every 30 seconds, with jitter so a hall full of terminals does not
  synchronise into a thundering herd.
- Correct its clock from the server every six hours.
- Display exactly the three lines the server returns.

### It does not

- Decide whether a tap is a time-in or a time-out.
- Decide whether a student is present, late or absent.
- Hold any student data. There is no roster on the device — a stolen terminal
  yields no personal information.
- Hold any fingerprint template. Templates live in the R307's own flash; the
  server stores only a slot number.
- Trust its own clock for a live tap. The server timestamps live taps itself.

That last point is the reason a drifting RTC is a nuisance rather than a data
integrity problem. The terminal's timestamp is honoured **only** when replaying a
queued tap, where it is the only record of when the tap actually happened.

---

## 6. Offline behaviour

Only a tap the server never answered is held. An HTTP refusal — no session
open, unknown card — is **not** queued: the server considered that tap and said
no, for a reason that will not change by asking again, and retrying it would
turn a clear answer into a silent loop that still ends in nothing recorded.

**The shipping terminal does this.** A tap the server never answered is held on
the terminal and sent later with the time it actually happened. The numbers
below describe the OLED terminal that was deleted; the shipping sketch holds
**40** taps in RTC memory rather than 500 in NVS, and differs where noted.

Each held entry carries the card UID, the timestamp at the moment of the tap,
and a `request_id` generated *then* — not at replay time.

That detail is what makes replay safe. If a sync succeeds but the response is
lost, the terminal retries with the same `request_id`, the server recognises it,
and the original response is replayed instead of recording the tap twice.

- Queue limit: **40 entries** in the shipping sketch (`TAP_QUEUE_MAX`), held in
  RTC memory. That survives a reset or a watchdog reboot but **not a power cut** —
  the honest limit of it is forty taps through a network outage, not through a
  mains failure.
- Depth is reported on every heartbeat, so the server raises its queue warning
  from real data.
- At the limit the shipping sketch **refuses the new tap** and says so, rather
  than dropping the oldest. Both lose a tap and there is no version of a full
  queue that does not, but the entries already held are facts that have been
  captured; overwriting them to make room for one that has not been confirmed
  destroys known data to store unknown data. The OLED terminal below did the
  opposite — at the limit, the **oldest** entry is dropped and logged. Losing the oldest tap
  is the least-bad option; refusing new taps would lose the ones still arriving.

On reconnection the terminal syncs in batches of 25, oldest first, and returns to
`READY` or `SESSION_OPEN` once the queue drains.

---

## 7. Feedback

> **The shipping terminal has no LEDs, buzzer or display.** It prints the
> server's decision to the Serial Monitor instead, and the web interface is
> where anyone actually watches attendance arrive. The server sends these
> fields on every response regardless, so wiring the hardware later is a
> firmware change only — nothing on the server has to know.

| Event | LED | Buzzer | Display |
|---|---|---|---|
| Time in — present | green | one short beep | `TIME IN — PRESENT` |
| Time in — late | amber | two short beeps | `TIME IN — LATE` |
| Time out | blue | one short beep | `TIME OUT` |
| Time out — early | amber | two short beeps | `TIME OUT — EARLY` |
| Rejected | red | one long beep | the server's reason |
| Unknown card | red | one long beep | `CARD NOT REGISTERED` |
| Session opened | green | ascending pair | subject and section |
| Offline queued | blue | one short beep | `SAVED — OFFLINE` |

Feedback is driven entirely by the `feedback` and `display_line_*` fields in the
server's response. Adding a status on the server requires no firmware change.

---

## 8. Security notes

**TLS with a pinned fingerprint.** The provisioning file carries the server
certificate's fingerprint, and the terminal validates against it. A device that
skipped validation would accept any host on the LAN claiming to be the server —
which is exactly the attack a school network makes easy.

**Credentials live in NVS, not in the sketch.** The compiled binary is identical
across every terminal and contains no secrets. Someone who dumps the flash gets
that one device's credentials and nothing else; keys are independent, so one
compromised terminal reveals nothing about any other.

**Set `DEBUG_LOGGING` to 0 before deployment.** Serial output is invaluable
during installation and an information leak afterwards. Change it in `config.h`
and re-flash once the terminal is installed and the enclosure is closed.

**Mount the terminal so the USB port is inaccessible.** Serial access is
equivalent to physical possession of the credentials.

**Fingerprint lockout mirrors the server.** Five failures locks the sensor for
five minutes locally, so a locked-out person cannot hammer the server endpoint.

---

## 9. Troubleshooting

### Read the serial monitor first

Open the Arduino IDE's Serial Monitor at **115200 baud** and press the board's
EN/RST button. Everything below is diagnosed from what it prints, and two lines
in particular answer most of the "the device does nothing" reports:

**`HALTED: <reason>`, repeating every ten seconds.** Startup gave up, and the
terminal will not read a card or a finger until the named problem is fixed.
This is the important one. A `return` from `setup()` does not stop an Arduino
sketch — `loop()` runs immediately afterwards regardless — so a board that had
stopped for a good reason used to sit there polling endpoints that refused
every request, discarding every refusal silently. It looked alive and did
nothing, and the single line explaining why had scrolled off the top of the
window long before anyone looked. The reason is now repeated until it is dealt
with. Fix what it names, then press EN/RST.

**`Server: POST /api/… -> HTTP 401 …`.** A request was refused, with the
server's own reason on the next line. Refusals are reported once, then at most
once every thirty seconds while the condition lasts, and once more when the
server starts answering again — so a working terminal stays quiet without a
broken one being able to fail invisibly.

One 401 is normal and is deliberately *not* reported: the very first request of
every boot is signed with a 1970 timestamp, because the board has no clock
until the server gives it one. The server refuses that request with
`TIMESTAMP_EXPIRED` and attaches its own epoch so the board can set itself and
retry. That exchange is how startup is supposed to go, and reporting it would
send you looking for an authentication fault that does not exist.

### Symptom table

| Symptom | Cause |
|---|---|
| `Failed to connect to ESP32: No serial data received` | An upload problem, not a code problem — the sketch already compiled. See [When the upload will not connect](#when-the-upload-will-not-connect). Close the Serial Monitor first, then hold BOOT. |
| A module works at boot on one run and not the next | Intermittent, not broken. Both modules share the 3.3 V rail and the ground, so a module that comes and goes — or a reader answering a different version each boot — is a supply or a joint. Use a 1 A wall supply, fix the shared joints, fit 100 µF + 100 nF at each module. |
| Nothing at all happens; `HALTED:` repeats | Startup stopped for the reason printed beside it. Nothing works until that is fixed, and it needs a person — the board will not recover on its own. |
| `PAUSED:` repeats instead of `HALTED:` | A fault that can clear itself, currently only "no clock yet". The terminal retries every 30 seconds and prints `Recovered:` when the server answers. No action needed unless it persists. |
| `failed to send the request headers` (`-2`) on every attempt | `LS_SERVER_URL` is missing its `http://`. `HTTPClient` splits a URL at the first colon to find the protocol, so `192.168.1.193:8080` makes `192.168.1.193` the scheme and the request is malformed before it leaves. The terminal now completes a bare `host:port` and prints that it did — write it in full anyway. |
| Worked yesterday, not today | The PC's IP is a DHCP lease and moves when the router restarts. Run `ipconfig` on the PC, compare against `LS_SERVER_URL`, and reserve the address in the router so it stops moving. If another device has since been given the old address, the board will connect to *it* and fail in confusing ways rather than failing cleanly. |
| A negative number where an HTTP status belongs | Not an HTTP status. `HTTPClient` reports its own failures as negative values, and the terminal now names each one. The important split: **`-1` never got a connection** — `start.bat` not running (it serves the site, *not* XAMPP's Apache), a wrong address, or a wrong port; **`-11` connected and sent, and the reply never came** — the address and port are proven right by the connection succeeding, so look at the Wi-Fi link, not at `LS_SERVER_URL`. |
| The teacher's scan says `SESSION OPEN — ? with ?, roster 0` | Firmware older than the fix in this section: the session really did open and is in the database, but the sketch read the response one level too shallow. Run `update.bat` and re-flash. |
| `SIGNATURE_INVALID` on every request | Clock drift. Check against `GET /api/device/time`. The window is ±30 s. |
| `DEVICE_UNCLAIMED` | Never completed first boot. Re-provision with a fresh file. |
| Claim fails with `CLAIM_TOKEN_INVALID` | The token was already used. Generate a new provisioning file. |
| Cards never read | MFRC522 on 5 V (destroyed), or SPI wiring. Confirm 3.3 V. |
| Fingerprint never responds | TX/RX swapped, or the baud rate is not 57600. |
| Enrolment never starts on the terminal | The terminal only polls in `READY`. A session open on it, or a local lockout, will hold it back until that clears. |
| Enrolment says "the two scans did not match" | A different finger the second time, or the same finger at a very different angle. Start again and place it the same way twice. |
| Display blank | I²C address — some modules are `0x3D`, not `0x3C`. |
| Random resets during scans | Underpowered supply. Use 5 V 2 A and add the 470 µF capacitor. |
| Stuck `OFFLINE` with a good network | TLS failure — usually the certificate fingerprint changed after the server certificate was renewed. Re-provision. |
| Queue grows and never drains | The server is reachable but rejecting. Read the device log on its detail page. |
