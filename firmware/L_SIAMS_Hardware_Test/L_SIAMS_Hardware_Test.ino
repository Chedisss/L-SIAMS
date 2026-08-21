/* ===========================================================================
 * L-SIAMS — hardware test
 *
 * Both modules, on one board, with nothing else in the way: no Wi-Fi, no
 * server, no credentials, no request signing. It answers the one question the
 * other diagnostics cannot, because each of them looks at half the board:
 *
 *      with the MFRC522 and the AS608 sharing this ESP32's 3V3 rail,
 *      does each of them still work?
 *
 * That question is worth asking on its own. Both modules work alone far more
 * often than they work together, and the reason is almost never the sketch —
 * it is the rail sagging when the RF field switches on while the sensor is
 * capturing. The symptom is a reader that reports a different version every
 * read, or a sensor that fails its handshake for no reason any log explains.
 * Testing them together is the only way to see it.
 *
 * Flash it, open the Serial Monitor at 115200, tap a card, press a finger.
 * Nothing is sent anywhere and nothing is recorded — a tap here proves the
 * hardware, not the system. For a tap that reaches the server, that is
 * L_SIAMS_RFID_Test; for the real thing, L_SIAMS_Terminal.
 *
 * What it does at boot:
 *
 *   1. The reader — version register sampled repeatedly (a different answer
 *      each time is a loose wire), the chip's own self test, and the antenna
 *      driver, which can be off on a chip that otherwise answers perfectly.
 *
 *   2. The sensor — handshake on the configured pins; if that fails, every
 *      likely pin pair, both ways round, at both baud rates, so a reversed
 *      TX/RX is named rather than reported as a missing sensor. Then its
 *      capacity, its security level, and how many templates it holds.
 *
 *   3. A verdict that reads both results together. Two modules failing at
 *      once on a shared rail is far more often the rail than two dead
 *      modules — and a summary that says so saves the afternoon spent
 *      shopping for replacements.
 *
 * Then it runs: cards are read and printed, fingers are matched against
 * whatever the sensor holds, and these commands work in the Serial Monitor:
 *
 *      help            this list
 *      test            run the boot checks again, without reflashing
 *      count           how many templates the sensor holds
 *      slots           which sensor slots are occupied
 *      enroll [slot]   enrol a finger, into the first free slot if none given
 *      delete <slot>   erase one template
 *      wipe            erase every template, and verify it reached zero
 *
 * Templates enrolled here are stored on the sensor and nothing in L-SIAMS
 * knows about them. Wipe before flashing the terminal, or the server and the
 * sensor disagree about who owns which slot — the Devices page reports the
 * sensor's count, so the mismatch does show up there.
 *
 * Wiring — MFRC522 (SPI):
 *      SDA/SS -> GPIO 5      SCK -> GPIO 18     MOSI -> GPIO 23
 *      MISO   -> GPIO 19     RST -> GPIO 22
 *      3.3V   -> 3V3   (NEVER 5V; 5 V destroys this module)
 *      GND    -> GND         IRQ -> not connected
 *
 * Wiring — AS608 fingerprint (UART2, 57600):
 *      TX  -> GPIO 16  (silkscreen RX2)   sensor transmits, ESP32 receives
 *      RX  -> GPIO 17  (silkscreen TX2)   ESP32 transmits, sensor receives
 *      VCC -> 3V3  (a bare AS608 has NO regulator; 5 V destroys it)
 *      GND -> GND
 *
 *      An R307 is the same sensor in a 5 V housing with a regulator on
 *      board. With one of those, VCC moves to VIN.
 *
 * Both modules share the one 3V3 pin. Join the two power wires to each other
 * and run a single wire to the pin rather than stacking two solder joints on
 * it — the upper joint takes all the strain and cracks, which presents as a
 * module that works on the bench and dies when the board is moved. Fit
 * 100 uF + 100 nF across 3V3 and GND at each module, and power the board from
 * a 1 A wall supply rather than a laptop port. firmware/README.md has the
 * current figures behind that advice.
 *
 * Libraries, by the exact name Library Manager shows:
 *      "MFRC522" by GithubCommunity              (NOT MFRC522v2 - different API)
 *      "Adafruit Fingerprint Sensor Library" by Adafruit
 * Board: ESP32 Dev Module.  Serial Monitor: 115200.
 * ======================================================================== */

#include <SPI.h>
#include <MFRC522.h>
#include <Adafruit_Fingerprint.h>

/* Reader pins. These match the terminal sketch; change them here and the
 * printed advice changes with them. */
#define PIN_RFID_SS        5
#define PIN_RFID_RST       22

/* Sensor pins, named for what lands on them rather than for the silkscreen:
 * the sensor's TX goes to the pin the ESP32 receives on. Wired straight
 * through, both ends talk and neither listens, and it fails silently. */
#define PIN_FINGER_RX      16      /* silkscreen RX2 — sensor TX lands here */
#define PIN_FINGER_TX      17      /* silkscreen TX2 — sensor RX lands here */
#define FINGERPRINT_BAUD   57600

/* Enough samples that an intermittent connection shows up, rather than being
 * missed by one lucky read — which is exactly how that fault hides. */
#define VERSION_SAMPLES    20

#define CARD_DEBOUNCE_MS   2000    /* one card held still is one tap */
#define FINGER_COOLDOWN_MS 1500
#define READER_WATCH_MS    2000
#define ENROLL_TIMEOUT_MS  20000   /* per finger placement */
#define MAX_PROBE_SLOT     200     /* bench sensors never hold more */

MFRC522        rfid(PIN_RFID_SS, PIN_RFID_RST);
HardwareSerial fingerSerial(2);
Adafruit_Fingerprint finger(&fingerSerial);

static bool     readerReady    = false;
static bool     fingerReady    = false;
static String   lastUid;
static uint32_t lastTapAt      = 0;
static uint32_t lastFingerAt   = 0;
static uint32_t lastReaderWatch = 0;
static byte     lastVersion    = 0xEE;   /* neither 0x00 nor a real version */

/* --------------------------------------------------------------- helpers -- */

static String uidToHex(const MFRC522::Uid &uid) {
  String out;
  out.reserve(uid.size * 2);
  for (byte i = 0; i < uid.size; i++) {
    char pair[3];
    snprintf(pair, sizeof(pair), "%02X", uid.uidByte[i]);
    out += pair;
  }
  return out;
}

/** Every value an MFRC522 or a common clone is documented to report. */
static bool isRealVersion(byte v) {
  return v == 0x91 || v == 0x92 || v == 0x88 || v == 0x90 || v == 0x12;
}

/**
 * Sample the version register, and report how many different answers came
 * back. One answer means the link is reliable, whatever the value is.
 */
static uint8_t sampleVersion(byte *distinct, byte *first) {
  uint8_t count = 0;

  for (uint8_t i = 0; i < VERSION_SAMPLES; i++) {
    byte v = rfid.PCD_ReadRegister(MFRC522::VersionReg);

    if (i == 0) *first = v;

    bool seen = false;
    for (uint8_t d = 0; d < count; d++) {
      if (distinct[d] == v) { seen = true; break; }
    }
    if (!seen && count < VERSION_SAMPLES) distinct[count++] = v;

    delay(5);
  }

  return count;
}

/* -------------------------------------------------------- reader bring-up -- */

/**
 * The three reader checks, in the order that makes their results mean
 * something: the link first, because nothing above it can be trusted while
 * the link is unreliable, then the silicon, then the transmitter.
 */
static bool checkReader() {
  SPI.begin();
  rfid.PCD_Init();
  delay(50);

  byte distinct[VERSION_SAMPLES];
  byte first = 0;
  uint8_t count = sampleVersion(distinct, &first);

  Serial.print("  link       version ");
  for (uint8_t d = 0; d < count; d++) {
    Serial.printf("0x%02X%s", distinct[d], d + 1 < count ? " / " : "");
  }

  if (count > 1) {
    Serial.println("   UNSTABLE");
    Serial.println("             Different answers to the same question is a loose");
    Serial.println("             connection. Wiggle MISO (19), then SCK (18), then");
    Serial.println("             3.3V and GND, and watch which one changes this.");
  } else if (isRealVersion(first)) {
    Serial.println("   good");
  } else if (first == 0x00 || first == 0xFF) {
    Serial.println("   NOTHING ANSWERING");
    Serial.println("             Check 3.3V, GND, and SS on GPIO 5.");
  } else {
    Serial.println("   stable, but not a documented version");
    Serial.println("             The link is reliable, so SPI works. Whether the chip");
    Serial.println("             is faulty or an odd clone is what the self test says.");
  }

  Serial.print("  self test  ");
  bool selfTest = rfid.PCD_PerformSelfTest();
  Serial.println(selfTest
    ? "PASSED — the silicon is genuine and working"
    : "FAILED — the chip did not produce its own signature");

  /* PCD_PerformSelfTest leaves the chip reset and idle. Without this, nothing
   * after it works — which would look like a second, separate fault. */
  rfid.PCD_Init();
  delay(50);

  byte tx = rfid.PCD_ReadRegister(MFRC522::TxControlReg);
  if ((tx & 0x03) != 0x03) {
    rfid.PCD_AntennaOn();
    delay(10);
    tx = rfid.PCD_ReadRegister(MFRC522::TxControlReg);
  }

  bool antennaOn = (tx & 0x03) == 0x03;
  Serial.printf("  antenna    TxControlReg 0x%02X   %s\n", tx,
                antennaOn ? "driving the coil"
                          : "OFF — the chip is not accepting writes");

  lastVersion = first;

  /* Deliberately not "the self test passed". Cheap clones exist that fail it
   * and read cards perfectly well, and calling those dead sends somebody
   * shopping for a replacement they do not need. A reader that answers
   * stably and has its transmitter on is worth trying cards on. */
  return count == 1 && first != 0x00 && first != 0xFF && antennaOn;
}

/* -------------------------------------------------------- sensor bring-up -- */

/**
 * Look for the sensor on pins other than the configured ones.
 *
 * The AS608's own labels are written from the sensor's point of view, so a
 * perfectly reasonable straight-through wiring leaves both ends talking and
 * neither listening. Rather than asking which pins were used, try the
 * plausible ones — each pair both ways round, and both baud rates, because
 * 57600 is the default but modules ship configured at 9600 and the symptom
 * is identical.
 *
 * Only runs when the configured pins fail, so a correctly wired board never
 * waits for it.
 */
static bool findFingerprintSensor() {
  struct Pair { uint8_t rx; uint8_t tx; };

  /* Pairs worth trying: free on a typical dev board, not strapping pins, not
   * input-only, and not already used by the reader or the USB serial. */
  static const Pair candidates[] = {
    { PIN_FINGER_TX, PIN_FINGER_RX },   /* configured, but crossed */
    { 16, 17 }, { 17, 16 },
    { 25, 26 }, { 26, 25 },
    { 32, 33 }, { 33, 32 },
    { 27, 14 }, { 14, 27 },
    { 13,  4 }, {  4, 13 },
  };

  static const uint32_t bauds[] = { 57600, 9600 };

  /* Each failing pair costs the library's one-second packet timeout, and
   * there are twenty-odd of them, so this goes quiet for around half a
   * minute. A dot per pair turns what reads as a hang into a progress bar. */
  Serial.println("  not on the configured pins — looking for it...");
  Serial.print("  trying every likely pair at two baud rates: ");

  for (uint8_t b = 0; b < sizeof(bauds) / sizeof(bauds[0]); b++) {
    for (uint8_t i = 0; i < sizeof(candidates) / sizeof(candidates[0]); i++) {
      const Pair &p = candidates[i];

      /* Skip the pair the caller already tried, at its baud rate. */
      if (bauds[b] == FINGERPRINT_BAUD && p.rx == PIN_FINGER_RX && p.tx == PIN_FINGER_TX) {
        continue;
      }

      fingerSerial.end();
      delay(20);
      fingerSerial.begin(bauds[b], SERIAL_8N1, p.rx, p.tx);
      delay(120);

      Serial.print('.');

      if (!finger.verifyPassword()) continue;

      Serial.println();
      Serial.printf("  FOUND on RX %d, TX %d at %lu baud.\n",
                    p.rx, p.tx, (unsigned long) bauds[b]);
      Serial.println();
      Serial.println("  It works from here, but the sketch is configured for something");
      Serial.println("  else. Either rewire to match the terminal — which expects RX 16,");
      Serial.println("  TX 17 at 57600 — or change these two lines in both sketches:");
      Serial.println();
      Serial.printf("      #define PIN_FINGER_RX      %d\n", p.rx);
      Serial.printf("      #define PIN_FINGER_TX      %d\n", p.tx);

      if (bauds[b] != FINGERPRINT_BAUD) {
        Serial.printf("      #define FINGERPRINT_BAUD   %lu\n", (unsigned long) bauds[b]);
      }

      Serial.println();
      return true;
    }
  }

  /* Nothing answered anywhere — put the port back where it was configured, so
   * the failure below describes the state the board is actually in. */
  fingerSerial.end();
  delay(20);
  fingerSerial.begin(FINGERPRINT_BAUD, SERIAL_8N1, PIN_FINGER_RX, PIN_FINGER_TX);
  delay(100);

  Serial.println();
  Serial.println("  no answer on any pin pair tried.");

  return false;
}

/** Handshake, then say what kind of sensor answered and what it is holding. */
static bool checkFingerprint() {
  fingerSerial.begin(FINGERPRINT_BAUD, SERIAL_8N1, PIN_FINGER_RX, PIN_FINGER_TX);
  delay(100);

  /* Configured pins first, always. The search only runs when those do not
   * answer, so a board wired as documented pays nothing for it existing. */
  if (!finger.verifyPassword() && !findFingerprintSensor()) {
    Serial.println("  handshake  NO ANSWER");
    Serial.println("             Three things produce this, in the order they are worth");
    Serial.println("             checking: TX and RX straight through instead of crossed,");
    Serial.println("             a bare AS608 on 5V (which destroys it), and an R307 on");
    Serial.println("             3V3 rather than VIN (which merely will not start).");
    return false;
  }

  Serial.println("  handshake  answered");

  /* getParameters() is what separates "something answered" from "an AS608
   * answered". A capacity of 0, or a packet length the datasheet does not
   * list, means the handshake succeeded against something that is not
   * speaking the protocol properly — a marginal rail, usually. */
  if (finger.getParameters() == FINGERPRINT_OK) {
    Serial.printf("  capacity   %u templates, security level %u\n",
                  finger.capacity, finger.security_level);
  }

  if (finger.getTemplateCount() == FINGERPRINT_OK) {
    Serial.printf("  library    %u template(s) stored\n", finger.templateCount);

    if (finger.templateCount == 0) {
      Serial.println("             Nothing to match against yet. Type `enroll` to put a");
      Serial.println("             finger in, then press it again to see it recognised.");
    }
  }

  return true;
}

/* ------------------------------------------------------------- the checks -- */

static void runChecks() {
  Serial.println();
  Serial.println("MFRC522");
  Serial.println("-------");
  readerReady = checkReader();

  Serial.println();
  Serial.println("AS608");
  Serial.println("-----");
  fingerReady = checkFingerprint();

  Serial.println();
  Serial.println("VERDICT");
  Serial.println("-------");

  if (readerReady && fingerReady) {
    Serial.println("  Both modules answer. Tap a card and press a finger — anything");
    Serial.println("  still broken after this is the network or the server, not the");
    Serial.println("  hardware.");
  } else if (!readerReady && !fingerReady) {
    Serial.println("  NEITHER module answers, and on a shared rail that is far more");
    Serial.println("  often the rail than two dead modules. Before suspecting either:");
    Serial.println("    - power the board from a 1 A wall supply, not a laptop port");
    Serial.println("    - fit 100 uF + 100 nF across 3V3 and GND at each module");
    Serial.println("    - unplug ONE module and re-run `test`. Two unknown modules on");
    Serial.println("      one rail makes it impossible to tell which is at fault, or");
    Serial.println("      whether either is.");
  } else if (!readerReady) {
    Serial.println("  The sensor works and the reader does not, so the rail is carrying");
    Serial.println("  at least one module and the fault is on the reader's side of it.");
    Serial.println("  L_SIAMS_Reader_Check looks at that module on its own.");
  } else {
    Serial.println("  The reader works and the sensor does not, so the rail is carrying");
    Serial.println("  at least one module and the fault is on the sensor's side of it.");
    Serial.println("  Check the TX/RX crossing first — it is the usual answer.");
  }

  Serial.println();
  Serial.println("Waiting. Tap a card, press a finger, or type `help`.");
  Serial.println();
}

/* ---------------------------------------------------------------- reading -- */

/**
 * A reader on a marginal rail stops answering rather than reporting an error,
 * and from the sketch's side that is indistinguishable from nobody tapping.
 * Watching the version register turns a silent reader into a printed line.
 */
static void watchReader() {
  if (millis() - lastReaderWatch < READER_WATCH_MS) return;
  lastReaderWatch = millis();

  byte version = rfid.PCD_ReadRegister(MFRC522::VersionReg);
  if (version == lastVersion) return;
  lastVersion = version;

  if (version == 0x00 || version == 0xFF) {
    Serial.printf("READER: 0x%02X — stopped responding. Suspect the 3.3V rail first.\n",
                  version);
    return;
  }

  Serial.printf("READER: 0x%02X — responding again.\n", version);
  rfid.PCD_Init();
  rfid.PCD_AntennaOn();
}

static void pollCard() {
  if (!rfid.PICC_IsNewCardPresent()) return;
  if (!rfid.PICC_ReadCardSerial()) {
    Serial.println("CARD: answered but would not select — hold it flatter, or suspect");
    Serial.println("      the antenna or the 3.3V supply under load.");
    return;
  }

  String uid = uidToHex(rfid.uid);

  /* A card left resting on the reader is read many times a second. One card
   * held still is one tap, which is what the terminal does too. */
  if (uid == lastUid && millis() - lastTapAt < CARD_DEBOUNCE_MS) {
    rfid.PICC_HaltA();
    rfid.PCD_StopCrypto1();
    return;
  }

  lastUid  = uid;
  lastTapAt = millis();

  MFRC522::PICC_Type type = rfid.PICC_GetType(rfid.uid.sak);

  /* PICC_GetTypeName returns a flash string rather than a char*, so it is
   * printed rather than formatted in. */
  Serial.printf("CARD: %s   (%d bytes, ", uid.c_str(), rfid.uid.size);
  Serial.print(rfid.PICC_GetTypeName(type));
  Serial.println(")");
  Serial.println("      This is the UID L-SIAMS would record. Nothing was sent anywhere.");

  rfid.PICC_HaltA();
  rfid.PCD_StopCrypto1();
}

/* --------------------------------------------------------------- matching -- */

static void pollFinger() {
  if (!fingerReady) return;
  if (millis() - lastFingerAt < FINGER_COOLDOWN_MS) return;

  if (finger.getImage() != FINGERPRINT_OK) return;

  lastFingerAt = millis();

  if (finger.image2Tz() != FINGERPRINT_OK) {
    Serial.println("FINGER: could not read the print — press flatter, and cover more");
    Serial.println("        of the window.");
    return;
  }

  /* fingerFastSearch() sends HighSpeedSearch (0x1B), which plenty of sensors
   * sold as AS608 do not implement. They answer with a packet error rather
   * than a "no match", and the library reports both as failure — so every
   * enrolled finger comes back unknown on a sensor that is working. Falling
   * back to the ordinary search separates the two. */
  uint8_t search = finger.fingerFastSearch();

  if (search != FINGERPRINT_OK && search != FINGERPRINT_NOTFOUND) {
    search = finger.fingerSearch();
    if (search == FINGERPRINT_OK) {
      Serial.println("FINGER: the fast search failed and the ordinary one worked — this");
      Serial.println("        sensor does not implement HighSpeedSearch. Harmless; the");
      Serial.println("        terminal falls back the same way.");
    }
  }

  if (search == FINGERPRINT_OK) {
    Serial.printf("FINGER: matched slot %d (confidence %d)\n",
                  finger.fingerID, finger.confidence);
    Serial.println("        In L-SIAMS this is what opens an attendance session.");
    return;
  }

  if (search == FINGERPRINT_NOTFOUND) {
    Serial.println("FINGER: not recognised — this print is not in the sensor's library.");
    Serial.println("        Type `enroll` to add it.");
    return;
  }

  /* Not "unknown finger": the sensor could not complete the search. Saying
   * so matters, because the obvious response to "unknown" is to enrol the
   * same finger again, and that cannot help. */
  Serial.printf("FINGER: the sensor could not search (code %d). That is a sensor\n", search);
  Serial.println("        fault, not an unknown finger — enrolling again will not help.");
}

/* -------------------------------------------------------------- enrolment -- */

/** Block until a finger is on the sensor, or the step times out. */
static bool waitForFinger(const char *prompt, uint8_t buffer) {
  Serial.printf("  %s\n", prompt);

  uint32_t started = millis();

  while (millis() - started < ENROLL_TIMEOUT_MS) {
    uint8_t result = finger.getImage();

    if (result == FINGERPRINT_NOFINGER) { delay(50); continue; }

    if (result != FINGERPRINT_OK) {
      Serial.printf("  the sensor could not capture an image (code %d)\n", result);
      return false;
    }

    if (finger.image2Tz(buffer) != FINGERPRINT_OK) {
      Serial.println("  that image was not usable — press flatter and try again");
      delay(400);
      continue;
    }

    Serial.println("  captured");
    return true;
  }

  Serial.println("  timed out waiting for a finger");
  return false;
}

static void waitForRemoval() {
  Serial.println("  lift the finger off");
  uint32_t started = millis();

  while (millis() - started < ENROLL_TIMEOUT_MS) {
    if (finger.getImage() == FINGERPRINT_NOFINGER) return;
    delay(50);
  }
}

/**
 * The lowest slot the sensor is not already using.
 *
 * No bulk "list" exists in the AS608/R307 protocol, so each slot is probed by
 * asking the sensor to load it. Capped, to keep this quick.
 */
static int firstFreeSlot() {
  for (uint16_t slot = 1; slot <= MAX_PROBE_SLOT; slot++) {
    if (finger.loadModel(slot) != FINGERPRINT_OK) return slot;
  }
  return -1;
}

static void enrollFinger(int slot) {
  if (slot <= 0) {
    slot = firstFreeSlot();
    if (slot < 0) {
      Serial.println("Every slot probed is occupied. `wipe` clears the sensor.");
      return;
    }
    Serial.printf("Enrolling into slot %d (the first free one).\n", slot);
  } else if (finger.loadModel(slot) == FINGERPRINT_OK) {
    /* Overwriting is allowed, but silently replacing somebody's finger is
     * how a teacher ends up opening sessions as a colleague. */
    Serial.printf("Slot %d is already occupied — it will be overwritten.\n", slot);
  }

  if (!waitForFinger("place the finger on the sensor", 1)) return;

  waitForRemoval();

  if (!waitForFinger("place the SAME finger again", 2)) return;

  if (finger.createModel() != FINGERPRINT_OK) {
    /* Two images that do not agree. Almost always a different finger the
     * second time, or the same finger at a very different angle. */
    Serial.println("  the two scans did not match — use the same finger, placed the");
    Serial.println("  same way, and try again");
    return;
  }

  if (finger.storeModel(slot) != FINGERPRINT_OK) {
    Serial.printf("  the sensor refused to store into slot %d\n", slot);
    return;
  }

  /* Reading the template back is not belt-and-braces. A sensor whose flash
   * has stopped accepting writes acknowledges the store and keeps nothing,
   * and that is indistinguishable from success unless something reads it
   * back — it is what produces a finger enrolled "successfully" that no
   * search will ever find, however many times it is enrolled again. */
  if (finger.loadModel(slot) != FINGERPRINT_OK) {
    Serial.printf("  slot %d reports stored but reads back EMPTY.\n", slot);
    Serial.println("  That is a hardware fault and a conclusive one: the sensor's");
    Serial.println("  flash is not accepting writes. Power-cycle it, `wipe`, and try");
    Serial.println("  once more; if it repeats, the sensor needs replacing.");
    return;
  }

  finger.getTemplateCount();
  Serial.printf("  stored in slot %d — the sensor now holds %d template(s)\n",
                slot, finger.templateCount);
  Serial.println("  Press that finger now and it should come back matched.");
}

/* ---------------------------------------------------------------- console -- */

static void printHelp() {
  Serial.println();
  Serial.println("Commands:");
  Serial.println("  help            this list");
  Serial.println("  test            run the boot checks again");
  Serial.println("  count           how many templates the sensor holds");
  Serial.println("  slots           which sensor slots are occupied");
  Serial.println("  enroll [slot]   enrol a finger, first free slot if none given");
  Serial.println("  delete <slot>   erase one template");
  Serial.println("  wipe            erase every template, and verify it reached zero");
  Serial.println();
}

static void handleSerial() {
  if (!Serial.available()) return;

  /* The default one-second timeout stalls the loop on every keystroke while
   * readStringUntil waits for a newline that a Monitor set to "No line
   * ending" never sends. */
  Serial.setTimeout(60);

  String command = Serial.readStringUntil('\n');
  command.trim();
  command.toLowerCase();

  if (command.length() == 0) return;

  String argument;
  int space = command.indexOf(' ');
  if (space > 0) {
    argument = command.substring(space + 1);
    argument.trim();
    command = command.substring(0, space);
  }

  if (command == "help" || command == "?") { printHelp(); return; }

  if (command == "test") { runChecks(); return; }

  if (!fingerReady) {
    Serial.println("The sensor did not start, so that command has nothing to talk to.");
    Serial.println("Fix the wiring and type `test`.");
    return;
  }

  if (command == "count") {
    finger.getTemplateCount();
    Serial.printf("The sensor holds %d template(s).\n", finger.templateCount);
    return;
  }

  if (command == "slots") {
    Serial.println("Occupied slots:");
    int found = 0;

    for (uint16_t slot = 1; slot <= MAX_PROBE_SLOT; slot++) {
      if (finger.loadModel(slot) == FINGERPRINT_OK) {
        Serial.printf("  slot %d\n", slot);
        found++;
      }
    }

    if (found == 0) Serial.println("  (none)");
    Serial.printf("Total: %d\n", found);
    return;
  }

  if (command == "enroll" || command == "enrol") {
    enrollFinger(argument.length() ? argument.toInt() : 0);
    return;
  }

  if (command == "delete") {
    int slot = argument.toInt();
    if (slot <= 0) {
      Serial.println("Which slot? `delete 3`. `slots` lists the occupied ones.");
      return;
    }

    uint8_t result = finger.deleteModel(slot);
    Serial.println(result == FINGERPRINT_OK
      ? "Deleted."
      : "The sensor refused the delete.");
    return;
  }

  if (command == "wipe") {
    Serial.println("Erasing every template on the sensor...");

    if (finger.emptyDatabase() != FINGERPRINT_OK) {
      Serial.println("The sensor refused the erase. Check power and wiring.");
      return;
    }

    /* Believing the OK is not enough, for the same reason the enrolment reads
     * its template back: flash that has stopped accepting writes acknowledges
     * the command and keeps everything. */
    finger.getTemplateCount();

    if (finger.templateCount == 0) {
      Serial.println("Done — the sensor is empty.");
      return;
    }

    Serial.printf("The sensor accepted the erase and still holds %d template(s).\n",
                  finger.templateCount);
    Serial.println("That is a hardware fault: the flash is not accepting writes.");
    return;
  }

  Serial.printf("Unknown command: %s   (type `help`)\n", command.c_str());
}

/* ------------------------------------------------------------------ main -- */

void setup() {
  Serial.begin(115200);
  delay(600);

  Serial.println();
  Serial.println("L-SIAMS hardware test — MFRC522 + AS608");
  Serial.println("=======================================");
  Serial.println();
  Serial.println("No Wi-Fi, no server, nothing recorded. Expecting:");
  Serial.println("  reader   SS 5   SCK 18   MOSI 23   MISO 19   RST 22   3V3 (never 5V)");
  Serial.println("  sensor   sensor TX -> 16   sensor RX -> 17   57600 baud   3V3");

  runChecks();
  printHelp();
}

void loop() {
  handleSerial();
  watchReader();

  /* The reader is polled even when the boot checks were unhappy with it.
   * Clones exist that fail the self test and read cards perfectly well, and
   * the only way to find that out is to ask. */
  pollCard();
  pollFinger();

  delay(50);
}
