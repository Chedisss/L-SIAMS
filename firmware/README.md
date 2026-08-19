# Firmware

**Flash `L_SIAMS_Terminal`.** That is the classroom terminal, and it is the only
sketch here that talks to the running system. Everything else in this directory
is a diagnostic you reach for when the terminal will not behave.

```
firmware/
  L_SIAMS_Terminal/     ← flash this one
  L_SIAMS_Reader_Check/   is the RFID reader alive?      no Wi-Fi, no server
  L_SIAMS_WhoAmI/         what is my MAC? what Wi-Fi?    no wiring at all
  L_SIAMS_RFID_Test/      does a tap reach the server?   signing only
  reference/              not for flashing — see below
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

### Before flashing

Set the four values at the top of the sketch: your Wi-Fi SSID and password, the
server URL, and the provisioning token from **Devices → Register terminal** in
the web interface. The sketch refuses to start with the placeholders still in
place and says which one is unset.

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

## The diagnostics

Reach for these in this order when something will not work. Each answers one
question and needs less than the one before it.

**`L_SIAMS_WhoAmI`** — no wiring, no credentials. Prints the board's MAC and
lists the Wi-Fi networks it can see. Use it when the terminal will not join the
network, or when you need the MAC to register the device and the terminal never
gets far enough to print it.

**`L_SIAMS_Reader_Check`** — no Wi-Fi, no server. Answers "can this MFRC522 read
a card at all?" Samples the version register twenty times and runs the chip's
own self test. A different value each time is a loose connection; the same wrong
value every time is a dead module.

**`L_SIAMS_RFID_Test`** — Wi-Fi and server, no fingerprint sensor. Proves a tap
reaches the server, authenticates, and comes back with a decision. When this
works, request signing is proven and anything still broken is elsewhere.

---

## `reference/`

**Nothing here is meant to be flashed.**

`L_SIAMS_Terminal_OLED` was the earlier terminal firmware, written against an
older server API. It is kept for three things it implements that the shipping
terminal does not: the offline attendance queue in NVS, the SSD1306 status
display, and an explicit boot state machine.

The queue is the real gap. The shipping terminal refuses a tap while the network
is down rather than recording it for later, which is a fair trade on a stable
LAN and not one to make in a school on unattended hardware. When you close that
gap, read this file first.

Do not flash it as it stands: it cannot issue student cards, it needs an SSD1306
to come up at all, it assumes a 5 V sensor, and none of the fixes found during
bench testing are in it.
