# Firmware

**Flash `L_SIAMS_Terminal`.** It is the one to start with: 640 lines that join
the Wi-Fi, register the board with the server, read a teacher's fingerprint to
open the attendance session, and send every student card tap to the database.

```
firmware/
  L_SIAMS_Terminal/
    L_SIAMS_Terminal.ino  the simple build — start here
  L_SIAMS_Bench/
    L_SIAMS_Bench.ino     adds enrolment from the web UI (see below)
  tools/syntax-check/     type-check either without an ESP32
```

Both sketches edit the same seven values at the top, and both take the same
provisioning file. Flashing one over the other needs no change on the server.

## Which sketch

| | `L_SIAMS_Terminal` | `L_SIAMS_Bench` |
|---|---|---|
| Lines | 640 | 1,532 |
| Wi-Fi, claim, heartbeat | yes | yes |
| Fingerprint opens a session | yes | yes |
| RFID taps to the database | yes | yes |
| Enrol a fingerprint from the Devices page | no | yes |
| Issue an RFID card from the web UI | no | yes |
| Push a teacher's template to other terminals | no | yes |

Enrolment is the only thing the simple sketch gives up. If you need to enrol a
teacher's finger or issue a card from the browser, flash `L_SIAMS_Bench` onto
one board for that job — every claimed terminal can be used for enrolment, so
it does not have to be the one in the classroom.

## Status, at a glance

`L_SIAMS_Terminal` prints one line per thing it does, so the Serial Monitor
says what state the terminal is in rather than scrolling:

```
MAC:    3C:61:05:0A:1B:2C
        ^ register THIS terminal with THIS MAC
Device: DEV-2026-0002
Server: http://192.168.1.14:8080

[rfid ] ready (version 0x92)
[finger] ready
[wifi ] joining "StaffRoom-2G" ....
[wifi ] connected, this board is 192.168.1.31
[clock] set from the server
[claim] ACCEPTED - this terminal is now active

=====================================================
READY - connected and claimed
=====================================================

[beat ] ok - no session open
[finger] slot 3, confidence 164
         SESSION OPEN - Maria Reyes
[card ] 04A7C1935D
         TIME_IN_RECORDED - Time in recorded for Garcia, Lorraine.
```

When something is wrong it stops, says why, and repeats the reason every 15
seconds — rather than carrying on and burying it:

```
=====================================================
STOPPED: could not reach the server at http://192.168.1.14:8080
=====================================================
Fix the above, then press the RESET button on the board.
```

---

## The terminal

One ESP32 running the whole loop. A teacher's finger opens the attendance
session, a student's card records against it, and the server decides every
outcome — the terminal never judges whether a tap is an arrival, a departure or
a late arrival.

### What you need

| Part | Notes |
|---|---|
| ESP32-WROOM-32 dev board | any 30-pin DOIT/DEVKIT board |
| MFRC522 | 13.56 MHz, SPI, **3.3 V** |
| AS608 or R307 fingerprint sensor | UART, 57600 baud |
| 5 V 1 A supply or better | a laptop USB port is the usual cause of resets |

No display, no buzzer, no LEDs, no button. The Serial Monitor at 115200 is the
console and the web interface is the screen.

### Wiring

Both modules share the ESP32's single 3V3 pin and its ground. Join the two
power wires to each other and run one wire to the pin — do not stack two solder
joints on the same pin, because the upper one takes all the strain and cracks.
Fit **100 µF + 100 nF across 3V3 and GND at each module**; the sketch header
explains why with the current figures.

| MFRC522 | ESP32 | | Fingerprint | ESP32 |
|---|---|---|---|---|
| SDA / SS | GPIO 5 | | TX | GPIO 16 (RX2) |
| SCK | GPIO 18 | | RX | GPIO 17 (TX2) |
| MOSI | GPIO 23 | | VCC | 3V3 for a bare AS608, **VIN for an R307** |
| MISO | GPIO 19 | | GND | GND |
| RST | GPIO 22 | | | |
| 3.3V | 3V3 | | | |
| GND | GND | | | |

> The sensor's TX goes to the pin the ESP32 *receives* on. Wired straight
> through, both talk and neither listens — and it fails silently.

> 5 V on a bare AS608 destroys it. An R307 is the same sensor in a 5 V housing
> with its own regulator, and that one wants VIN.

### Libraries

By the exact name Library Manager shows:

- **MFRC522** by GithubCommunity — *not* MFRC522v2, which has a different API
- **Adafruit Fingerprint Sensor Library** by Adafruit
- **ArduinoJson** by Benoit Blanchon — 6 or 7, both compile

Board: **ESP32 Dev Module**. Serial Monitor: **115200**.

### Finding the board's MAC

Registering the terminal asks for it. Flash the sketch as it is — placeholders
and all — and open the Serial Monitor at 115200. The MAC is the second line,
printed before the sketch checks anything:

```
L-SIAMS classroom terminal
MAC:   A0:B7:65:12:34:56
       Register this terminal with that MAC on the Devices page.
```

It comes off the radio, so it needs no Wi-Fi, no credentials and no wiring.

### Before flashing

Open `L_SIAMS_Bench.ino` and edit the seven values at the top — your Wi-Fi name
and password, the server's LAN address with its port, and the four from the
provisioning JSON you downloaded at **Devices → Register Device**. The block
says where each one comes from.

`LS_SERVER_URL` is the one that catches people. It must be the PC's LAN
address, the one `start.bat` prints, like `http://192.168.1.14:8080`. Never
`localhost` — to the ESP32 that means the ESP32, so the request never leaves
the board.

The sketch refuses to start with placeholders still in place and names the one
that is unset, rather than failing later in a way that looks like a network
fault.

> **`update.bat` replaces this file.** It is tracked, so an update overwrites
> your seven values with the placeholders. `update.bat` now saves a copy first,
> as `L_SIAMS_Bench.ino.your-copy`, and tells you — but you still have to paste
> them back.
>
> To stop that happening at all, put the same seven `#define` lines in a file
> called `secrets.h` beside the sketch. It is gitignored, so updates leave it
> alone, and anything in it wins over the block in the sketch. Optional, and
> worth doing once you stop changing them.

### Serial console

Type these into the Serial Monitor while it runs:

| Command | Does |
|---|---|
| `count` | how many templates the sensor is holding |
| `slots` | which sensor slots are occupied |
| `wipe` | erase every template on the sensor, and verify it reached zero |

`wipe` erases the sensor but not the server's records of who owns which slot.
After a wipe, delete the fingerprints in the web interface too, or the two
disagree — the heartbeat reports the sensor's count so the mismatch is visible
on the Devices page.

---

## Running more than one terminal

The server runs as many terminals as you register. Two boards fail to come up
together for one of three reasons, and each says so plainly:

**Each board needs its own registration.** One provisioning file belongs to one
board. Flashing the same `LS_DEVICE_ID`, `LS_API_KEY` and `LS_CLAIM_TOKEN` onto
a second board makes the server refuse it with `CLAIM_IDENTITY_MISMATCH`,
because the MAC it presents is not the one that device id was registered with.
Register the second board separately, download its own provisioning file, and
paste those values.

**Register it with the MAC the board prints.** The claim is refused unless the
board's MAC matches the registration exactly — this is the usual reason a
second terminal stays Offline forever. The sketch prints its MAC at boot,
before anything can stop it:

```
MAC:    3C:61:05:0A:1B:2C
        ^ register THIS terminal with THIS MAC
```

The provisioning file also carries `mac_address`, so you can compare the two.
If they differ, edit the device on the Devices page and correct it there — the
board cannot change the MAC it has.

**Two terminals cannot share a classroom.** A room has one register, so the
server allows one terminal per classroom and role. Registering a second board
into a room that already has one is refused at registration with
`This classroom already has an active terminal`. Put it in a different
classroom, or set one board to `entry` and the other to `exit` if you genuinely
want two readers in one room.

Once each board has its own registration with its own MAC, both claim, both
heartbeat, and both show Online — that is tested, not assumed.

## Checking it before you flash

```
tools/syntax-check/check.sh
```

Type-checks the sketch with g++ against stub headers, so a typo or a wrong
argument count is caught at a desk rather than at the bench. It does not need
the ESP32 toolchain and does not prove the sketch works — see the README in
that folder for exactly what it does and does not cover.
