/* ===========================================================================
 * L-SIAMS classroom terminal - simple build
 * ===========================================================================
 * Does four things and nothing else:
 *
 *   1. joins the Wi-Fi
 *   2. registers itself with the server (claim)
 *   3. reads a teacher's fingerprint to open the class register
 *   4. reads student RFID cards and sends each tap to the database
 *
 * Everything the board is doing is printed as a one-line status, so the Serial
 * Monitor tells you the state of the terminal rather than scrolling.
 *
 * Libraries (Arduino IDE -> Tools -> Manage Libraries):
 *   MFRC522                by GithubCommunity
 *   Adafruit Fingerprint Sensor Library
 *   ArduinoJson            by Benoit Blanchon   (v6 or v7, either is fine)
 *
 * Board: "ESP32 Dev Module".
 *
 * ---------------------------------------------------------------------------
 * WIRING
 *
 *   RFID-RC522        ESP32          Fingerprint AS608   ESP32
 *   ----------        -----          -----------------   -----
 *   SDA  (SS)   ->    GPIO 5         TX            ->    GPIO 17
 *   SCK         ->    GPIO 18        RX            ->    GPIO 16
 *   MOSI        ->    GPIO 23        VCC           ->    3V3
 *   MISO        ->    GPIO 19        GND           ->    GND
 *   RST         ->    GPIO 22
 *   3.3V        ->    3V3            Both modules are 3.3 V. 5 V kills them.
 *   GND         ->    GND
 * =========================================================================== */

#include <WiFi.h>
#include <HTTPClient.h>
#include <SPI.h>
#include <MFRC522.h>
#include <Adafruit_Fingerprint.h>
#include <ArduinoJson.h>
#include <time.h>
#include <esp_system.h>
#include "mbedtls/md.h"

/* ArduinoJson 6 and 7 disagree about how a document is declared. One alias
 * covers both, so either version installs and works. */
#if ARDUINOJSON_VERSION_MAJOR < 7
struct LsJson : public DynamicJsonDocument { LsJson() : DynamicJsonDocument(2048) {} };
#else
using LsJson = JsonDocument;
#endif

/* ===========================================================================
 * EDIT THESE SEVEN LINES, THEN UPLOAD
 * ---------------------------------------------------------------------------
 * Four come from the provisioning JSON you downloaded when you registered this
 * terminal on the Devices page:
 *
 *      LS_DEVICE_ID    <- "device_id"
 *      LS_API_KEY      <- "api_key"
 *      LS_HMAC_SECRET  <- "hmac_secret"
 *      LS_CLAIM_TOKEN  <- "claim_token"
 *
 * The other three you type yourself:
 *
 *      LS_WIFI_SSID    "StaffRoom-2G"
 *      LS_WIFI_PASS    "your wifi password"
 *      LS_SERVER_URL   "http://192.168.1.14:8080"
 *
 * LS_SERVER_URL must be the PC's LAN address WITH the port - the one start.bat
 * prints. Never localhost or 127.0.0.1: to this board those mean this board,
 * so the request never leaves it.
 *
 * REGISTERING A SECOND TERMINAL: give it its own LS_DEVICE_ID, its own key and
 * its own claim token, and register it on the Devices page with THAT board's
 * MAC - the one this sketch prints at boot. Two boards cannot share a
 * provisioning file, and two boards cannot sit in the same classroom.
 * =========================================================================== */

#if defined(__has_include)
#  if __has_include("secrets.h")
#    include "secrets.h"
#  endif
#endif

#ifndef LS_WIFI_SSID
#define LS_WIFI_SSID    "PASTE_WIFI_NAME"
#endif
#ifndef LS_WIFI_PASS
#define LS_WIFI_PASS    "PASTE_WIFI_PASSWORD"
#endif
#ifndef LS_SERVER_URL
#define LS_SERVER_URL   "PASTE_SERVER_URL"
#endif
#ifndef LS_DEVICE_ID
#define LS_DEVICE_ID    "PASTE_DEVICE_ID"
#endif
#ifndef LS_API_KEY
#define LS_API_KEY      "PASTE_API_KEY"
#endif
#ifndef LS_HMAC_SECRET
#define LS_HMAC_SECRET  "PASTE_HMAC_SECRET"
#endif
#ifndef LS_CLAIM_TOKEN
#define LS_CLAIM_TOKEN  ""
#endif

/* ------------------------------------------------------------------ pins -- */

#define PIN_RFID_SS      5
#define PIN_RFID_RST     22
#define PIN_FINGER_RX    17     /* ESP32 listens here - wire to sensor TX */
#define PIN_FINGER_TX    16     /* ESP32 speaks here  - wire to sensor RX */
#define FINGER_BAUD      57600

/* ---------------------------------------------------------------- timing -- */

#define HEARTBEAT_MS     30000  /* the server calls a terminal offline at 90 s */
#define CARD_REPEAT_MS    2500  /* a held card reports over and over           */
#define FINGER_REPEAT_MS  3000
#define HTTP_TIMEOUT_MS   8000

/* ----------------------------------------------------------------- state -- */

MFRC522              rfid(PIN_RFID_SS, PIN_RFID_RST);
HardwareSerial       fingerSerial(2);
Adafruit_Fingerprint finger(&fingerSerial);

static bool     rfidReady   = false;
static bool     fingerReady = false;
static bool     claimed     = false;
static long     clockOffset = 0;      /* server epoch minus board epoch */
static uint32_t lastBeat    = 0;
static uint32_t lastCardAt  = 0;
static uint32_t lastFingerAt = 0;
static String   lastUid     = "";
static String   sessionLine = "no session open";

/* =========================================================================
 * Small helpers
 * ========================================================================= */

static String hex(const uint8_t *data, size_t len) {
  static const char digits[] = "0123456789abcdef";
  String out;
  out.reserve(len * 2);
  for (size_t i = 0; i < len; i++) {
    out += digits[data[i] >> 4];
    out += digits[data[i] & 0x0F];
  }
  return out;
}

static String sha256Hex(const String &message) {
  uint8_t digest[32];
  const mbedtls_md_info_t *info = mbedtls_md_info_from_type(MBEDTLS_MD_SHA256);
  mbedtls_md_context_t ctx;
  mbedtls_md_init(&ctx);
  mbedtls_md_setup(&ctx, info, 0);
  mbedtls_md_starts(&ctx);
  mbedtls_md_update(&ctx, (const uint8_t *) message.c_str(), message.length());
  mbedtls_md_finish(&ctx, digest);
  mbedtls_md_free(&ctx);
  return hex(digest, 32);
}

static String hmacSha256Hex(const String &message, const char *key) {
  uint8_t digest[32];
  const mbedtls_md_info_t *info = mbedtls_md_info_from_type(MBEDTLS_MD_SHA256);
  mbedtls_md_context_t ctx;
  mbedtls_md_init(&ctx);
  mbedtls_md_setup(&ctx, info, 1);
  mbedtls_md_hmac_starts(&ctx, (const uint8_t *) key, strlen(key));
  mbedtls_md_hmac_update(&ctx, (const uint8_t *) message.c_str(), message.length());
  mbedtls_md_hmac_finish(&ctx, digest);
  mbedtls_md_free(&ctx);
  return hex(digest, 32);
}

static String boardMac() {
  uint8_t mac[6];
  esp_read_mac(mac, ESP_MAC_WIFI_STA);
  char buf[18];
  snprintf(buf, sizeof(buf), "%02X:%02X:%02X:%02X:%02X:%02X",
           mac[0], mac[1], mac[2], mac[3], mac[4], mac[5]);
  return String(buf);
}

/* A v4 UUID. The server uses it to make a retried tap land exactly once. */
static String uuid() {
  uint8_t b[16];
  for (uint8_t i = 0; i < 16; i++) b[i] = (uint8_t) esp_random();
  b[6] = (b[6] & 0x0F) | 0x40;
  b[8] = (b[8] & 0x3F) | 0x80;
  String h = hex(b, 16);
  return h.substring(0, 8) + "-" + h.substring(8, 12) + "-" + h.substring(12, 16)
       + "-" + h.substring(16, 20) + "-" + h.substring(20);
}

static long serverNow() { return (long) time(nullptr) + clockOffset; }

/* =========================================================================
 * Talking to the server
 * ========================================================================= */

/* An unsigned POST - only /api/device/claim accepts one. */
static int plainPost(const String &path, const String &body, LsJson *out) {
  HTTPClient http;
  http.setTimeout(HTTP_TIMEOUT_MS);
  if (!http.begin(String(LS_SERVER_URL) + path)) return -1;
  http.addHeader("Content-Type", "application/json");
  int status = http.POST(body);
  if (status > 0 && out != nullptr) deserializeJson(*out, http.getString());
  http.end();
  return status;
}

/* Every other request is signed. The server recomputes this signature and
 * refuses anything that does not match, so a terminal with tampered firmware
 * or a stolen key from the wrong network cannot record attendance. */
static int signedRequest(const char *method, const String &path,
                         const String &body, LsJson *out,
                         const String &requestId = "") {
  HTTPClient http;
  http.setTimeout(HTTP_TIMEOUT_MS);
  if (!http.begin(String(LS_SERVER_URL) + path)) return -1;

  String ts    = String(serverNow());
  uint8_t r[8];
  for (uint8_t i = 0; i < 8; i++) r[i] = (uint8_t) esp_random();
  String nonce = hex(r, 8);

  String canonical = String(method) + "\n" + path + "\n" + LS_DEVICE_ID + "\n"
                   + ts + "\n" + nonce + "\n" + sha256Hex(body);

  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-LSIAMS-Device-Id", LS_DEVICE_ID);
  http.addHeader("X-LSIAMS-Api-Key", LS_API_KEY);
  http.addHeader("X-LSIAMS-Timestamp", ts);
  http.addHeader("X-LSIAMS-Nonce", nonce);
  http.addHeader("X-LSIAMS-Signature", hmacSha256Hex(canonical, LS_HMAC_SECRET));
  if (requestId.length()) http.addHeader("X-LSIAMS-Request-Id", requestId);

  int status = strcmp(method, "GET") == 0 ? http.GET() : http.POST(body);
  if (status > 0 && out != nullptr) deserializeJson(*out, http.getString());
  http.end();
  return status;
}

/* =========================================================================
 * Bring-up
 * ========================================================================= */

/* Stop, and keep saying why.
 *
 * setup() returning does NOT stop an Arduino sketch - loop() runs regardless.
 * A board that "stopped" this way carries on polling hardware it never
 * initialised and printing into the void, which is what a terminal stuck here
 * looks like from the Serial Monitor: endless text and no clue. So the halt is
 * a real one, and it repeats the reason every 15 seconds instead of scrolling
 * something new. */
static void halt(const String &reason) {
  Serial.println();
  Serial.println("=====================================================");
  Serial.println("STOPPED: " + reason);
  Serial.println("=====================================================");
  Serial.println("Fix the above, then press the RESET button on the board.");
  for (;;) {
    delay(15000);
    Serial.println("[stopped] " + reason + "  - press RESET after fixing");
  }
}

static bool configLooksReal() {
  struct { const char *value; const char *name; } required[] = {
    { LS_WIFI_SSID,   "LS_WIFI_SSID"   },
    { LS_SERVER_URL,  "LS_SERVER_URL"  },
    { LS_DEVICE_ID,   "LS_DEVICE_ID"   },
    { LS_API_KEY,     "LS_API_KEY"     },
    { LS_HMAC_SECRET, "LS_HMAC_SECRET" },
  };
  bool ok = true;

  for (uint8_t i = 0; i < 5; i++) {
    if (strncmp(required[i].value, "PASTE_", 6) == 0) {
      Serial.printf("  %s is still the placeholder\n", required[i].name);
      ok = false;
    }
  }

  /* Not a placeholder, and the worst one to get wrong: the board joins the
   * Wi-Fi, reports nothing, and shows Offline forever, because every request
   * went to the ESP32 itself. */
  if (strstr(LS_SERVER_URL, "localhost") || strstr(LS_SERVER_URL, "127.0.0.1")) {
    Serial.println("  LS_SERVER_URL points at localhost, which to this board means");
    Serial.println("  THIS BOARD. Use the PC's LAN address, e.g. http://192.168.1.14:8080");
    ok = false;
  }
  return ok;
}

static bool connectWifi(uint32_t timeoutMs) {
  Serial.printf("[wifi ] joining \"%s\" ", LS_WIFI_SSID);
  WiFi.mode(WIFI_STA);
  WiFi.begin(LS_WIFI_SSID, LS_WIFI_PASS);

  uint32_t started = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - started < timeoutMs) {
    delay(400);
    Serial.print(".");
  }
  Serial.println();

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[wifi ] FAILED - wrong name or password, or out of range");
    return false;
  }
  Serial.print("[wifi ] connected, this board is ");
  Serial.println(WiFi.localIP());
  return true;
}

/* The clock, from the one endpoint that needs no signature.
 *
 * Every signed request is refused if the timestamp is more than 30 s off, and
 * an ESP32 wakes with no clock at all - so the clock has to come first, and it
 * cannot come from a signed endpoint. */
static bool syncClock() {
  HTTPClient http;
  http.setTimeout(HTTP_TIMEOUT_MS);
  if (!http.begin(String(LS_SERVER_URL) + "/api/health")) return false;

  int status = http.GET();
  if (status != 200) {
    http.end();
    Serial.printf("[clock] server did not answer /api/health (HTTP %d)\n", status);
    return false;
  }

  LsJson doc;
  DeserializationError err = deserializeJson(doc, http.getString());
  http.end();
  if (err) return false;

  long epoch = doc["data"]["server_epoch"] | 0L;
  if (epoch <= 0) {
    Serial.println("[clock] server sent no epoch - update the server, then retry");
    return false;
  }

  clockOffset = epoch - (long) time(nullptr);
  Serial.println("[clock] set from the server");
  return true;
}

/* Register this board with the server. This is what turns it from Pending
 * into an active terminal on the Devices page. */
static bool claimDevice() {
  if (strlen(LS_CLAIM_TOKEN) == 0) {
    Serial.println("[claim] no token in the sketch - assuming already claimed");
    return true;
  }

  LsJson body;
  body["claim_token"] = LS_CLAIM_TOKEN;
  body["device_id"]   = LS_DEVICE_ID;
  body["mac_address"] = boardMac();

  String payload;
  serializeJson(body, payload);

  LsJson res;
  int status = plainPost("/api/device/claim", payload, &res);

  if (status == 200 || status == 201) {
    Serial.println("[claim] ACCEPTED - this terminal is now active");
    return true;
  }

  const char *code = res["code"] | "";
  Serial.printf("[claim] REFUSED (HTTP %d %s) %s\n", status, code,
                (const char *) (res["message"] | ""));

  /* Already spent is not a failure. The token is single use, so every reboot
   * after the first lands here - the board is claimed and should carry on. */
  if (strcmp(code, "CLAIM_TOKEN_USED") == 0) {
    Serial.println("        (already claimed - carrying on)");
    return true;
  }

  if (strcmp(code, "CLAIM_IDENTITY_MISMATCH") == 0) {
    Serial.println();
    Serial.println("        The server has a different MAC recorded for this device id.");
    Serial.println("        THIS board is  " + boardMac());
    Serial.println("        Open the Devices page, edit " LS_DEVICE_ID ", and set that MAC.");
    Serial.println("        This is the usual reason a second terminal stays Offline:");
    Serial.println("        two boards cannot share one provisioning file.");
  }

  if (status < 0) {
    Serial.println("        The server could not be reached at all. Check start.bat is");
    Serial.println("        running, that LS_SERVER_URL is the PC's LAN address, and");
    Serial.println("        that Windows Firewall is not blocking the port.");
  }
  return false;
}

static void sendHeartbeat() {
  LsJson body;
  body["firmware_version"] = "simple-1.0";
  body["uptime_sec"]       = millis() / 1000;
  body["free_heap"]        = ESP.getFreeHeap();
  body["wifi_rssi"]        = WiFi.RSSI();

  String payload;
  serializeJson(body, payload);

  LsJson res;
  int status = signedRequest("POST", "/api/device/heartbeat", payload, &res);

  if (status == 200) {
    /* The server says whether a register is open in this room, which is the
     * one piece of state worth showing on a terminal with no screen. */
    if (!res["data"]["active_session"].isNull()) {
      const char *sec = res["data"]["active_session"]["section"] | "";
      const char *sub = res["data"]["active_session"]["subject"] | "";
      sessionLine = String("session open: ") + sub + " " + sec;
    } else {
      sessionLine = "no session open";
    }
    Serial.println("[beat ] ok - " + sessionLine);
    return;
  }

  const char *code = res["code"] | "";
  Serial.printf("[beat ] FAILED (HTTP %d %s)\n", status, code);

  /* Drift is the common one in the field and it fixes itself, so do that
   * rather than making somebody power-cycle the board. */
  if (strcmp(code, "TIMESTAMP_EXPIRED") == 0) {
    Serial.println("        clock drifted - resyncing");
    syncClock();
  }
}

/* =========================================================================
 * Readers
 * ========================================================================= */

static void startRfid() {
  SPI.begin();
  rfid.PCD_Init();
  delay(50);
  byte version = rfid.PCD_ReadRegister(MFRC522::VersionReg);
  rfidReady = (version != 0x00 && version != 0xFF);
  Serial.printf("[rfid ] %s (version 0x%02X)\n",
                rfidReady ? "ready" : "NOT FOUND - check wiring and 3.3V", version);
}

static void startFingerprint() {
  fingerSerial.begin(FINGER_BAUD, SERIAL_8N1, PIN_FINGER_RX, PIN_FINGER_TX);
  delay(100);
  fingerReady = finger.verifyPassword();
  Serial.printf("[finger] %s\n",
                fingerReady ? "ready" : "NOT FOUND - check TX/RX are crossed and 3.3V");
}

static String readCardUid() {
  if (!rfidReady) return "";
  if (!rfid.PICC_IsNewCardPresent()) return "";
  if (!rfid.PICC_ReadCardSerial()) return "";

  String uid = hex(rfid.uid.uidByte, rfid.uid.size);
  uid.toUpperCase();

  rfid.PICC_HaltA();
  rfid.PCD_StopCrypto1();
  return uid;
}

/* A teacher's finger opens the register. Nothing else does - no password, no
 * button, no action on the web side. */
static void handleFinger() {
  if (!fingerReady) return;
  if (millis() - lastFingerAt < FINGER_REPEAT_MS) return;
  if (finger.getImage() != FINGERPRINT_OK) return;
  if (finger.image2Tz() != FINGERPRINT_OK) return;
  if (finger.fingerFastSearch() != FINGERPRINT_OK) {
    Serial.println("[finger] not recognised");
    lastFingerAt = millis();
    return;
  }

  lastFingerAt = millis();
  Serial.printf("\n[finger] slot %d, confidence %d\n", finger.fingerID, finger.confidence);

  LsJson body;
  body["fingerprint_id"] = finger.fingerID;
  body["confidence"]     = finger.confidence;

  String payload;
  serializeJson(body, payload);

  LsJson res;
  int status = signedRequest("POST", "/api/attendance/start", payload, &res);

  if (status == 201 || status == 200) {
    Serial.printf("         SESSION OPEN - %s\n", (const char *) (res["teacher"] | "teacher"));
    Serial.printf("         %s\n", (const char *) (res["display_line_3"] | ""));
    sessionLine = "session open";
  } else {
    Serial.printf("         refused (HTTP %d %s) %s\n", status,
                  (const char *) (res["code"] | ""),
                  (const char *) (res["message"] | ""));
  }
}

/* A card tap. The board reports that a card was presented; the SERVER decides
 * whether that is an arrival, a departure or a refusal. */
static void sendTap(const String &uid) {
  String requestId = uuid();

  LsJson body;
  body["rfid_uid"]   = uid;
  body["request_id"] = requestId;

  String payload;
  serializeJson(body, payload);

  LsJson res;
  int status = signedRequest("POST", "/api/attendance/tap", payload, &res, requestId);

  const char *code = res["code"] | "";

  if (status == 200 || status == 201) {
    Serial.printf("         %s - %s\n", code, (const char *) (res["message"] | ""));
    return;
  }

  Serial.printf("         REFUSED (HTTP %d %s) %s\n", status, code,
                (const char *) (res["message"] | ""));

  if (strcmp(code, "TIMESTAMP_EXPIRED") == 0) syncClock();
}

/* =========================================================================
 * setup / loop
 * ========================================================================= */

void setup() {
  Serial.begin(115200);
  delay(800);

  Serial.println();
  Serial.println("=====================================================");
  Serial.println("L-SIAMS classroom terminal");
  Serial.println("=====================================================");
  Serial.println("MAC:    " + boardMac());
  Serial.println("        ^ register THIS terminal with THIS MAC");
  Serial.println("Device: " LS_DEVICE_ID);
  Serial.println("Server: " LS_SERVER_URL);
  Serial.println();

  if (!configLooksReal()) {
    halt("the settings at the top of this sketch are not filled in");
  }

  startRfid();
  startFingerprint();

  /* Two modules on two different buses rarely fail in the same boot. What they
   * share is the 3.3 V rail and the ground, so both going quiet together points
   * at power rather than at two separate faults. */
  if (!rfidReady && !fingerReady) {
    halt("neither reader responded - check the 3.3V and GND rails first");
  }

  if (!connectWifi(25000)) {
    halt("could not join the Wi-Fi");
  }

  if (!syncClock()) {
    halt("could not reach the server at " LS_SERVER_URL);
  }

  claimed = claimDevice();
  if (!claimed) {
    halt("the server refused to activate this terminal - see the reason above");
  }

  sendHeartbeat();

  Serial.println();
  Serial.println("=====================================================");
  Serial.println("READY - connected and claimed");
  Serial.println("A teacher scans a finger to open the register.");
  Serial.println("Students then tap their cards.");
  Serial.println("=====================================================");
  Serial.println();

  lastBeat = millis();
}

void loop() {
  /* Wi-Fi drops are normal on a school network and recover on their own, so
   * this reconnects rather than halting. */
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[wifi ] dropped - reconnecting");
    if (!connectWifi(20000)) {
      delay(5000);
      return;
    }
    syncClock();
  }

  /* The heartbeat is what makes this terminal show as Online. Miss it for
   * 90 seconds and the server marks the board offline. */
  if (millis() - lastBeat >= HEARTBEAT_MS) {
    lastBeat = millis();
    sendHeartbeat();
  }

  handleFinger();

  String uid = readCardUid();
  if (uid.length() == 0) {
    delay(50);
    return;
  }

  /* A card held against the reader reports continuously; without this one tap
   * becomes dozens of requests. */
  if (uid == lastUid && millis() - lastCardAt < CARD_REPEAT_MS) return;

  lastUid    = uid;
  lastCardAt = millis();

  Serial.println("\n[card ] " + uid);
  sendTap(uid);
}
