/* ===========================================================================
 * L-SIAMS — reader check
 *
 * Answers one question: can this MFRC522 read a card? No Wi-Fi, no server, no
 * credentials, no fingerprint sensor. Flash it, open the Serial Monitor at
 * 115200, and read.
 *
 * Four separate checks, because they fail independently:
 *
 *   1. The version register, sampled twenty times. A real value means the SPI
 *      link works. A DIFFERENT value each time means a loose connection. The
 *      same wrong value every time means the link is fine and the chip is not
 *      what it claims to be.
 *
 *   2. The chip's own self test. It runs a known input through the internal
 *      CRC engine and compares the result against the signature NXP burned
 *      into the part. Passing it is proof the silicon works; failing it while
 *      SPI is stable is proof the module is faulty. This is the check that
 *      settles "dead or just unusual".
 *
 *   3. The antenna driver. A chip that answers perfectly with its transmitter
 *      switched off will never see a card, and nothing else here would say so.
 *
 *   4. An actual card — attempted regardless of what the version says. Cheap
 *      clones exist that report a version no datasheet lists and read cards
 *      perfectly well, and refusing to try would send somebody shopping for a
 *      replacement they did not need.
 *
 * Wiring it expects:
 *      SDA/SS -> GPIO 5      SCK -> GPIO 18     MOSI -> GPIO 23
 *      MISO   -> GPIO 19     RST -> GPIO 22
 *      3.3V   -> 3V3   (NEVER 5V)      GND -> GND
 *      IRQ    -> not connected
 *
 * Library: "MFRC522" by GithubCommunity (NOT MFRC522v2).
 * Board: ESP32 Dev Module.  Serial Monitor: 115200.
 * ======================================================================== */

#include <SPI.h>
#include <MFRC522.h>

#define PIN_SS   5
#define PIN_RST  22

/* Enough samples that an intermittent connection shows up, rather than being
 * missed by one lucky read — which is exactly how that fault hides. */
#define SAMPLES  20

MFRC522 rfid(PIN_SS, PIN_RST);

static uint32_t round_ = 0;

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

  for (uint8_t i = 0; i < SAMPLES; i++) {
    byte v = rfid.PCD_ReadRegister(MFRC522::VersionReg);

    if (i == 0) *first = v;

    bool seen = false;
    for (uint8_t d = 0; d < count; d++) {
      if (distinct[d] == v) { seen = true; break; }
    }
    if (!seen && count < SAMPLES) distinct[count++] = v;

    delay(5);
  }

  return count;
}

/** Try a card. Asks WUPA as well as REQA so one already resting is found. */
static void tryCard() {
  bool present = rfid.PICC_IsNewCardPresent();

  if (!present) {
    /* PICC_IsNewCardPresent resets these three before asking; PICC_WakeupA
     * does not, and inherits whatever the last transaction left behind. */
    rfid.PCD_WriteRegister(MFRC522::TxModeReg, 0x00);
    rfid.PCD_WriteRegister(MFRC522::RxModeReg, 0x00);
    rfid.PCD_WriteRegister(MFRC522::ModWidthReg, 0x26);

    byte atqa[2];
    byte len = sizeof(atqa);
    MFRC522::StatusCode s = rfid.PICC_WakeupA(atqa, &len);
    present = (s == MFRC522::STATUS_OK || s == MFRC522::STATUS_COLLISION);
  }

  if (!present) {
    Serial.println("      card: nothing in the field — hold one flat on the reader");
    return;
  }

  if (!rfid.PICC_ReadCardSerial()) {
    Serial.println("      card: ANSWERED but would not select — hold it flatter, or");
    Serial.println("            suspect the antenna or the 3.3V supply under load");
    return;
  }

  Serial.print("      card: ");
  for (byte i = 0; i < rfid.uid.size; i++) {
    if (rfid.uid.uidByte[i] < 0x10) Serial.print("0");
    Serial.print(rfid.uid.uidByte[i], HEX);
  }
  Serial.println("   <-- READ. This is the UID L-SIAMS would record.");

  rfid.PICC_HaltA();
  rfid.PCD_StopCrypto1();
}

void setup() {
  Serial.begin(115200);
  delay(600);

  Serial.println();
  Serial.println("L-SIAMS reader check");
  Serial.println("====================");
  Serial.println();
  Serial.println("Expecting:  SS 5   SCK 18   MOSI 23   MISO 19   RST 22");
  Serial.println("            3.3V -> 3V3 (never 5V)    GND -> GND    IRQ -> nothing");
  Serial.println();

  SPI.begin();
  rfid.PCD_Init();
  delay(50);

  /* ---- 1. the link -------------------------------------------------------- */

  byte distinct[SAMPLES];
  byte first = 0;
  uint8_t count = sampleVersion(distinct, &first);

  Serial.print("1. SPI link       version ");
  for (uint8_t d = 0; d < count; d++) {
    Serial.printf("0x%02X%s", distinct[d], d + 1 < count ? " / " : "");
  }

  if (count > 1) {
    Serial.println("   UNSTABLE");
    Serial.println("                   Different answers to the same question means a loose");
    Serial.println("                   connection. Wiggle MISO (19), then SCK (18), then");
    Serial.println("                   3.3V and GND, and watch which one changes this.");
  } else if (isRealVersion(first)) {
    Serial.println("   good");
  } else if (first == 0x00 || first == 0xFF) {
    Serial.println("   NOTHING ANSWERING");
    Serial.println("                   Check 3.3V, GND, and SS on GPIO 5.");
  } else {
    Serial.println("   stable, but not a documented version");
    Serial.println("                   The link is reliable — SPI is working. Whether the chip");
    Serial.println("                   is faulty or just an odd clone is what check 2 decides.");
  }

  /* ---- 2. the chip's own self test ---------------------------------------- */

  Serial.print("2. Chip self test ");
  bool selfTest = rfid.PCD_PerformSelfTest();
  Serial.println(selfTest
    ? "PASSED — the silicon is genuine and working"
    : "FAILED — the chip did not produce its own signature");

  /* PCD_PerformSelfTest leaves the chip reset and idle; without this nothing
   * after it works, which would look like a second fault. */
  rfid.PCD_Init();
  delay(50);

  /* ---- 3. the antenna driver ---------------------------------------------- */

  byte tx = rfid.PCD_ReadRegister(MFRC522::TxControlReg);
  bool antennaOn = (tx & 0x03) == 0x03;

  Serial.printf("3. Antenna        TxControlReg 0x%02X   %s\n", tx,
                antennaOn ? "on" : "OFF — the transmitter is not driving the coil");

  if (!antennaOn) {
    rfid.PCD_AntennaOn();
    delay(10);
    tx = rfid.PCD_ReadRegister(MFRC522::TxControlReg);
    Serial.printf("                  after switching it on: 0x%02X %s\n", tx,
                  (tx & 0x03) == 0x03 ? "(now on)" : "(still off — the chip is not accepting writes)");
  }

  /* ---- verdict ------------------------------------------------------------ */

  Serial.println();

  if (selfTest) {
    Serial.println("VERDICT: the reader works. If the version looked odd, it is a clone");
    Serial.println("         and the odd number is cosmetic — carry on and use it.");
  } else if (count > 1) {
    Serial.println("VERDICT: fix the wiring first. The self test cannot mean anything");
    Serial.println("         while the link itself is unreliable.");
  } else {
    Serial.println("VERDICT: the link is fine and the chip fails its own self test, so");
    Serial.println("         the module is faulty. Two things worth trying before buying");
    Serial.println("         a replacement:");
    Serial.println("           - move RST off GPIO 22 to another free pin, in case that");
    Serial.println("             pin is the problem rather than the module");
    Serial.println("           - power the ESP32 from a phone charger rather than the");
    Serial.println("             laptop, in case the 3.3V rail is sagging under load");
    Serial.println("         Cards are still attempted below, because some clones fail");
    Serial.println("         this test and read perfectly well.");
  }

  Serial.println();
}

void loop() {
  round_++;

  byte distinct[SAMPLES];
  byte first = 0;
  uint8_t count = sampleVersion(distinct, &first);

  Serial.printf("[%lu] version ", (unsigned long) round_);
  for (uint8_t d = 0; d < count; d++) {
    Serial.printf("0x%02X%s", distinct[d], d + 1 < count ? " / " : "");
  }
  Serial.println(count > 1 ? "   (unstable — wiggle one wire at a time)" : "");

  /* Always, whatever the version said. A clone that reports nonsense and reads
   * cards is a working reader, and the only way to find that out is to ask. */
  tryCard();

  Serial.println();
  delay(1500);
}
