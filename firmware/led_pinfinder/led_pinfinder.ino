/*
 * =============================================================================
 *  L-SIAMS — LED PIN FINDER  (standalone diagnostic)
 * =============================================================================
 *
 *  The LEDs will not light on GPIO 25/26. This sketch checks whether they are
 *  actually connected to some OTHER pin — a solder that slipped to a neighbour,
 *  or a mislabelled wire. It drives EVERY usable output pin on the board, one
 *  at a time, LOW then HIGH, and prints the pin number as it goes.
 *
 *  HOW TO USE
 *   1. Upload this sketch. Open Serial Monitor at 115200.
 *   2. Watch the two LEDs while it steps through the pins (dim the room / cup a
 *      hand over them so a faint glow shows).
 *   3. If a LED lights, read the GPIO number printed at that moment and tell me.
 *      - lit during the "LOW" phase  -> active-low wiring
 *      - lit during the "HIGH" phase -> active-high wiring
 *   4. When it reaches GPIO 2 you may see the board's own small blue LED — that
 *      just confirms the ESP32's outputs are working.
 *
 *  If NOTHING lights on ANY pin in either phase, the fault is not a pin: it is a
 *  broken joint, a reversed or dead LED, or no power reaching the little board —
 *  and only re-soldering fixes that.
 *
 *  Touches no credentials. Re-upload the real L_SIAMS_Bench sketch when done.
 * =============================================================================
 */

/* Every output-capable GPIO on a 30-pin ESP32 dev board. Input-only pins
 * (34/35/36/39) are deliberately excluded — they cannot drive an LED at all. */
const int PINS[] = {2, 4, 5, 12, 13, 14, 15, 16, 17, 18, 19, 21, 22, 23, 25, 26, 27, 32, 33};
const int PIN_COUNT = sizeof(PINS) / sizeof(PINS[0]);

const uint32_t HOLD_MS = 1500;   /* time on each pin, each phase */

static void allInput() {
  for (int i = 0; i < PIN_COUNT; i++) {
    pinMode(PINS[i], INPUT);
  }
}

void setup() {
  Serial.begin(115200);
  delay(900);
  allInput();

  Serial.println();
  Serial.println("=================================================");
  Serial.println(" L-SIAMS  LED PIN FINDER");
  Serial.println("=================================================");
  Serial.println(" Driving every output pin LOW then HIGH, 1.5s each.");
  Serial.println(" If a LED lights, note the GPIO number printed then:");
  Serial.println("   lit on LOW  -> active-low wiring");
  Serial.println("   lit on HIGH -> active-high wiring");
  Serial.println(" Nothing on any pin -> broken joint / dead LED / no power.");
  Serial.println("=================================================");
}

void loop() {
  for (int i = 0; i < PIN_COUNT; i++) {
    allInput();
    pinMode(PINS[i], OUTPUT);

    Serial.println();
    Serial.print(">>> GPIO ");
    Serial.print(PINS[i]);
    Serial.println(" = LOW  (watch 1.5s)");
    digitalWrite(PINS[i], LOW);
    delay(HOLD_MS);

    Serial.print(">>> GPIO ");
    Serial.print(PINS[i]);
    Serial.println(" = HIGH (watch 1.5s)");
    digitalWrite(PINS[i], HIGH);
    delay(HOLD_MS);

    pinMode(PINS[i], INPUT);
  }

  Serial.println();
  Serial.println("----- full sweep done, repeating in 2s -----");
  delay(2000);
}
