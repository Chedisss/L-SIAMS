/* ===========================================================================
 * L-SIAMS — who am I?
 *
 * Prints the board's MAC address, and lists the Wi-Fi networks it can see.
 * No credentials, no libraries to install, no wiring — flash it, open the
 * Serial Monitor at 115200, and read.
 *
 * Two things it answers that the terminal sketches cannot:
 *
 *   The MAC, before Wi-Fi works. The terminal sketch prints its MAC after it
 *   joins the network, so a board that cannot join never shows you the value
 *   you need to register it. This one reads the MAC out of the radio without
 *   connecting to anything.
 *
 *   Which networks are actually in range. The ESP32 has no 5 GHz radio. If
 *   your router publishes one name for both bands and the PC is on the 5 GHz
 *   side, the board and the PC are not on the same network however identical
 *   the name looks. A name missing from this list is a name this board cannot
 *   join, whatever your laptop shows.
 *
 * Board: ESP32 Dev Module.  Serial Monitor: 115200.
 * ======================================================================== */

#include <WiFi.h>

void setup() {
  Serial.begin(115200);
  delay(600);

  Serial.println();
  Serial.println("L-SIAMS — board identity");
  Serial.println("========================");
  Serial.println();

  /* Station mode without begin(): the radio is initialised, so the MAC is
   * readable, but no association is attempted. */
  WiFi.mode(WIFI_STA);
  delay(100);

  Serial.print("MAC address: ");
  Serial.println(WiFi.macAddress());
  Serial.println();
  Serial.println("  ^ this is the value to register on the device's Edit page");
  Serial.println("    in L-SIAMS. Copy it exactly, colons and all. It does not");
  Serial.println("    change when you reflash, and it is not the address on the");
  Serial.println("    Bluetooth or access-point radios, which differ by one and");
  Serial.println("    will be refused.");
  Serial.println();

  Serial.println("Scanning for Wi-Fi networks (a few seconds)...");
  Serial.println();

  int found = WiFi.scanNetworks();

  if (found <= 0) {
    Serial.println("  No networks found at all. Either the antenna is not");
    Serial.println("  connected, or the board is out of range of everything.");
    return;
  }

  Serial.printf("  %-32s %6s  %s\n", "NETWORK NAME (SSID)", "SIGNAL", "SECURITY");
  Serial.println("  ---------------------------------------------------------");

  for (int i = 0; i < found; i++) {
    Serial.printf(
      "  %-32s %4d dBm  %s\n",
      WiFi.SSID(i).c_str(),
      WiFi.RSSI(i),
      WiFi.encryptionType(i) == WIFI_AUTH_OPEN ? "open" : "password"
    );
  }

  Serial.println();
  Serial.println("Every network listed above is 2.4 GHz — that is the only band");
  Serial.println("this board has. If the Wi-Fi your PC uses is not in this list,");
  Serial.println("the board cannot reach the PC no matter what is in the sketch.");
  Serial.println();
  Serial.println("Signal: better than -70 dBm is comfortable. Worse than -80 dBm");
  Serial.println("drops requests in ways that look like server faults.");
}

void loop() {
  delay(10000);
}
