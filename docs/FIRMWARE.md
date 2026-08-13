# Terminal firmware

The classroom terminal is an ESP32 with an RFID reader, a fingerprint sensor and
a small display. Its job is narrow on purpose: **read hardware, report to the
server, display what the server says.** It does not decide whether a tap is an
arrival or a departure, whether a student is late, or whether attendance should
be recorded at all. Those are server decisions, so a terminal with a wrong clock
or modified firmware cannot manufacture a status.

---

## 1. Hardware

| Component | Part | Notes |
|---|---|---|
| Controller | ESP32-WROOM-32 dev board | 4 MB flash minimum |
| RFID reader | MFRC522 (13.56 MHz) | SPI |
| Fingerprint sensor | R307 / AS608 | UART, 57600 baud |
| Display | SSD1306 OLED 128×64 | I²C, address `0x3C` |
| Feedback | Passive buzzer, 4 LEDs | green / red / blue / amber |
| Input | Momentary push button | 3-second hold for the maintenance menu |
| Power | 5 V 2 A supply | see the power note below |

### Wiring

**MFRC522 → ESP32** (SPI)

| MFRC522 | ESP32 |
|---|---|
| SDA / SS | GPIO 5 |
| SCK | GPIO 18 |
| MOSI | GPIO 23 |
| MISO | GPIO 19 |
| RST | GPIO 27 |
| 3.3V | 3V3 |
| GND | GND |

> The MFRC522 is a **3.3 V** part. Connecting it to 5 V destroys it. This is the
> single most common assembly mistake.

**R307 fingerprint sensor → ESP32** (UART2)

| R307 | ESP32 |
|---|---|
| TX (green) | GPIO 16 |
| RX (white) | GPIO 17 |
| VCC (red) | 5 V |
| GND (black) | GND |

The sensor's TX goes to the ESP32's RX. Crossing these is the second most common
assembly mistake, and it fails silently — the sensor simply never answers.

**SSD1306 OLED → ESP32** (I²C)

| OLED | ESP32 |
|---|---|
| SDA | GPIO 21 |
| SCL | GPIO 22 |
| VCC | 3.3 V |
| GND | GND |

**Feedback**

| Function | GPIO | Wiring |
|---|---|---|
| Buzzer | 25 | through a transistor if the buzzer draws more than 20 mA |
| Green LED | 26 | via 220 Ω |
| Red LED | 33 | via 220 Ω |
| Blue LED | 32 | via 220 Ω |
| Amber LED | 14 | via 220 Ω |
| Button | 4 | to GND, using the internal pull-up |

### Power

Use a 5 V 2 A supply, not a phone charger of unknown provenance. The fingerprint
sensor draws a burst of current while its illumination is on, and an underpowered
supply produces a brownout reset in the middle of a scan — which presents as an
intermittent, maddening fault that looks like a firmware bug.

Put a 470 µF electrolytic across the 5 V rail near the sensor.

---

## 2. Build environment

Arduino IDE 2.x or `arduino-cli`, with the ESP32 board package installed.

Libraries:

| Library | Purpose |
|---|---|
| `ArduinoJson` (v7) | Request and response bodies |
| `MFRC522` | RFID reader |
| `Adafruit Fingerprint Sensor Library` | R307 |
| `Adafruit SSD1306` + `Adafruit GFX` | Display |

`WiFi`, `WiFiClientSecure`, `HTTPClient`, `Preferences`, `SPI`, `Wire` and
`mbedtls` come with the ESP32 core. HMAC-SHA256 uses `mbedtls/md.h` — the same
primitive the server uses, so there is no bespoke crypto in the firmware.

Board settings:

```
Board:            ESP32 Dev Module
Flash Size:       4MB (32Mb)
Partition Scheme: Default 4MB with spiffs
CPU Frequency:    240MHz
Upload Speed:     921600
```

The default partition scheme matters — the offline queue lives in NVS, and a
partition layout without room for it silently truncates the queue.

---

## 3. Provisioning

A terminal is provisioned once, over USB serial, before it is installed.

### Step 1 — register the device on the server

Devices → **Register terminal**. Enter the name, MAC address and classroom. The
response carries the API key, the HMAC secret and a single-use claim token,
**displayed exactly once**. Download the provisioning JSON at that moment.

For a batch, use Devices → **Bulk register** and download the ZIP of one JSON per
terminal. The same rule applies: that download is the only copy.

### Step 2 — flash the firmware

```bash
arduino-cli compile --fqbn esp32:esp32:esp32 firmware/L_SIAMS_Terminal
arduino-cli upload  --fqbn esp32:esp32:esp32 -p /dev/ttyUSB0 firmware/L_SIAMS_Terminal
```

The firmware ships with no credentials in it. The same binary goes on every
terminal; identity arrives in the next step.

### Step 3 — paste the provisioning JSON

Open the serial monitor at **115200 baud**. An unprovisioned terminal shows
`PROVISION — Paste config JSON over serial` and waits.

Paste the JSON contents, then press Enter:

```json
{
  "schema_version": "1.0",
  "device_id": "DEV-2026-0001",
  "device_name": "Room 204 Terminal",
  "classroom_name": "Room 204",
  "device_role": "both",
  "api_key": "…",
  "hmac_secret": "…",
  "claim_token": "…",
  "server_url": "https://192.168.1.10",
  "server_fingerprint": "…",
  "heartbeat_interval_sec": 30,
  "sync_interval_sec": 60,
  "offline_queue_limit": 500
}
```

It then prompts for the Wi-Fi SSID and password, writes everything to NVS, and
restarts.

### Step 4 — first boot claims the device

On restart the terminal connects to Wi-Fi and presents its claim token to
`POST /api/device/claim`. The server activates the device and **consumes the
token** — it cannot be presented again. The Devices page flips from *pending* to
*claimed*, and the terminal is live.

If claiming fails, the token was already used or has expired. Generate a fresh
provisioning file from the device's detail page and start again from step 3.

### Re-provisioning

Hold the button for 3 seconds to reach the maintenance menu, which offers a
credential wipe. Wiping clears NVS and returns the terminal to `PROVISION`. It
will need a fresh provisioning file — the old credentials cannot be recovered,
which is the point.

---

## 4. State machine

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
- Queue taps to NVS when the network is down, and replay them oldest-first with
  their original timestamps and request ids.
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

When a request fails, the terminal enters `OFFLINE` and queues taps in NVS. Each
queued entry carries the card UID, the timestamp at the moment of the tap, and a
`request_id` generated *then* — not at replay time.

That detail is what makes replay safe. If a sync succeeds but the response is
lost, the terminal retries with the same `request_id`, the server recognises it,
and the original response is replayed instead of recording the tap twice.

- Queue limit: 500 entries by default, configurable per device.
- Warning at 100 entries — the display shows the depth, and the server raises a
  notification from the heartbeat.
- At the limit, the **oldest** entry is dropped and logged. Losing the oldest tap
  is the least-bad option; refusing new taps would lose the ones still arriving.

On reconnection the terminal syncs in batches of 25, oldest first, and returns to
`READY` or `SESSION_OPEN` once the queue drains.

---

## 7. Feedback

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

| Symptom | Cause |
|---|---|
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
