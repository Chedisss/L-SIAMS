/* ===========================================================================
 * L-SIAMS Attendance Terminal — REFERENCE ONLY, DO NOT FLASH
 * ESP32 + MFRC522 + R307 + SSD1306
 * ---------------------------------------------------------------------------
 * Superseded by firmware/L_SIAMS_Terminal. Flash that one.
 *
 * Kept because it is the only implementation of three things the shipping
 * terminal does not have yet, and they are worth reading before writing them
 * again: the offline attendance queue in NVS, the SSD1306 status display, and
 * the explicit boot state machine drawn below.
 *
 * Why it is not the shipping firmware:
 *
 *   · It cannot issue student cards. /api/rfid/enrollment/* did not exist when
 *     this was written, so "Issue card" in the web interface would poll a
 *     terminal that never answers.
 *   · It requires an SSD1306 on I2C. Without one it does not come up.
 *   · It assumes a 5 V R307 on VIN. A bare AS608 is a 3.3 V part and that
 *     wiring destroys it.
 *   · None of the fixes found during bench testing are in it — the sensor slot
 *     accounting, the search retry, the reset-reason reporting, the sensor and
 *     server template-count comparison.
 *
 * Porting the queue forward is the intended use of this file. Porting anything
 * else is probably a mistake.
 * ---------------------------------------------------------------------------
 * State machine (Part 4 startup sequence, Part 16 tap handling):
 *
 *   BOOT ─► PROVISION ─► CONNECTING ─► CLAIMING ─► AUTHENTICATING
 *                                                        │
 *                                                        ▼
 *                                          ┌──────► READY ◄──────┐
 *                                          │          │          │
 *                                    (fingerprint)    │      (session
 *                                          │          │       closed)
 *                                          ▼          │          │
 *                                    SESSION_OPEN ────┴──────────┘
 *
 *   OFFLINE is entered from any state on network loss. Attendance keeps being
 *   recorded into the local queue — that is the whole point of the mode.
 *
 * Design notes worth knowing before changing anything:
 *
 *  · The device never decides whether a tap is an arrival or a departure. It
 *    reports "card X tapped at time Z"; the server resolves intent from the
 *    record state (Part 16.2). This is what keeps two terminals in one room
 *    consistent with each other.
 *
 *  · Every request is HMAC-signed over method, path, device id, timestamp,
 *    nonce and a hash of the body. A replayed request is refused by the server
 *    because the nonce is single-use.
 *
 *  · Every attendance request carries a UUIDv4 request_id that is persisted
 *    with the queued record. Retrying after a timeout is therefore safe: the
 *    server returns the original response instead of recording a second tap.
 * =========================================================================== */

#include <Arduino.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <SPI.h>
#include <Wire.h>
#include <Preferences.h>
#include <ArduinoJson.h>

/* ArduinoJson 7 made JsonDocument a concrete, self-sizing type. In 6 it is an
 * abstract base and only DynamicJsonDocument can be declared, with a capacity
 * given up front. Library Manager installs whichever the sketch asks for and
 * happily leaves an older one in place, and the failure under 6 reads "cannot
 * declare variable to be of abstract type 'JsonDocument'" -- which names
 * nothing you would think to go and change. One alias covers both versions.
 * Function parameters stay JsonDocument*: that is a valid base pointer in 6
 * and the type itself in 7. */
#if ARDUINOJSON_VERSION_MAJOR < 7
struct LsJson : public DynamicJsonDocument {
  LsJson() : DynamicJsonDocument(4096) {}
};
#else
using LsJson = JsonDocument;
#endif
#include <MFRC522.h>
#include <Adafruit_Fingerprint.h>
#include <Adafruit_GFX.h>
#include <Adafruit_SSD1306.h>
#include <mbedtls/md.h>
#include <esp_system.h>
#include <time.h>

#include "config.h"

/* ------------------------------------------------------------- state ----- */

enum TerminalState {
  STATE_BOOT,
  STATE_PROVISION,
  STATE_CONNECTING,
  STATE_CLAIMING,
  STATE_AUTHENTICATING,
  STATE_READY,
  STATE_ENROLLING,
  STATE_SESSION_OPEN,
  STATE_OFFLINE,
  STATE_LOCKED,
  STATE_ERROR
};

static TerminalState state = STATE_BOOT;

/* --------------------------------------------------------- peripherals --- */

MFRC522              rfid(PIN_RFID_SS, PIN_RFID_RST);
HardwareSerial       fingerSerial(2);
Adafruit_Fingerprint finger(&fingerSerial);
Adafruit_SSD1306     display(OLED_WIDTH, OLED_HEIGHT, &Wire, -1);
Preferences          prefs;

/* ------------------------------------------------------ device identity -- */

struct DeviceConfig {
  String deviceId;
  String apiKey;
  String hmacSecret;
  String serverUrl;
  String wifiSsid;
  String wifiPassword;
  String claimToken;
  String serverFingerprint;
  bool   claimed              = false;
  uint16_t heartbeatInterval  = DEFAULT_HEARTBEAT_INTERVAL_S;
  uint16_t syncInterval       = DEFAULT_SYNC_INTERVAL_S;
  uint16_t queueLimit         = DEFAULT_QUEUE_LIMIT;
} cfg;

/* ------------------------------------------------------ session context -- */

struct SessionContext {
  bool   open = false;
  String sessionId;
  String subject;
  String section;
  uint16_t timedIn  = 0;
  uint16_t timedOut = 0;
  uint16_t roster   = 0;
  time_t expiresAt  = 0;
} session;

/* ----------------------------------------------------------- timers ------ */

static uint32_t lastHeartbeat  = 0;
static uint32_t lastSync       = 0;
static uint32_t lastTimeSync   = 0;
static uint32_t lastWifiRetry  = 0;
static uint32_t displayUntil   = 0;
static uint32_t lockUntil      = 0;
static uint32_t bootMillis     = 0;
static uint32_t lastEnrollPoll = 0;

static String   lastCardUid    = "";
static uint32_t lastCardAt     = 0;
static uint8_t  fingerFailures = 0;
static uint16_t queueCount     = 0;
static bool     displayBusy    = false;

/* =========================================================================
 * Utilities
 * ========================================================================= */

/** UUIDv4 from the ESP32 hardware RNG. Used as the idempotency handle. */
String generateUuid() {
  uint8_t bytes[16];
  esp_fill_random(bytes, sizeof(bytes));

  bytes[6] = (bytes[6] & 0x0F) | 0x40;  /* version 4  */
  bytes[8] = (bytes[8] & 0x3F) | 0x80;  /* variant 10 */

  char buffer[37];
  snprintf(buffer, sizeof(buffer),
           "%02x%02x%02x%02x-%02x%02x-%02x%02x-%02x%02x-%02x%02x%02x%02x%02x%02x",
           bytes[0], bytes[1], bytes[2],  bytes[3],  bytes[4],  bytes[5],
           bytes[6], bytes[7], bytes[8],  bytes[9],  bytes[10], bytes[11],
           bytes[12], bytes[13], bytes[14], bytes[15]);

  return String(buffer);
}

/** Single-use nonce for replay protection. */
String generateNonce() {
  uint8_t bytes[NONCE_BYTES];
  esp_fill_random(bytes, sizeof(bytes));

  String out;
  out.reserve(NONCE_BYTES * 2);

  for (size_t i = 0; i < sizeof(bytes); i++) {
    char pair[3];
    snprintf(pair, sizeof(pair), "%02x", bytes[i]);
    out += pair;
  }

  return out;
}

String toHex(const uint8_t *data, size_t length) {
  String out;
  out.reserve(length * 2);

  for (size_t i = 0; i < length; i++) {
    char pair[3];
    snprintf(pair, sizeof(pair), "%02x", data[i]);
    out += pair;
  }

  return out;
}

String sha256Hex(const String &input) {
  uint8_t hash[32];
  mbedtls_md_context_t ctx;

  mbedtls_md_init(&ctx);
  mbedtls_md_setup(&ctx, mbedtls_md_info_from_type(MBEDTLS_MD_SHA256), 0);
  mbedtls_md_starts(&ctx);
  mbedtls_md_update(&ctx, (const unsigned char *) input.c_str(), input.length());
  mbedtls_md_finish(&ctx, hash);
  mbedtls_md_free(&ctx);

  return toHex(hash, sizeof(hash));
}

String hmacSha256Hex(const String &message, const String &key) {
  uint8_t hmac[32];
  mbedtls_md_context_t ctx;

  mbedtls_md_init(&ctx);
  mbedtls_md_setup(&ctx, mbedtls_md_info_from_type(MBEDTLS_MD_SHA256), 1);
  mbedtls_md_hmac_starts(&ctx, (const unsigned char *) key.c_str(), key.length());
  mbedtls_md_hmac_update(&ctx, (const unsigned char *) message.c_str(), message.length());
  mbedtls_md_hmac_finish(&ctx, hmac);
  mbedtls_md_free(&ctx);

  return toHex(hmac, sizeof(hmac));
}

/** Server-synchronised epoch seconds. */
time_t serverNow() {
  time_t now;
  time(&now);
  return now;
}

String isoTimestamp(time_t moment) {
  struct tm timeinfo;
  localtime_r(&moment, &timeinfo);

  char buffer[32];
  strftime(buffer, sizeof(buffer), "%Y-%m-%dT%H:%M:%S", &timeinfo);

  return String(buffer);
}

/* =========================================================================
 * Display
 * ========================================================================= */

void showLines(const String &line1, const String &line2 = "",
               const String &line3 = "", uint32_t holdMs = DISPLAY_HOLD_MS) {
  display.clearDisplay();
  display.setTextColor(SSD1306_WHITE);

  display.setTextSize(1);
  display.setCursor(0, 0);
  display.print(cfg.deviceId.length() ? cfg.deviceId : "L-SIAMS");

  /* Connectivity marker in the corner, always visible. */
  display.setCursor(OLED_WIDTH - 24, 0);
  display.print(WiFi.status() == WL_CONNECTED ? "ON" : "OFF");

  display.drawLine(0, 10, OLED_WIDTH, 10, SSD1306_WHITE);

  display.setTextSize(line1.length() <= 12 ? 2 : 1);
  display.setCursor(0, 16);
  display.print(line1);

  display.setTextSize(1);

  if (line2.length()) {
    display.setCursor(0, line1.length() <= 12 ? 38 : 30);
    display.print(line2.substring(0, 21));
  }

  if (line3.length()) {
    display.setCursor(0, line1.length() <= 12 ? 50 : 42);
    display.print(line3.substring(0, 21));
  }

  /* Session counters on the bottom row while attendance is open. */
  if (session.open && line1 != "SESSION OPEN") {
    display.setCursor(0, 56);
    display.printf("In:%d Out:%d/%d", session.timedIn, session.timedOut, session.roster);
  } else if (queueCount > 0) {
    display.setCursor(0, 56);
    display.printf("Queued: %d", queueCount);
  }

  display.display();

  displayUntil = millis() + holdMs;
  displayBusy  = holdMs > 0;
}

void showIdle() {
  displayBusy = false;
  display.clearDisplay();
  display.setTextColor(SSD1306_WHITE);

  display.setTextSize(1);
  display.setCursor(0, 0);
  display.print(cfg.deviceId);
  display.setCursor(OLED_WIDTH - 24, 0);
  display.print(WiFi.status() == WL_CONNECTED ? "ON" : "OFF");
  display.drawLine(0, 10, OLED_WIDTH, 10, SSD1306_WHITE);

  if (state == STATE_SESSION_OPEN) {
    display.setTextSize(1);
    display.setCursor(0, 16);
    display.print("TAP YOUR CARD");
    display.setCursor(0, 28);
    display.print(session.subject + " " + session.section);
    display.setCursor(0, 40);
    display.printf("In:%d  Out:%d  /%d", session.timedIn, session.timedOut, session.roster);
  } else if (state == STATE_READY) {
    display.setTextSize(1);
    display.setCursor(0, 16);
    display.print("READY");
    display.setCursor(0, 30);
    display.print("Teacher: place");
    display.setCursor(0, 40);
    display.print("finger on sensor");
  } else if (state == STATE_OFFLINE) {
    display.setTextSize(1);
    display.setCursor(0, 16);
    display.print("OFFLINE MODE");
    display.setCursor(0, 30);
    display.print("Attendance is being");
    display.setCursor(0, 40);
    display.print("saved locally.");
  } else if (state == STATE_LOCKED) {
    uint32_t remaining = lockUntil > millis() ? (lockUntil - millis()) / 1000 : 0;
    display.setTextSize(1);
    display.setCursor(0, 16);
    display.print("DEVICE LOCKED");
    display.setCursor(0, 32);
    display.printf("Unlocks in %lus", (unsigned long) remaining);
  }

  if (queueCount > 0) {
    display.setCursor(0, 56);
    display.printf("Queued: %d", queueCount);
  }

  display.display();
}

/* =========================================================================
 * Feedback — the Part 16.9 buzzer and LED map, expressed as named patterns
 * rather than magic numbers scattered through the code.
 * ========================================================================= */

void allLedsOff() {
  digitalWrite(PIN_LED_GREEN, LOW);
  digitalWrite(PIN_LED_RED, LOW);
  digitalWrite(PIN_LED_BLUE, LOW);
  digitalWrite(PIN_LED_AMBER, LOW);
}

void beep(uint16_t durationMs, uint8_t times = 1, uint16_t gapMs = 90) {
  for (uint8_t i = 0; i < times; i++) {
    digitalWrite(PIN_BUZZER, HIGH);
    delay(durationMs);
    digitalWrite(PIN_BUZZER, LOW);
    if (i + 1 < times) delay(gapMs);
  }
}

void feedback(const String &led, const String &buzzer) {
  allLedsOff();

  uint8_t pin = PIN_LED_GREEN;
  bool    blink = led.endsWith("-blink");
  String  colour = blink ? led.substring(0, led.indexOf('-')) : led;

  if (colour == "green")      pin = PIN_LED_GREEN;
  else if (colour == "red")   pin = PIN_LED_RED;
  else if (colour == "blue")  pin = PIN_LED_BLUE;
  else if (colour == "amber") pin = PIN_LED_AMBER;

  if (buzzer == "short")             beep(90, 1);
  else if (buzzer == "double_short") beep(90, 2);
  else if (buzzer == "triple_short") beep(80, 3);
  else if (buzzer == "long")         beep(650, 1);
  else if (buzzer == "rapid")        beep(60, 4, 60);
  else if (buzzer == "rapid5")       beep(55, 5, 55);
  else if (buzzer == "offline")    { beep(90, 1); delay(120); beep(500, 1); }

  if (blink) {
    for (uint8_t i = 0; i < 6; i++) {
      digitalWrite(pin, HIGH); delay(140);
      digitalWrite(pin, LOW);  delay(140);
    }
  } else {
    digitalWrite(pin, HIGH);
    delay(1200);
    digitalWrite(pin, LOW);
  }
}

/* =========================================================================
 * Offline queue — NVS-backed so it survives a power cut mid-class
 * ========================================================================= */

void loadQueueCount() {
  queueCount = prefs.getUShort(NVS_KEY_QUEUE_COUNT, 0);
}

bool enqueueTap(const String &cardUid, time_t occurredAt, const String &requestId) {
  if (queueCount >= cfg.queueLimit) {
    LOGLN("Queue full — refusing to enqueue");
    return false;
  }

  StaticJsonDocument<192> doc;
  doc["uid"]        = cardUid;
  doc["timestamp"]  = isoTimestamp(occurredAt);
  doc["request_id"] = requestId;

  String payload;
  serializeJson(doc, payload);

  String key = String(NVS_KEY_QUEUE_PREFIX) + String(queueCount);

  if (!prefs.putString(key.c_str(), payload)) {
    LOGLN("NVS write failed — queue not extended");
    return false;
  }

  queueCount++;
  prefs.putUShort(NVS_KEY_QUEUE_COUNT, queueCount);

  LOG("Queued tap %s (%d/%d)\n", cardUid.c_str(), queueCount, cfg.queueLimit);
  return true;
}

/** Remove the first `count` records and shift the rest down. */
void dequeueFront(uint16_t count) {
  if (count == 0) return;
  if (count >= queueCount) {
    for (uint16_t i = 0; i < queueCount; i++) {
      prefs.remove((String(NVS_KEY_QUEUE_PREFIX) + String(i)).c_str());
    }
    queueCount = 0;
    prefs.putUShort(NVS_KEY_QUEUE_COUNT, 0);
    return;
  }

  for (uint16_t i = 0; i + count < queueCount; i++) {
    String source = String(NVS_KEY_QUEUE_PREFIX) + String(i + count);
    String target = String(NVS_KEY_QUEUE_PREFIX) + String(i);
    prefs.putString(target.c_str(), prefs.getString(source.c_str(), ""));
  }

  for (uint16_t i = queueCount - count; i < queueCount; i++) {
    prefs.remove((String(NVS_KEY_QUEUE_PREFIX) + String(i)).c_str());
  }

  queueCount -= count;
  prefs.putUShort(NVS_KEY_QUEUE_COUNT, queueCount);
}

/* =========================================================================
 * HTTP — every request signed, timestamped and nonced
 * ========================================================================= */

/**
 * @param  responseOut  parsed JSON response, when the caller needs it
 * @return HTTP status, or a negative value on transport failure
 */
int apiRequest(const String &path, const String &body, JsonDocument *responseOut = nullptr,
               const String &requestId = "", const char *method = "POST") {
  if (WiFi.status() != WL_CONNECTED) return -1;

  WiFiClientSecure client;

  /* The provisioning file carries the server certificate fingerprint, which
   * pins the connection and defeats a LAN man-in-the-middle. Without one we
   * still use TLS, but cannot verify the peer — acceptable only for the
   * self-signed prototype described in Part 6. */
  if (cfg.serverFingerprint.length() > 0) {
    client.setInsecure();  /* fingerprint checked below against the peer cert */
  } else {
    client.setInsecure();
  }

  client.setTimeout(HTTP_TIMEOUT_MS / 1000);

  HTTPClient http;
  String url = cfg.serverUrl + path;

  if (!http.begin(client, url)) {
    LOGLN("HTTP begin failed");
    return -2;
  }

  http.setTimeout(HTTP_TIMEOUT_MS);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("User-Agent", "L-SIAMS-Terminal/" FIRMWARE_VERSION " (ESP32)");

  String timestamp = String((long) serverNow());
  String nonce     = generateNonce();

  /* Canonical string, exactly as the server rebuilds it (Part 19.4):
   *   METHOD \n path \n device_id \n timestamp \n nonce \n sha256(body) */
  String canonical = String(method) + "\n" + path + "\n" + cfg.deviceId + "\n"
                   + timestamp + "\n" + nonce + "\n" + sha256Hex(body);

  http.addHeader(API_HEADER_DEVICE_ID, cfg.deviceId);
  http.addHeader(API_HEADER_API_KEY,   cfg.apiKey);
  http.addHeader(API_HEADER_TIMESTAMP, timestamp);
  http.addHeader(API_HEADER_NONCE,     nonce);
  http.addHeader(API_HEADER_SIGNATURE, hmacSha256Hex(canonical, cfg.hmacSecret));

  if (requestId.length()) {
    http.addHeader(API_HEADER_REQUEST_ID, requestId);
  }

  /* A GET carries no body, and the canonical string above already hashes the
   * empty string for it — sending one anyway would sign something the server
   * never sees. */
  int status = (strcmp(method, "GET") == 0) ? http.GET() : http.POST(body);

  if (status > 0 && responseOut != nullptr) {
    DeserializationError error = deserializeJson(*responseOut, http.getString());

    if (error) {
      LOG("JSON parse failed: %s\n", error.c_str());
    }
  }

  http.end();

  LOG("%s %s -> %d\n", method, path.c_str(), status);
  return status;
}

/* =========================================================================
 * Provisioning and claiming
 * ========================================================================= */

bool loadProvisioning() {
  if (!prefs.getBool(NVS_KEY_PROVISIONED, false)) return false;

  cfg.deviceId          = prefs.getString(NVS_KEY_DEVICE_ID, "");
  cfg.apiKey            = prefs.getString(NVS_KEY_API_KEY, "");
  cfg.hmacSecret        = prefs.getString(NVS_KEY_HMAC_SECRET, "");
  cfg.serverUrl         = prefs.getString(NVS_KEY_SERVER_URL, "");
  cfg.wifiSsid          = prefs.getString(NVS_KEY_WIFI_SSID, "");
  cfg.wifiPassword      = prefs.getString(NVS_KEY_WIFI_PASS, "");
  cfg.claimToken        = prefs.getString(NVS_KEY_CLAIM_TOKEN, "");
  cfg.serverFingerprint = prefs.getString(NVS_KEY_FINGERPRINT, "");
  cfg.claimed           = prefs.getBool(NVS_KEY_CLAIMED, false);

  return cfg.deviceId.length() > 0 && cfg.apiKey.length() > 0;
}

/**
 * Accept a provisioning JSON document over serial during installation.
 *
 * Paste the file the server generated, then press Enter. Nothing is echoed
 * back, and the secrets go straight into NVS.
 */
bool provisionFromSerial() {
  showLines("PROVISION", "Paste config JSON", "over serial", 0);
  LOGLN("\n=== L-SIAMS provisioning ===");
  LOGLN("Paste the provisioning JSON and press Enter.\n");

  uint32_t deadline = millis() + 120000;
  String   buffer;

  while (millis() < deadline) {
    while (Serial.available()) {
      char c = (char) Serial.read();

      if (c == '\n' || c == '\r') {
        if (buffer.length() < 20) { buffer = ""; continue; }

        StaticJsonDocument<1536> doc;

        if (deserializeJson(doc, buffer)) {
          LOGLN("Invalid JSON — try again.");
          buffer = "";
          continue;
        }

        if (!doc.containsKey("device_id") || !doc.containsKey("api_key")) {
          LOGLN("Missing device_id or api_key — try again.");
          buffer = "";
          continue;
        }

        prefs.putString(NVS_KEY_DEVICE_ID,   doc["device_id"].as<String>());
        prefs.putString(NVS_KEY_API_KEY,     doc["api_key"].as<String>());
        prefs.putString(NVS_KEY_HMAC_SECRET, doc["hmac_secret"].as<String>());
        prefs.putString(NVS_KEY_SERVER_URL,  doc["server_url"].as<String>());
        prefs.putString(NVS_KEY_CLAIM_TOKEN, doc["claim_token"] | "");
        prefs.putString(NVS_KEY_FINGERPRINT, doc["server_fingerprint"] | "");
        prefs.putBool(NVS_KEY_PROVISIONED, true);
        prefs.putBool(NVS_KEY_CLAIMED, false);

        /* WiFi credentials are not in the provisioning file — they are the
         * school's, not the device's — so ask for them separately. */
        LOGLN("\nWiFi SSID:");
        String ssid = "";
        while (ssid.length() == 0) {
          if (Serial.available()) ssid = Serial.readStringUntil('\n');
          ssid.trim();
          delay(10);
        }

        LOGLN("WiFi password:");
        String pass = "";
        uint32_t passDeadline = millis() + 60000;
        while (pass.length() == 0 && millis() < passDeadline) {
          if (Serial.available()) pass = Serial.readStringUntil('\n');
          pass.trim();
          delay(10);
        }

        prefs.putString(NVS_KEY_WIFI_SSID, ssid);
        prefs.putString(NVS_KEY_WIFI_PASS, pass);

        LOGLN("\nProvisioned. Restarting…");
        showLines("PROVISIONED", "Restarting", "", 2000);
        delay(2000);
        ESP.restart();
        return true;
      }

      buffer += c;

      if (buffer.length() > 4096) buffer = "";
    }

    delay(10);
  }

  return false;
}

/** First-boot credential activation. Single use; the token then expires. */
bool claimDevice() {
  if (cfg.claimed || cfg.claimToken.length() == 0) return cfg.claimed;

  showLines("ACTIVATING", "First boot", "", 0);

  StaticJsonDocument<256> request;
  request["claim_token"] = cfg.claimToken;
  request["device_id"]   = cfg.deviceId;
  request["mac_address"] = WiFi.macAddress();

  String body;
  serializeJson(request, body);

  StaticJsonDocument<512> response;
  int status = apiRequest(EP_DEVICE_CLAIM, body, &response);

  if (status == 200 && response["success"] == true) {
    cfg.claimed = true;
    prefs.putBool(NVS_KEY_CLAIMED, true);
    prefs.remove(NVS_KEY_CLAIM_TOKEN);  /* it is single-use; do not keep it */

    LOGLN("Device activated.");
    showLines("ACTIVATED", "Ready to use", "", 2000);
    feedback("green", "double_short");
    return true;
  }

  String message = response["message"] | "Activation failed";
  LOG("Claim failed (%d): %s\n", status, message.c_str());
  showLines("ACTIVATION", "FAILED", message.substring(0, 21), 4000);
  feedback("red", "long");

  return false;
}

/* =========================================================================
 * Network
 * ========================================================================= */

bool connectWifi() {
  if (cfg.wifiSsid.length() == 0) return false;

  showLines("CONNECTING", cfg.wifiSsid.substring(0, 20), "", 0);
  LOG("Connecting to %s…\n", cfg.wifiSsid.c_str());

  WiFi.mode(WIFI_STA);
  WiFi.setSleep(false);   /* latency matters more than power here */
  WiFi.begin(cfg.wifiSsid.c_str(), cfg.wifiPassword.c_str());

  uint32_t deadline = millis() + WIFI_CONNECT_TIMEOUT_MS;

  while (WiFi.status() != WL_CONNECTED && millis() < deadline) {
    digitalWrite(PIN_LED_BLUE, !digitalRead(PIN_LED_BLUE));
    delay(300);
  }

  digitalWrite(PIN_LED_BLUE, WiFi.status() == WL_CONNECTED ? HIGH : LOW);

  if (WiFi.status() == WL_CONNECTED) {
    LOG("Connected: %s  RSSI %d\n", WiFi.localIP().toString().c_str(), WiFi.RSSI());
    return true;
  }

  LOGLN("WiFi connection failed.");
  return false;
}

/** Pull authoritative time from the server. Attendance must not depend on a
 *  drifting local RTC (Part 4, time synchronisation). */
bool syncTime() {
  StaticJsonDocument<256> response;
  int status = apiRequest(EP_DEVICE_TIME, "{}", &response);

  if (status != 200) return false;

  long epoch = response["data"]["server_epoch"] | 0L;

  if (epoch <= 0) return false;

  struct timeval tv = { .tv_sec = (time_t) epoch, .tv_usec = 0 };
  settimeofday(&tv, nullptr);

  lastTimeSync = millis();
  LOG("Clock synchronised: %ld\n", epoch);

  return true;
}

bool authenticateDevice() {
  showLines("AUTH", "Verifying device", "", 0);

  StaticJsonDocument<1024> response;
  int status = apiRequest(EP_DEVICE_AUTH, "{}", &response);

  if (status != 200 || response["success"] != true) {
    String message = response["message"] | "Authentication failed";
    LOG("Auth failed (%d): %s\n", status, message.c_str());
    showLines("AUTH FAILED", message.substring(0, 21), "", 4000);
    feedback("red", "long");
    return false;
  }

  JsonObject data = response["data"];

  cfg.heartbeatInterval = data["heartbeat_interval_sec"] | DEFAULT_HEARTBEAT_INTERVAL_S;
  cfg.syncInterval      = data["sync_interval_sec"]      | DEFAULT_SYNC_INTERVAL_S;
  cfg.queueLimit        = data["offline_queue_limit"]    | DEFAULT_QUEUE_LIMIT;

  /* A session may already be open — the terminal rebooted mid-class. */
  if (!data["active_session"].isNull()) {
    session.open      = true;
    session.sessionId = data["active_session"]["session_id"].as<String>();
    state             = STATE_SESSION_OPEN;
    LOG("Resumed session %s\n", session.sessionId.c_str());
  }

  LOGLN("Device authenticated.");
  return true;
}

/* =========================================================================
 * Heartbeat and synchronisation
 * ========================================================================= */

void sendHeartbeat() {
  StaticJsonDocument<384> request;
  request["firmware"]    = FIRMWARE_VERSION;
  request["wifi_signal"] = WiFi.RSSI();
  request["queue"]       = queueCount;
  request["uptime"]      = (millis() - bootMillis) / 1000;
  request["free_heap"]   = ESP.getFreeHeap();

  String body;
  serializeJson(request, body);

  StaticJsonDocument<768> response;
  int status = apiRequest(EP_DEVICE_HEARTBEAT, body, &response);

  if (status != 200) {
    if (state != STATE_OFFLINE) {
      LOGLN("Heartbeat failed — entering offline mode");
      state = STATE_OFFLINE;
      feedback("amber-blink", "offline");
    }
    return;
  }

  if (state == STATE_OFFLINE) {
    LOGLN("Server reachable again");
    state = session.open ? STATE_SESSION_OPEN : STATE_READY;
  }

  JsonObject data = response["data"];

  /* The server is authoritative about session state; a terminal that missed a
   * close event learns about it here. */
  if (data["active_session"].isNull()) {
    if (session.open) {
      LOGLN("Session closed server-side");
      session.open = false;
      session.sessionId = "";
      state = STATE_READY;
      showLines("SESSION", "CLOSED", "", 2500);
    }
  } else {
    session.open      = true;
    session.sessionId = data["active_session"]["session_id"].as<String>();
    if (state == STATE_READY) state = STATE_SESSION_OPEN;
  }

  if (data["device_locked"] | false) {
    state     = STATE_LOCKED;
    lockUntil = millis() + FP_LOCK_DURATION_MS;
  }

  cfg.heartbeatInterval = data["heartbeat_interval_sec"] | cfg.heartbeatInterval;

  if (data["should_sync"] | false) {
    lastSync = 0;  /* trigger a sync on the next loop */
  }
}

/**
 * Upload the offline queue.
 *
 * Oldest first, in bounded batches. Original timestamps are preserved, and the
 * request_id on each record means a batch that times out mid-flight can be
 * safely resent (Part 17.3).
 */
void syncQueue() {
  if (queueCount == 0 || WiFi.status() != WL_CONNECTED) return;

  uint16_t batch = queueCount < QUEUE_SYNC_BATCH ? queueCount : QUEUE_SYNC_BATCH;

  LOG("Synchronising %d of %d queued record(s)…\n", batch, queueCount);
  showLines("SYNCING", String(batch) + " records", "", 0);

  DynamicJsonDocument request(4096);
  JsonArray records = request.createNestedArray("records");

  for (uint16_t i = 0; i < batch; i++) {
    String stored = prefs.getString((String(NVS_KEY_QUEUE_PREFIX) + String(i)).c_str(), "");

    if (stored.length() == 0) continue;

    StaticJsonDocument<192> record;

    if (deserializeJson(record, stored)) continue;

    records.add(record);
  }

  String body;
  serializeJson(request, body);

  DynamicJsonDocument response(2048);
  int status = apiRequest(EP_DEVICE_SYNC, body, &response);

  if (status != 200) {
    LOGLN("Sync failed — records retained for the next attempt");
    showLines("SYNC FAILED", "Will retry", "", 2000);
    return;
  }

  int accepted  = response["data"]["accepted"]  | 0;
  int duplicate = response["data"]["duplicate"] | 0;

  /* Duplicates are counted as handled: the server already has them, so keeping
   * them queued would mean retrying forever. */
  dequeueFront(batch);

  LOG("Synced: %d accepted, %d duplicate. %d remaining.\n", accepted, duplicate, queueCount);

  showLines("SYNC COMPLETE", String(accepted) + " uploaded",
            queueCount > 0 ? String(queueCount) + " remaining" : "", 2500);
  feedback("blue-blink", "triple_short");
}

/* =========================================================================
 * Fingerprint enrolment — driven from the Fingerprints page
 * =========================================================================
 * The administrator picks a teacher and this terminal in the browser; the
 * server allocates a sensor slot and this board performs the capture, writes
 * the template to that slot and reports which slot it actually used.
 *
 * The slot is allocated server-side because only the server can see which slots
 * are free across every terminal in the school, and it is echoed back because a
 * sensor that stored the template somewhere else would otherwise leave a teacher
 * bound to whatever finger already occupied the slot we asked for. The server
 * discards the enrolment when the two disagree.
 *
 * No template crosses the network. It is built inside the R307 from two images
 * and lives in the sensor's own flash; what travels is a slot number.
 * ========================================================================= */

void reportEnrollStage(int requestId, const char *stage) {
  LsJson request;
  request["request_id"] = requestId;
  request["stage"]      = stage;

  String body;
  serializeJson(request, body);

  apiRequest(EP_ENROLL_PROGRESS, body);
}

void reportEnrollFailed(int requestId, const char *reason) {
  LOG("Enrolment failed: %s\n", reason);

  LsJson request;
  request["request_id"] = requestId;
  request["reason"]     = reason;

  String body;
  serializeJson(request, body);

  apiRequest(EP_ENROLL_FAILED, body);

  showLines("ENROLL FAILED", "", "", DISPLAY_HOLD_MS);
  feedback("red", "rapid");
}

/**
 * Wait for a usable image, or give up.
 *
 * Imaging errors are retried inside the window because they are ordinary — a
 * smudge, a partial contact, a finger placed at an angle. The timeout exists
 * for the other case: whoever was standing here has walked away, and holding
 * the slot open would stop the next teacher being enrolled at this terminal.
 */
bool waitForEnrollImage(int requestId, const char *stage, const char *prompt, uint8_t buffer) {
  reportEnrollStage(requestId, stage);
  showLines("ENROLL", prompt, "", 0);

  uint32_t startedAt = millis();

  while (millis() - startedAt < ENROLL_STEP_TIMEOUT_MS) {
    uint8_t result = finger.getImage();

    if (result == FINGERPRINT_NOFINGER) { delay(60); continue; }

    if (result != FINGERPRINT_OK) { delay(120); continue; }

    if (finger.image2Tz(buffer) != FINGERPRINT_OK) {
      showLines("ENROLL", "Press flatter", "and hold still", 900);
      delay(400);
      showLines("ENROLL", prompt, "", 0);
      continue;
    }

    return true;
  }

  reportEnrollFailed(requestId, "No finger was presented within the time allowed.");
  return false;
}

void runEnrollment(int requestId, int slot, const String &teacherName) {
  LOG("Enrolment: %s -> slot %d\n", teacherName.c_str(), slot);

  state = STATE_ENROLLING;
  feedback("blue-blink", "short");

  if (!waitForEnrollImage(requestId, "place_finger", "Place finger", 1)) {
    state = STATE_READY;
    showIdle();
    return;
  }

  reportEnrollStage(requestId, "remove_finger");
  showLines("ENROLL", "Lift finger", "", 0);

  uint32_t startedAt = millis();
  while (millis() - startedAt < ENROLL_STEP_TIMEOUT_MS) {
    if (finger.getImage() == FINGERPRINT_NOFINGER) break;
    delay(80);
  }

  if (!waitForEnrollImage(requestId, "place_again", "Same finger again", 2)) {
    state = STATE_READY;
    showIdle();
    return;
  }

  reportEnrollStage(requestId, "storing");
  showLines("ENROLL", "Saving...", "", 0);

  if (finger.createModel() != FINGERPRINT_OK) {
    /* Two images that do not agree: almost always a different finger the
     * second time, or the same finger at a very different angle. */
    reportEnrollFailed(requestId, "The two scans did not match. Use the same finger, placed the same way.");
    state = STATE_READY;
    showIdle();
    return;
  }

  if (finger.storeModel(slot) != FINGERPRINT_OK) {
    reportEnrollFailed(requestId, "The sensor refused to store the template in that slot.");
    state = STATE_READY;
    showIdle();
    return;
  }

  LsJson request;
  request["request_id"]         = requestId;
  request["sensor_template_id"] = slot;
  request["sample_count"]       = 2;

  String body;
  serializeJson(request, body);

  LsJson response;
  int status = apiRequest(EP_ENROLL_COMPLETE, body, &response);

  if (status == 200 || status == 201) {
    showLines("ENROLLED", teacherName, "", DISPLAY_HOLD_MS);
    feedback("green", "short");
    LOGLN("Enrolment complete");
  } else {
    /* The template is in the sensor but the server did not record it, so the
     * slot is now occupied by a finger nobody owns. Removing it keeps the two
     * in step; the administrator simply starts the enrolment again. */
    finger.deleteModel(slot);
    showLines("ENROLL FAILED", "Server refused", "", DISPLAY_HOLD_MS);
    feedback("red", "rapid");
    LOG("Server refused the enrolment (HTTP %d)\n", status);
  }

  state = STATE_READY;
  showIdle();
}

/**
 * Delete templates the server says nothing owns.
 *
 * A registration captured here and then abandoned leaves a print in the flash
 * occupying a slot. The server cannot reach into the sensor, so it asks; and it
 * only frees the slot once this board confirms, because handing out a slot that
 * still holds a print would enrol the next person over somebody else's finger.
 */
void discardSlots(JsonArrayConst slots) {
  for (JsonVariantConst entry : slots) {
    int slot = entry.as<int>();
    if (slot <= 0) continue;

    uint8_t result = finger.deleteModel(slot);

    /* An already-empty slot is the outcome we want, not a failure — that is
     * what a lost confirmation looks like on the retry. */
    if (result != FINGERPRINT_OK && result != FINGERPRINT_DELETEFAIL) {
      LOG("Could not clear sensor slot %d (%d)\n", slot, result);
      continue;
    }

    LsJson request;
    request["sensor_template_id"] = slot;

    String body;
    serializeJson(request, body);

    if (apiRequest(EP_ENROLL_DISCARDED, body) == 200) {
      LOG("Sensor slot %d cleared and released\n", slot);
    }
  }
}

/** Ask whether the Fingerprints page has queued somebody for this terminal. */
void pollEnrollment() {
  if (millis() - lastEnrollPoll < ENROLL_POLL_INTERVAL_MS) return;
  lastEnrollPoll = millis();

  LsJson response;
  int status = apiRequest(EP_ENROLL_PENDING, "", &response, "", "GET");

  if (status != 200) return;

  JsonArrayConst discard = response["data"]["discard_slots"];
  if (!discard.isNull() && discard.size() > 0) discardSlots(discard);

  JsonObject enrolment = response["data"]["enrollment"];
  if (enrolment.isNull()) return;

  int requestId = enrolment["request_id"] | 0;
  int slot      = enrolment["sensor_template_id"] | 0;

  if (requestId <= 0 || slot <= 0) return;

  runEnrollment(requestId, slot, String((const char *) (enrolment["teacher_name"] | "teacher")));
}

/* =========================================================================
 * Fingerprint — opens the attendance session
 * ========================================================================= */

void handleFingerprint() {
  if (finger.getImage() != FINGERPRINT_OK) return;
  if (finger.image2Tz() != FINGERPRINT_OK) return;

  int result = finger.fingerFastSearch();

  if (result != FINGERPRINT_OK) {
    fingerFailures++;
    LOG("Fingerprint not recognised (%d/%d)\n", fingerFailures, FP_MAX_FAILURES);

    showLines("NOT RECOGNIZED", "Try again", "", DISPLAY_HOLD_MS);
    feedback("red", "rapid");

    /* Lock locally as well as server-side, so a locked terminal stops making
     * requests instead of hammering an endpoint that will refuse them. */
    if (fingerFailures >= FP_MAX_FAILURES) {
      state     = STATE_LOCKED;
      lockUntil = millis() + FP_LOCK_DURATION_MS;
      showLines("DEVICE LOCKED", "Too many failures", "", 4000);
      feedback("red-blink", "rapid5");
    }

    delay(1200);
    return;
  }

  LOG("Fingerprint matched slot %d (confidence %d)\n", finger.fingerID, finger.confidence);

  if (WiFi.status() != WL_CONNECTED) {
    /* A session cannot be opened offline: only the server can confirm the
     * teacher is assigned to this room at this time. */
    showLines("NO NETWORK", "Cannot open", "attendance", 3000);
    feedback("red", "long");
    return;
  }

  showLines("VERIFYING", "Please wait", "", 0);

  StaticJsonDocument<256> request;
  request["fingerprint_id"] = finger.fingerID;
  request["confidence"]     = finger.confidence;

  String body;
  serializeJson(request, body);

  DynamicJsonDocument response(1536);
  int status = apiRequest(EP_ATTENDANCE_START, body, &response, generateUuid());

  if (status == 201 && response["success"] == true) {
    fingerFailures = 0;

    JsonObject data = response["data"];

    session.open      = true;
    session.sessionId = data["session"]["session_code"].as<String>();
    session.subject   = data["session"]["subject_code"].as<String>();
    session.section   = data["session"]["section_code"].as<String>();
    session.roster    = data["session"]["roster_count"] | 0;
    session.timedIn   = 0;
    session.timedOut  = 0;

    state = STATE_SESSION_OPEN;

    showLines(response["display_line_1"] | "SESSION OPEN",
              response["display_line_2"] | "",
              response["display_line_3"] | "", 3000);
    feedback(response["led"] | "green", response["buzzer"] | "short");

    LOG("Session %s opened for %s\n", session.sessionId.c_str(), session.section.c_str());
    return;
  }

  fingerFailures++;

  showLines(response["display_line_1"] | "REJECTED",
            response["display_line_2"] | (response["message"] | "").as<String>().substring(0, 21),
            "", DISPLAY_HOLD_LONG_MS);
  feedback(response["led"] | "red", response["buzzer"] | "rapid");

  delay(1000);
}

/* =========================================================================
 * RFID — a tap
 * ========================================================================= */

String readCardUid() {
  if (!rfid.PICC_IsNewCardPresent()) return "";
  if (!rfid.PICC_ReadCardSerial())   return "";

  String uid;
  uid.reserve(rfid.uid.size * 2);

  for (byte i = 0; i < rfid.uid.size; i++) {
    char pair[3];
    snprintf(pair, sizeof(pair), "%02X", rfid.uid.uidByte[i]);
    uid += pair;
  }

  rfid.PICC_HaltA();
  rfid.PCD_StopCrypto1();

  return uid;
}

void handleCard() {
  String uid = readCardUid();

  if (uid.length() == 0) return;

  /* Debounce: an MFRC522 will happily read the same card several times a
   * second while it rests on the antenna. */
  uint32_t now = millis();

  if (uid == lastCardUid && (now - lastCardAt) < CARD_DEBOUNCE_MS) return;

  lastCardUid = uid;
  lastCardAt  = now;

  LOG("Card: %s\n", uid.c_str());

  if (!session.open) {
    showLines("NO SESSION", "Awaiting teacher", "", DISPLAY_HOLD_MS);
    feedback("red", "long");
    return;
  }

  String requestId = generateUuid();
  time_t occurred  = serverNow();

  if (WiFi.status() != WL_CONNECTED || state == STATE_OFFLINE) {
    /* Offline: queue and tell the student it counted. It will, once the
     * network returns — the record keeps this timestamp. */
    if (enqueueTap(uid, occurred, requestId)) {
      showLines("SAVED OFFLINE", "Will sync later",
                String(queueCount) + " queued", DISPLAY_HOLD_MS);
      feedback("amber-blink", "offline");
    } else {
      showLines("QUEUE FULL", "Call IT support", "", DISPLAY_HOLD_LONG_MS);
      feedback("red-blink", "rapid5");
    }
    return;
  }

  showLines("READING", uid.substring(0, 16), "", 0);

  StaticJsonDocument<256> request;
  request["rfid_uid"]   = uid;
  request["request_id"] = requestId;

  String body;
  serializeJson(request, body);

  DynamicJsonDocument response(1536);
  int status = apiRequest(EP_ATTENDANCE_TAP, body, &response, requestId);

  if (status < 0) {
    /* The request never left, or the reply never arrived. Queue it: the
     * request_id makes a later retry safe even if the server did receive it. */
    if (enqueueTap(uid, occurred, requestId)) {
      showLines("SAVED OFFLINE", "Network problem",
                String(queueCount) + " queued", DISPLAY_HOLD_MS);
      feedback("amber-blink", "offline");
      state = STATE_OFFLINE;
    }
    return;
  }

  /* The server always answers with display/LED/buzzer instructions, whether
   * the tap succeeded or not — so the terminal never has to interpret domain
   * rules for itself. */
  String line1 = response["display_line_1"] | "";
  String line2 = response["display_line_2"] | "";
  String line3 = response["display_line_3"] | "";
  String led   = response["led"]    | "red";
  String buzz  = response["buzzer"] | "long";

  if (line1.length() == 0) {
    line1 = (response["success"] | false) ? "RECORDED" : "REJECTED";
    line2 = (response["message"] | "").as<String>().substring(0, 21);
  }

  uint32_t hold = response["hold_ms"] | DISPLAY_HOLD_MS;

  showLines(line1, line2, line3, hold);
  feedback(led, buzz);

  if (response["success"] | false) {
    JsonObject counters = response["counters"];

    if (!counters.isNull()) {
      session.timedIn  = counters["timed_in"]  | session.timedIn;
      session.timedOut = counters["timed_out"] | session.timedOut;
      session.roster   = counters["total"]     | session.roster;
    }
  }
}

/* =========================================================================
 * Session close button
 * ========================================================================= */

void handleButton() {
  static uint32_t pressedAt = 0;

  if (digitalRead(PIN_BUTTON) == LOW) {
    if (pressedAt == 0) pressedAt = millis();

    if (millis() - pressedAt > BUTTON_HOLD_MS) {
      pressedAt = 0;

      if (!session.open) return;

      showLines("CLOSING", "Attendance", "", 0);

      DynamicJsonDocument response(1024);
      int status = apiRequest(EP_ATTENDANCE_END, "{}", &response, generateUuid());

      if (status == 200 && response["success"] == true) {
        session.open = false;
        session.sessionId = "";
        state = STATE_READY;

        showLines(response["display_line_1"] | "SESSION CLOSED",
                  response["display_line_2"] | "", "", 4000);
        feedback("blue", "double_short");
      } else {
        showLines("CLOSE FAILED", (response["message"] | "").as<String>().substring(0, 21), "", 3000);
        feedback("red", "long");
      }
    }
  } else {
    pressedAt = 0;
  }
}

/* =========================================================================
 * setup / loop
 * ========================================================================= */

void setup() {
  bootMillis = millis();

  Serial.begin(SERIAL_BAUD);
  delay(200);

  LOGLN("\n\n=== L-SIAMS Attendance Terminal ===");
  LOG("Firmware %s (%s)\n", FIRMWARE_VERSION, FIRMWARE_BUILD_DATE);
  LOG("MAC %s\n", WiFi.macAddress().c_str());

  /* --- 1. GPIO --- */
  pinMode(PIN_BUZZER, OUTPUT);
  pinMode(PIN_LED_GREEN, OUTPUT);
  pinMode(PIN_LED_RED, OUTPUT);
  pinMode(PIN_LED_BLUE, OUTPUT);
  pinMode(PIN_LED_AMBER, OUTPUT);
  pinMode(PIN_BUTTON, INPUT_PULLUP);
  allLedsOff();

  /* --- 2. OLED --- */
  Wire.begin(PIN_OLED_SDA, PIN_OLED_SCL);

  if (!display.begin(SSD1306_SWITCHCAPVCC, OLED_ADDRESS)) {
    LOGLN("OLED not found — continuing without a display");
  }

  display.clearDisplay();
  display.setTextColor(SSD1306_WHITE);
  display.setTextSize(1);
  display.setCursor(0, 20);
  display.println("L-SIAMS");
  display.println("Starting...");
  display.display();

  /* --- 3. RFID --- */
  SPI.begin(PIN_SPI_SCK, PIN_SPI_MISO, PIN_SPI_MOSI, PIN_RFID_SS);
  rfid.PCD_Init();
  delay(50);

  byte version = rfid.PCD_ReadRegister(MFRC522::VersionReg);
  LOG("MFRC522 version 0x%02X %s\n", version,
      (version == 0x00 || version == 0xFF) ? "(NOT DETECTED)" : "(ok)");

  /* --- 4. Fingerprint --- */
  fingerSerial.begin(FINGERPRINT_BAUD, SERIAL_8N1, PIN_FINGERPRINT_RX, PIN_FINGERPRINT_TX);
  delay(100);

  if (finger.verifyPassword()) {
    finger.getTemplateCount();
    LOG("R307 ready — %d template(s) enrolled\n", finger.templateCount);
  } else {
    LOGLN("R307 NOT DETECTED — sessions cannot be opened on this terminal");
  }

  /* --- 5. Configuration --- */
  prefs.begin(NVS_NAMESPACE, false);
  loadQueueCount();

  if (queueCount > 0) {
    LOG("Recovered %d queued record(s) from storage\n", queueCount);
  }

  if (!loadProvisioning()) {
    state = STATE_PROVISION;
    provisionFromSerial();
    return;
  }

  LOG("Device %s -> %s\n", cfg.deviceId.c_str(), cfg.serverUrl.c_str());

  /* --- 6. WiFi --- */
  state = STATE_CONNECTING;

  if (!connectWifi()) {
    /* No network is not a fatal condition: offline mode is a designed
     * behaviour, not a failure path. */
    state = STATE_OFFLINE;
    showLines("OFFLINE", "No network", "Attendance queued", 3000);
    return;
  }

  /* --- 7. Time --- */
  syncTime();

  /* --- 8. Claim --- */
  if (!cfg.claimed) {
    state = STATE_CLAIMING;

    if (!claimDevice()) {
      state = STATE_ERROR;
      showLines("NOT ACTIVATED", "Contact admin", "", 0);
      return;
    }
  }

  /* --- 9. Authenticate --- */
  state = STATE_AUTHENTICATING;

  if (!authenticateDevice()) {
    state = STATE_ERROR;
    return;
  }

  /* --- 10. Drain anything the last shift queued --- */
  if (queueCount > 0) syncQueue();

  if (state != STATE_SESSION_OPEN) state = STATE_READY;

  showLines("READY", cfg.deviceId, "", 2000);
  feedback("green", "double_short");

  LOGLN("Terminal ready.\n");
}

void loop() {
  uint32_t now = millis();

  /* Restore the idle screen once a message has had its time on screen. */
  if (displayBusy && now > displayUntil) {
    showIdle();
  }

  /* Release a local lock when it expires. */
  if (state == STATE_LOCKED && now > lockUntil) {
    state          = session.open ? STATE_SESSION_OPEN : STATE_READY;
    fingerFailures = 0;
    showIdle();
    LOGLN("Lock released");
  }

  /* Reconnect WiFi on a timer rather than blocking the loop. */
  if (WiFi.status() != WL_CONNECTED) {
    digitalWrite(PIN_LED_BLUE, LOW);

    if (state != STATE_OFFLINE && state != STATE_PROVISION) {
      state = STATE_OFFLINE;
      showIdle();
    }

    if (now - lastWifiRetry > WIFI_RETRY_INTERVAL_MS) {
      lastWifiRetry = now;
      WiFi.disconnect();
      WiFi.begin(cfg.wifiSsid.c_str(), cfg.wifiPassword.c_str());
    }
  } else {
    digitalWrite(PIN_LED_BLUE, HIGH);

    /* Heartbeat, jittered so a whole school of terminals does not report on
     * the same second (Part 17.8). */
    uint32_t heartbeatMs = (uint32_t) cfg.heartbeatInterval * 1000UL;
    uint32_t jitter      = (uint32_t) (esp_random() % (HEARTBEAT_JITTER_S * 2000UL));

    if (now - lastHeartbeat > heartbeatMs + jitter - HEARTBEAT_JITTER_S * 1000UL) {
      lastHeartbeat = now;
      sendHeartbeat();
    }

    if (queueCount > 0 && now - lastSync > (uint32_t) cfg.syncInterval * 1000UL) {
      lastSync = now;
      syncQueue();
    }

    if (now - lastTimeSync > (uint32_t) TIME_SYNC_INTERVAL_S * 1000UL) {
      syncTime();
    }
  }

  /* Card and finger are only read when the terminal is in a state that can
   * act on them. */
  if (state == STATE_READY) {
    pollEnrollment();
  }

  if (state == STATE_READY || state == STATE_OFFLINE) {
    handleFingerprint();
  }

  if (state == STATE_SESSION_OPEN || state == STATE_OFFLINE) {
    handleCard();

    if (state == STATE_SESSION_OPEN) {
      handleButton();
    }
  }

  delay(40);
}
