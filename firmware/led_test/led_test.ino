/*
 * =============================================================================
 *  L-SIAMS — LED wiring tester  (standalone diagnostic)
 * =============================================================================
 *
 *  WHAT THIS IS FOR
 *  The two status LEDs are not lighting, and on a soldered board we cannot
 *  probe with a meter. So instead of guessing, this sketch drives GPIO 25 and
 *  GPIO 26 through EVERY combination of level, one at a time, and announces
 *  each step on the serial monitor. You watch the LEDs and tell me which TEST
 *  number lit which colour.
 *
 *  HOW TO USE
 *   1. Upload THIS sketch to the ESP32 (it replaces the main one for now —
 *      that is fine, we put the real one back after).
 *   2. Open Serial Monitor at 115200 baud.
 *   3. Watch the two LEDs. Each test runs for 5 seconds and prints its name.
 *   4. Note which TEST number makes GREEN light, and which makes RED light.
 *      Tip: cup a hand over the LEDs / dim the room so a faint glow shows.
 *   5. Tell me the results. Then re-upload the real L_SIAMS_Bench sketch.
 *
 *  It changes nothing permanent and touches no Wi-Fi / API credentials.
 *
 *  If your wires actually went to different pins than 25/26, change GREEN_PIN
 *  and RED_PIN below to match and re-upload.
 * =============================================================================
 */

const int GREEN_PIN = 25;   // wire you called "green"
const int RED_PIN   = 26;   // wire you called "red"

const uint32_t HOLD_MS = 5000;   // how long each test is held so you can look

/* Release both pins to high-impedance input so neither is driving a LED —
 * used between tests so only the pin under test can change anything. */
static void releaseBoth() {
  pinMode(GREEN_PIN, INPUT);
  pinMode(RED_PIN,   INPUT);
}

/* Drive ONE pin to a fixed level, hold, announce, then release. */
static void driveOne(int pin, int level, const char *name) {
  releaseBoth();
  pinMode(pin, OUTPUT);
  digitalWrite(pin, level);

  Serial.println();
  Serial.print(">>> ");
  Serial.println(name);
  Serial.println("    watch the LEDs for 5 seconds...");

  delay(HOLD_MS);

  releaseBoth();
  delay(700);
}

/* Drive BOTH pins to a level at once. */
static void driveBoth(int level, const char *name) {
  pinMode(GREEN_PIN, OUTPUT);
  pinMode(RED_PIN,   OUTPUT);
  digitalWrite(GREEN_PIN, level);
  digitalWrite(RED_PIN,   level);

  Serial.println();
  Serial.print(">>> ");
  Serial.println(name);
  Serial.println("    watch the LEDs for 5 seconds...");

  delay(HOLD_MS);

  releaseBoth();
  delay(700);
}

void setup() {
  Serial.begin(115200);
  delay(900);

  releaseBoth();

  Serial.println();
  Serial.println("=================================================");
  Serial.println(" L-SIAMS  LED WIRING TESTER");
  Serial.println("=================================================");
  Serial.println(" GREEN wire is on GPIO 25, RED wire is on GPIO 26.");
  Serial.println();
  Serial.println(" Watch the LEDs and note which TEST lights which:");
  Serial.println("  - lights on a LOW test  -> active-low wiring");
  Serial.println("      (VCC -> LED -> resistor -> GPIO)");
  Serial.println("  - lights on a HIGH test -> active-high wiring");
  Serial.println("      (GPIO -> resistor -> LED -> GND)");
  Serial.println("  - green lights on a GPIO26 test (or red on 25)");
  Serial.println("      -> the two wires are swapped");
  Serial.println("  - NOTHING lights in any test -> broken solder joint,");
  Serial.println("      LED in backwards, dead LED, or no power to the board");
  Serial.println("=================================================");
}

void loop() {
  driveOne(GREEN_PIN, HIGH, "TEST 1 : GPIO25 = HIGH  (3.3V out)");
  driveOne(GREEN_PIN, LOW,  "TEST 2 : GPIO25 = LOW   (0V / sink)");
  driveOne(RED_PIN,   HIGH, "TEST 3 : GPIO26 = HIGH  (3.3V out)");
  driveOne(RED_PIN,   LOW,  "TEST 4 : GPIO26 = LOW   (0V / sink)");
  driveBoth(HIGH,           "TEST 5 : BOTH   = HIGH");
  driveBoth(LOW,            "TEST 6 : BOTH   = LOW");

  Serial.println();
  Serial.println("----- full cycle done, repeating in 3 seconds -----");
  delay(3000);
}
