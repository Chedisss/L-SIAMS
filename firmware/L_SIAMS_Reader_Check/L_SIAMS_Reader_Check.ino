/* ===========================================================================
 * L-SIAMS — reader check
 *
 * Answers one question: is the MFRC522 wired correctly? No Wi-Fi, no server,
 * no credentials, no fingerprint sensor. Flash it, open the Serial Monitor at
 * 115200, and read.
 *
 * It reads the reader's version register twenty times a second and reports
 * whether the answer is a real version, a wrong one, or a different one each
 * time — because those three mean three different things and only the first
 * is worth chasing a card over.
 *
 * It keeps reporting, so you can wiggle one wire at a time and watch the line
 * change. The wire that changes it is the bad one. That is the fastest way to
 * find a poor connection, and it needs no meter.
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

/* How many times to read the version register per round. Enough that an
 * intermittent connection shows up rather than being missed by one lucky
 * read, which is exactly how this fault hides. */
#define SAMPLES  20

MFRC522 rfid(PIN_SS, PIN_RST);

static uint32_t round_ = 0;

/** Every value an MFRC522 or a common clone actually reports. */
static bool isRealVersion(byte v) {
  return v == 0x91 || v == 0x92 || v == 0x88 || v == 0x90 || v == 0x12;
}

static void banner() {
  Serial.println();
  Serial.println("L-SIAMS reader check");
  Serial.println("====================");
  Serial.println();
  Serial.println("Expecting:  SS 5   SCK 18   MOSI 23   MISO 19   RST 22");
  Serial.println("            3.3V -> 3V3 (never 5V)    GND -> GND    IRQ -> nothing");
  Serial.println();
  Serial.println("Wiggle one wire at a time and watch the line below change.");
  Serial.println("The wire that changes it is the one to fix.");
  Serial.println();
}

void setup() {
  Serial.begin(115200);
  delay(600);

  banner();

  SPI.begin();
  rfid.PCD_Init();
  delay(50);
}

void loop() {
  round_++;

  /* --- sample the version register --------------------------------------- */

  byte values[SAMPLES];
  byte distinct[SAMPLES];
  uint8_t distinctCount = 0;

  for (uint8_t i = 0; i < SAMPLES; i++) {
    values[i] = rfid.PCD_ReadRegister(MFRC522::VersionReg);

    bool seen = false;
    for (uint8_t d = 0; d < distinctCount; d++) {
      if (distinct[d] == values[i]) { seen = true; break; }
    }
    if (!seen) distinct[distinctCount++] = values[i];

    delay(5);
  }

  const bool stable = (distinctCount == 1);
  const byte first  = values[0];

  /* --- say what that means ----------------------------------------------- */

  Serial.printf("[%lu] version ", (unsigned long) round_);

  for (uint8_t d = 0; d < distinctCount; d++) {
    Serial.printf("0x%02X%s", distinct[d], d + 1 < distinctCount ? " / " : "");
  }

  if (stable && isRealVersion(first)) {
    Serial.println("   OK — the reader is wired correctly.");
  } else if (stable && (first == 0x00 || first == 0xFF)) {
    Serial.println("   NOTHING ANSWERING.");
    Serial.println("      Check 3.3V, GND, and SS on GPIO 5. A reader with no power");
    Serial.println("      or no chip-select answers with all-zeros or all-ones.");
  } else if (stable) {
    Serial.println("   WRONG, but consistent.");
    Serial.println("      The link is reliable, so the wiring is probably sound, but this");
    Serial.println("      is not a version any MFRC522 reports. Most likely a dead module");
    Serial.println("      or an unusual clone. Try a different one.");
  } else {
    Serial.println("   UNSTABLE — different answers to the same question.");
    Serial.println("      This is a connection, not a configuration. Wiggle MISO (19),");
    Serial.println("      then SCK (18), then 3.3V and GND, and watch which one changes");
    Serial.println("      this line. Joined dupont pairs in mid-air are the usual cause.");
  }

  /* --- if the reader is healthy, try a card ------------------------------- */

  if (stable && isRealVersion(first)) {
    /* Cards halted by an earlier read stay halted until they leave the field,
     * so a card resting on the reader answers WUPA and not REQA. Both are
     * asked, or a card sitting there looks like no card at all. */
    bool present = rfid.PICC_IsNewCardPresent();

    if (!present) {
      rfid.PCD_WriteRegister(MFRC522::TxModeReg, 0x00);
      rfid.PCD_WriteRegister(MFRC522::RxModeReg, 0x00);
      rfid.PCD_WriteRegister(MFRC522::ModWidthReg, 0x26);

      byte atqa[2];
      byte len = sizeof(atqa);
      MFRC522::StatusCode s = rfid.PICC_WakeupA(atqa, &len);
      present = (s == MFRC522::STATUS_OK || s == MFRC522::STATUS_COLLISION);
    }

    if (!present) {
      Serial.println("      no card in the field — hold one flat on the reader");
    } else if (!rfid.PICC_ReadCardSerial()) {
      Serial.println("      a card ANSWERED but would not select — hold it flatter, or");
      Serial.println("      suspect the antenna or the 3.3V supply under load");
    } else {
      Serial.print("      CARD ");
      for (byte i = 0; i < rfid.uid.size; i++) {
        if (rfid.uid.uidByte[i] < 0x10) Serial.print("0");
        Serial.print(rfid.uid.uidByte[i], HEX);
      }
      Serial.println("   <-- this is the UID L-SIAMS would record");

      rfid.PICC_HaltA();
      rfid.PCD_StopCrypto1();
    }
  }

  Serial.println();
  delay(1500);
}
