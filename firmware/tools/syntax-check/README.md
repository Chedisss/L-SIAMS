# Type-checking the terminal sketch without an ESP32

```
./check.sh
```

Prints `OK — no errors, no warnings`, or the compiler's complaint.

## Why this exists

The terminal sketch is around 1,300 lines and touches request signing, a
hand-written UART packet protocol and two hardware libraries. Every mistake in
it costs a walk to the bench, a re-flash and a squint at a serial log.

Most of those mistakes are ones a compiler catches for free: a typo'd
identifier, a wrong argument count, a shadowed variable, a branch that forgets
to return. You do not need the real ESP32 toolchain to find them — you need
headers with the right shapes.

That is all `stub/` is. Minimal stand-ins for `Arduino.h`, `WiFi`,
`HTTPClient`, `SPI`, `MFRC522`, `Adafruit_Fingerprint`, `ArduinoJson`,
`esp_system` and `mbedtls`, with signatures matching the real libraries.
`tu.cpp` defines the globals they declare and then `#include`s the sketch
verbatim, so g++ sees what the Arduino IDE would.

## What it catches

Syntax and type errors, undeclared identifiers, wrong argument counts,
shadowed variables, sign-comparison mistakes, uninitialised reads, missing
returns — under `-Wall -Wextra -Wshadow -Wsign-compare -Wuninitialized
-Wreturn-type -Wparentheses`.

## What it does not catch

**Runtime behaviour.** The AS608 packet code type-checks whether or not the
sensor answers the way the datasheet says. Only the bench proves that.

**Library drift.** The stubs assert what the real signatures *should* be. If
`Adafruit_Fingerprint` changes `fingerSearch`, this keeps passing and the IDE
starts failing. When the two disagree, the IDE is right — fix the stub.

**Anything ESP32-specific.** Memory limits, task watchdogs, brownout
behaviour, actual Wi-Fi. This is a desktop g++ run.

A pass here means "worth flashing", not "works".

## Keeping it honest

The stubs deliberately expose only real library names. `PCD_StopCrypto1()`
exists on `MFRC522`; `PCD_StopCrypto()` does not, and the stub does not offer
it — so calling the wrong one fails here rather than in the IDE. Resist adding
a convenience method to the stub to make an error go away: that is the one
change that turns this from a check into a rubber stamp.
