# Firmware

**Flash `L_SIAMS_Bench`.** It is the only sketch here, and it runs a whole
classroom: a teacher's finger opens the attendance session, a student's card
records against it, and the server decides every outcome.

```
firmware/
  L_SIAMS_Bench/
    L_SIAMS_Bench.ino     one file — edit the seven values at the top
  tools/syntax-check/     type-check it without an ESP32
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

## Checking it before you flash

```
tools/syntax-check/check.sh
```

Type-checks the sketch with g++ against stub headers, so a typo or a wrong
argument count is caught at a desk rather than at the bench. It does not need
the ESP32 toolchain and does not prove the sketch works — see the README in
that folder for exactly what it does and does not cover.
