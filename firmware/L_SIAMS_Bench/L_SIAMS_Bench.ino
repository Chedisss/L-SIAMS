/* ===========================================================================
 * L-SIAMS — bench terminal: RFID + fingerprint
 *
 * The full loop on one ESP32: a teacher's finger opens the attendance
 * session, a student's card records against it, and the server decides every
 * outcome. No display, no offline queue, no state machine — those belong to
 * the production firmware. This is the smallest sketch that produces real
 * attendance rows.
 *
 * Wiring — MFRC522 (SPI):
 *      SDA/SS -> GPIO 5      SCK -> GPIO 18     MOSI -> GPIO 23
 *      MISO   -> GPIO 19     RST -> GPIO 22
 *      3.3V   -> 3V3   (NEVER 5V; 5 V destroys this module)
 *      GND    -> GND
 *
 * Wiring — R307 fingerprint (UART2, 57600):
 *      TX  -> GPIO 16  (silkscreen RX2)   sensor transmits, ESP32 receives
 *      RX  -> GPIO 17  (silkscreen TX2)   ESP32 transmits, sensor receives
 *      VCC -> VIN (5V)     GND -> GND
 *      WAKEUP and the 3.3 V touch feed stay disconnected.
 *
 * Libraries, by the exact name Library Manager shows:
 *      "MFRC522" by GithubCommunity              (NOT MFRC522v2 - different API)
 *      "Adafruit Fingerprint Sensor Library" by Adafruit
 *      "ArduinoJson" by Benoit Blanchon          (6 or 7; both compile)
 * Board: ESP32 Dev Module.  Serial Monitor: 115200.
 * ======================================================================== */

#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <SPI.h>
#include <MFRC522.h>
#include <Adafruit_Fingerprint.h>
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
#include <sys/time.h>
#include <esp_system.h>
#include "mbedtls/md.h"

/* ---------------------------------------------------------------- config -- */
/* All six come from the provisioning JSON downloaded when the terminal was
 * registered. That download is the only copy of the key and secret that will
 * ever exist, and every download rotates them — so use one file, and do not
 * download again after pasting. */

static const char *WIFI_SSID   = "YOUR_WIFI_NAME";
static const char *WIFI_PASS   = "YOUR_WIFI_PASSWORD";

/* The host PC's LAN address WITH the port. Never localhost — to the ESP32
 * that means the ESP32. On the PC:
 *   (Get-NetIPConfiguration | Where-Object {$_.IPv4DefaultGateway -ne $null}).IPv4Address.IPAddress */
static const char *SERVER_URL  = "http://192.168.0.100:8080";

static const char *DEVICE_ID   = "DEV-2026-0001";
static const char *API_KEY     = "lsk_xxxxxxxx.yyyyyyyy";
static const char *HMAC_SECRET = "zzzzzzzzzzzzzzzz";

/* Leave CLAIM_TOKEN empty once the device is claimed. */
static const char *CLAIM_TOKEN = "";

/* The MAC is NOT configured here. The claim sends WiFi.macAddress() — the
 * address this board actually has — and the server checks it against the one
 * registered, refusing the claim if they differ.
 *
 * Reading it from the radio rather than from a constant is deliberate. A
 * constant is a second place for the same value to be wrong, and the resulting
 * CLAIM_IDENTITY_MISMATCH says nothing about which of the two copies is the
 * mistaken one. It is also the weaker check: the point of comparing MACs is to
 * prove a leaked provisioning file is being presented by the hardware it was
 * issued for, and a value the flasher types in proves nothing at all.
 *
 * The MAC to register is printed at boot, right below the IP. */

/* ------------------------------------------------------------------ pins -- */

#define PIN_RFID_SS        5

/* RST on GPIO 22, matching the board as actually wired.
 *
 * Worth knowing before the display goes on: config.h assigns GPIO 22 to
 * PIN_OLED_SCL, so the production firmware expects the reader's RST on GPIO
 * 27 and this pin for I2C. Two options when you get there — move RST back to
 * 27, or change PIN_OLED_SCL and PIN_RFID_RST in config.h to match this
 * board. Either is fine; leaving both on 22 is not. */
#define PIN_RFID_RST       22
#define PIN_FINGER_RX      16      /* silkscreen RX2 — sensor TX lands here */
#define PIN_FINGER_TX      17      /* silkscreen TX2 — sensor RX lands here */
#define FINGERPRINT_BAUD   57600

#define CARD_DEBOUNCE_MS       2500
#define HEARTBEAT_INTERVAL_MS  30000   /* server marks offline after 90 s */
#define READER_WATCH_MS        2000
#define FINGER_COOLDOWN_MS     1500

/* How often an idle terminal asks whether somebody has been queued for
 * enrolment on the Fingerprints page. Two seconds is what makes pressing
 * "Start scan" feel immediate to whoever is standing at the sensor; the board
 * has nothing else to do between taps, and the reply is a few hundred bytes.
 * Polling stops while a session is open — the sensor is busy then. */
#define ENROLL_POLL_MS         2000
#define ENROLL_STEP_TIMEOUT_MS 20000   /* per finger placement */

MFRC522        rfid(PIN_RFID_SS, PIN_RFID_RST);
HardwareSerial fingerSerial(2);
Adafruit_Fingerprint finger(&fingerSerial);

static String   lastUid;
static uint32_t lastTapAt         = 0;
static bool     clockSet          = false;
static String   lastDateHeader;
static uint32_t lastHeartbeatAt   = 0;
static bool     heartbeatLogged   = false;
static uint32_t lastReaderCheck   = 0;
static byte     lastReaderVersion = 0xEE;    /* neither 0x00 nor a real version */
static bool     fingerReady       = false;
static uint32_t lastFingerAt      = 0;
static bool     sessionOpen       = false;
static uint32_t lastEnrollPoll    = 0;
static bool     enrolling         = false;

/* --------------------------------------------------------------- helpers -- */

static String toHexLower(const uint8_t *data, size_t len) {
  String out;
  out.reserve(len * 2);
  for (size_t i = 0; i < len; i++) {
    char pair[3];
    snprintf(pair, sizeof(pair), "%02x", data[i]);
    out += pair;
  }
  return out;
}

/* SHA-256 and HMAC both go through the generic message-digest interface.
 * mbedtls_sha256()'s signature changed between mbedtls 2.x and 3.x, so the
 * direct call compiles on some ESP32 cores and not others; this spelling
 * works on both. Lowercase hex, because the server rebuilds the same string
 * with PHP's hash()/hash_hmac() and compares with hash_equals() — byte
 * exact, so uppercase fails exactly like a wrong key. */
static String sha256Hex(const String &data) {
  uint8_t digest[32];
  const mbedtls_md_info_t *info = mbedtls_md_info_from_type(MBEDTLS_MD_SHA256);
  mbedtls_md_context_t ctx;
  mbedtls_md_init(&ctx);
  mbedtls_md_setup(&ctx, info, 0);
  mbedtls_md_starts(&ctx);
  mbedtls_md_update(&ctx, (const unsigned char *) data.c_str(), data.length());
  mbedtls_md_finish(&ctx, digest);
  mbedtls_md_free(&ctx);
  return toHexLower(digest, sizeof(digest));
}

/* The secret is used as raw key bytes exactly as it appears in the
 * provisioning JSON: no base64 decode, no hex decode, no trimming. */
static String hmacSha256Hex(const String &message, const char *key) {
  uint8_t out[32];
  const mbedtls_md_info_t *info = mbedtls_md_info_from_type(MBEDTLS_MD_SHA256);
  mbedtls_md_context_t ctx;
  mbedtls_md_init(&ctx);
  mbedtls_md_setup(&ctx, info, 1);
  mbedtls_md_hmac_starts(&ctx, (const unsigned char *) key, strlen(key));
  mbedtls_md_hmac_update(&ctx, (const unsigned char *) message.c_str(), message.length());
  mbedtls_md_hmac_finish(&ctx, out);
  mbedtls_md_free(&ctx);
  return toHexLower(out, sizeof(out));
}

static String randomHex(size_t bytes) {
  String out;
  out.reserve(bytes * 2);
  for (size_t i = 0; i < bytes; i++) {
    char pair[3];
    snprintf(pair, sizeof(pair), "%02x", (uint8_t) (esp_random() & 0xFF));
    out += pair;
  }
  return out;
}

/* Days since 1970-01-01, by Howard Hinnant's algorithm. Written out rather
 * than using timegm() (missing from some ESP32 toolchains) or
 * strptime()+mktime() (depends on the process timezone, which is not UTC). */
static long daysFromCivil(long y, unsigned m, unsigned d) {
  y -= (m <= 2);
  const long     era = (y >= 0 ? y : y - 399) / 400;
  const unsigned yoe = (unsigned) (y - era * 400);
  const unsigned doy = (153u * (m + (m > 2 ? -3 : 9)) + 2) / 5 + d - 1;
  const unsigned doe = yoe * 365 + yoe / 4 - yoe / 100 + doy;
  return era * 146097L + (long) doe - 719468L;
}

/* RFC 7231 date: "Sat, 08 Aug 2026 14:42:16 GMT". Always GMT by spec. */
static time_t parseHttpDate(const String &value) {
  char monthName[4] = {0};
  int  day = 0, year = 0, hour = 0, minute = 0, second = 0;
  int comma = value.indexOf(',');
  String rest = (comma >= 0) ? value.substring(comma + 1) : value;
  rest.trim();
  if (sscanf(rest.c_str(), "%d %3s %d %d:%d:%d",
             &day, monthName, &year, &hour, &minute, &second) != 6) return 0;
  static const char *months = "JanFebMarAprMayJunJulAugSepOctNovDec";
  const char *found = strstr(months, monthName);
  if (found == nullptr) return 0;
  unsigned month = (unsigned) ((found - months) / 3) + 1;
  return (time_t) (daysFromCivil(year, month, (unsigned) day) * 86400L
                   + hour * 3600L + minute * 60L + second);
}

/* Version-4 UUID. The server validates the shape and uses it to make a tap
 * idempotent: the same request_id replayed never records attendance twice. */
static String generateUuid() {
  uint8_t b[16];
  for (int i = 0; i < 16; i++) b[i] = (uint8_t) (esp_random() & 0xFF);
  b[6] = (b[6] & 0x0F) | 0x40;
  b[8] = (b[8] & 0x3F) | 0x80;
  char buf[37];
  snprintf(buf, sizeof(buf),
           "%02x%02x%02x%02x-%02x%02x-%02x%02x-%02x%02x-%02x%02x%02x%02x%02x%02x",
           b[0], b[1], b[2],  b[3],  b[4],  b[5],  b[6],  b[7],
           b[8], b[9], b[10], b[11], b[12], b[13], b[14], b[15]);
  return String(buf);
}

/* ------------------------------------------------------------- transport -- */

/**
 * One signed request. The server rebuilds this canonical string byte for byte
 * before checking the signature (ApiKeyService::canonicalString):
 *
 *     METHOD \n path \n device_id \n timestamp \n nonce \n sha256_hex(body)
 *
 * `path` is the path alone — no scheme, no host, no query. Any one of those
 * six fields being wrong gives SIGNATURE_INVALID with no hint as to which, so
 * they are assembled in exactly one place, here.
 */
static int signedRequest(const char *method, const String &path, const String &body,
                         JsonDocument *responseOut, const String &requestId = "") {
  if (WiFi.status() != WL_CONNECTED) return -1;

  String url  = String(SERVER_URL) + path;
  bool  isTls = url.startsWith("https://");

  WiFiClient       plain;
  WiFiClientSecure secure;
  HTTPClient http;

  if (isTls) {
    secure.setInsecure();
    if (!http.begin(secure, url)) return -2;
  } else {
    if (!http.begin(plain, url)) return -2;
  }

  http.setTimeout(8000);
  http.addHeader("Content-Type", "application/json");

  /* Declared before the request or HTTPClient discards it. This is the clock
   * bootstrap: Date arrives even on the 401 that rejects a bad timestamp. */
  static const char *wanted[] = { "Date" };
  http.collectHeaders(wanted, 1);

  String timestamp = String((long) time(nullptr));
  String nonce     = randomHex(16);
  String canonical = String(method) + "\n" + path + "\n" + DEVICE_ID + "\n"
                   + timestamp + "\n" + nonce + "\n" + sha256Hex(body);

  http.addHeader("X-LSIAMS-Device-Id", DEVICE_ID);
  http.addHeader("X-LSIAMS-Api-Key",   API_KEY);
  http.addHeader("X-LSIAMS-Timestamp", timestamp);
  http.addHeader("X-LSIAMS-Nonce",     nonce);
  http.addHeader("X-LSIAMS-Signature", hmacSha256Hex(canonical, HMAC_SECRET));
  if (requestId.length()) http.addHeader("X-LSIAMS-Request-Id", requestId);

  int status = (strcmp(method, "GET") == 0) ? http.GET() : http.POST(body);

  if (status > 0) {
    lastDateHeader = http.header("Date");
    if (responseOut != nullptr) deserializeJson(*responseOut, http.getString());
  }

  http.end();
  return status;
}

/** Unsigned POST — only the claim call, which runs before this device has
 *  anything to sign with and before the clock is set. */
static int unsignedPost(const String &path, const String &body, JsonDocument *responseOut) {
  if (WiFi.status() != WL_CONNECTED) return -1;

  String url  = String(SERVER_URL) + path;
  bool  isTls = url.startsWith("https://");

  WiFiClient       plain;
  WiFiClientSecure secure;
  HTTPClient http;

  if (isTls) {
    secure.setInsecure();
    if (!http.begin(secure, url)) return -2;
  } else {
    if (!http.begin(plain, url)) return -2;
  }

  http.setTimeout(8000);
  http.addHeader("Content-Type", "application/json");
  static const char *wanted[] = { "Date" };
  http.collectHeaders(wanted, 1);

  int status = http.POST(body);

  if (status > 0) {
    lastDateHeader = http.header("Date");
    if (responseOut != nullptr) deserializeJson(*responseOut, http.getString());
  }

  http.end();
  return status;
}

/* ------------------------------------------------------------ activation -- */

/**
 * A freshly registered terminal is `unclaimed`, and DeviceAuthMiddleware
 * refuses every signed request from one — including the clock sync. The claim
 * endpoint is the one device route with no signature requirement.
 *
 * CLAIM_TOKEN_USED means an earlier boot already claimed it, which is the
 * state we want, so it counts as success.
 */
static bool claimDevice() {
  if (strlen(CLAIM_TOKEN) == 0) {
    Serial.println("  no claim token set — skipping (fine if already claimed)");
    return true;
  }

  LsJson request;
  request["claim_token"] = CLAIM_TOKEN;
  request["device_id"]   = DEVICE_ID;
  request["mac_address"] = WiFi.macAddress();

  String body;
  serializeJson(request, body);

  LsJson response;
  int status = unsignedPost("/api/device/claim", body, &response);
  const char *code = response["code"] | "";

  if (status == 200 || status == 201) {
    Serial.println("  device claimed — now active");
    return true;
  }

  if (strcmp(code, "CLAIM_TOKEN_USED") == 0) {
    Serial.println("  already claimed on an earlier boot (fine)");
    return true;
  }

  Serial.printf("  claim FAILED (HTTP %d, %s): %s\n",
                status, code, (const char *) (response["message"] | ""));

  if (strcmp(code, "CLAIM_IDENTITY_MISMATCH") == 0) {
    Serial.println("  -> the registration does not match this board. Compare both:");
    Serial.printf("       DEVICE_ID in this sketch : %s\n", DEVICE_ID);
    Serial.printf("       this board's MAC         : %s\n", WiFi.macAddress().c_str());
    Serial.println("     against the device page in L-SIAMS, or run: console.bat doctor");
    Serial.println("     A terminal that has never claimed can have its MAC corrected");
    Serial.println("     there; one that has already claimed cannot, by design.");
  }

  return false;
}

/* ----------------------------------------------------------------- clock -- */

static void setClock(time_t epoch) {
  struct timeval tv;
  tv.tv_sec  = epoch;
  tv.tv_usec = 0;
  settimeofday(&tv, nullptr);
  clockSet = true;
}

/**
 * Signed requests must land within 30 seconds of server time, and an ESP32
 * boots believing it is 1970 — so the first request is unsignable, and NTP is
 * not available on a LAN with no route to the internet.
 *
 * The signed call is tried first and gives the exact epoch when the clock is
 * already close. When it is not, the 401 still carries an HTTP Date header,
 * which is good to the second and enough to make the retry succeed.
 */
static bool syncClockFromServer() {
  LsJson response;
  int status = signedRequest("GET", "/api/device/time", "", &response);

  if (status == 200) {
    long epoch = response["data"]["server_epoch"] | 0L;
    if (epoch > 0) {
      setClock((time_t) epoch);
      Serial.printf("  clock set from /api/device/time: %ld\n", epoch);
      return true;
    }
  }

  time_t fromHeader = lastDateHeader.length() ? parseHttpDate(lastDateHeader) : 0;

  if (fromHeader <= 0) {
    Serial.printf("  clock sync failed (HTTP %d, code %s, no usable Date header)\n",
                  status, (const char *) (response["code"] | "-"));
    return false;
  }

  setClock(fromHeader);
  Serial.printf("  clock set from HTTP Date: %ld\n", (long) fromHeader);

  status = signedRequest("GET", "/api/device/time", "", &response);
  if (status == 200) {
    long epoch = response["data"]["server_epoch"] | 0L;
    if (epoch > 0) {
      setClock((time_t) epoch);
      Serial.printf("  refined from /api/device/time: %ld\n", epoch);
    }
  }

  return true;
}

/* ------------------------------------------------------------- heartbeat -- */

/**
 * Without this the dashboard shows Offline for ever with "never sent a
 * heartbeat", even while the device is claimed and signing correctly — the
 * status column is driven by last_heartbeat_at and nothing else. The worker
 * flips a terminal offline 90 seconds after the last one.
 *
 * Only the first success is logged; a line every thirty seconds would bury
 * the taps this sketch exists to show.
 */
static void sendHeartbeat() {
  if (!clockSet) return;

  LsJson request;
  request["firmware"]    = "1.0.0-bench";
  request["wifi_signal"] = WiFi.RSSI();
  request["queue"]       = 0;
  request["uptime"]      = (int) (millis() / 1000);
  request["free_heap"]   = (int) ESP.getFreeHeap();

  String body;
  serializeJson(request, body);

  LsJson response;
  int status = signedRequest("POST", "/api/device/heartbeat", body, &response);

  if (status == 200 || status == 201) {
    if (!heartbeatLogged) {
      Serial.println("Heartbeat accepted — the dashboard should show Online.");
      heartbeatLogged = true;
    }
    return;
  }

  Serial.printf("Heartbeat failed (HTTP %d, %s)\n",
                status, (const char *) (response["code"] | "-"));
  heartbeatLogged = false;
}

/* ---------------------------------------------------------- reader watch -- */

/**
 * 0x00 means the SPI read came back as all-zero bits — the module is not
 * answering, which is wiring or power rather than code. Polling it means a
 * reseated wire shows up within two seconds instead of needing a reflash.
 */
static void watchReader() {
  if (millis() - lastReaderCheck < READER_WATCH_MS) return;
  lastReaderCheck = millis();

  byte version = rfid.PCD_ReadRegister(MFRC522::VersionReg);
  if (version == lastReaderVersion) return;
  lastReaderVersion = version;

  if (version == 0x00 || version == 0xFF) {
    Serial.printf("READER: 0x%02X — not responding.\n", version);
    Serial.println("  MISO -> GPIO 19, MOSI -> GPIO 23, SCK -> GPIO 18,");
    Serial.println("  SDA/SS -> GPIO 5, RST -> GPIO 22, and 3.3V (never 5V).");
    return;
  }

  Serial.printf("READER: 0x%02X — responding.\n", version);
  rfid.PCD_Init();
  rfid.PCD_AntennaOn();
}

/* ------------------------------------------------------------- enrolment -- */

/**
 * Enrolment, driven from the Fingerprints page.
 *
 * The old way was to flash the Adafruit `enroll` example, read a slot number
 * off the serial monitor and type it into a form. Nothing tied the two halves
 * together, so a typo bound a teacher to somebody else's finger and nothing
 * anywhere would have noticed.
 *
 * Here the server allocates the slot — it is the only party that can see which
 * slots are free across the whole school — and this board writes the template
 * to exactly that slot and reports back which slot it actually used. The server
 * refuses the result if those two disagree.
 *
 * Still no template on the wire: it is built inside the R307 from two images
 * and stored in the sensor's own flash. What crosses the network is a slot
 * number and a quality score.
 */
static void reportEnrollStage(int requestId, const char *stage) {
  LsJson request;
  request["request_id"] = requestId;
  request["stage"]      = stage;

  String body;
  serializeJson(request, body);

  LsJson response;
  signedRequest("POST", "/api/fingerprint/enrollment/progress", body, &response);
}

static void reportEnrollFailed(int requestId, const char *reason) {
  Serial.printf("Enrol: FAILED — %s\n", reason);

  LsJson request;
  request["request_id"] = requestId;
  request["reason"]     = reason;

  String body;
  serializeJson(request, body);

  LsJson response;
  signedRequest("POST", "/api/fingerprint/enrollment/failed", body, &response);
}

/**
 * Block until a finger is on the sensor, or the step times out.
 *
 * A person who has walked away is the common case, not an error to retry
 * forever: holding the slot open would stop the next teacher being enrolled at
 * this terminal at all.
 */
static bool waitForFinger(int requestId, const char *stage, uint8_t buffer) {
  reportEnrollStage(requestId, stage);
  Serial.printf("Enrol: %s\n", stage);

  uint32_t startedAt = millis();

  while (millis() - startedAt < ENROLL_STEP_TIMEOUT_MS) {
    uint8_t result = finger.getImage();

    if (result == FINGERPRINT_NOFINGER) { delay(60); continue; }

    if (result != FINGERPRINT_OK) {
      /* Imaging errors are transient — a smudge, a partial contact. Retrying
       * inside the window is what a person expects; failing the whole
       * enrolment on the first bad frame is not. */
      delay(120);
      continue;
    }

    if (finger.image2Tz(buffer) != FINGERPRINT_OK) {
      Serial.println("  print not clear enough — press flatter and hold still");
      delay(400);
      continue;
    }

    return true;
  }

  reportEnrollFailed(requestId, "No finger was presented within the time allowed.");
  return false;
}

static void waitForFingerRemoved(int requestId) {
  reportEnrollStage(requestId, "remove_finger");
  Serial.println("Enrol: lift the finger off");

  uint32_t startedAt = millis();

  while (millis() - startedAt < ENROLL_STEP_TIMEOUT_MS) {
    if (finger.getImage() == FINGERPRINT_NOFINGER) return;
    delay(80);
  }
}

static void runEnrollment(int requestId, int slot, const char *teacherName) {
  Serial.println();
  Serial.printf("=== ENROLMENT: %s -> sensor slot %d ===\n", teacherName, slot);

  enrolling = true;

  if (!waitForFinger(requestId, "place_finger", 1)) { enrolling = false; return; }

  waitForFingerRemoved(requestId);

  if (!waitForFinger(requestId, "place_again", 2)) { enrolling = false; return; }

  reportEnrollStage(requestId, "storing");

  if (finger.createModel() != FINGERPRINT_OK) {
    /* Two images that do not agree. Almost always a different finger the
     * second time, or the same finger at a very different angle. */
    reportEnrollFailed(requestId, "The two scans did not match. Use the same finger, placed the same way.");
    enrolling = false;
    return;
  }

  if (finger.storeModel(slot) != FINGERPRINT_OK) {
    reportEnrollFailed(requestId, "The sensor refused to store the template in that slot.");
    enrolling = false;
    return;
  }

  /* Read back what is actually in the sensor rather than trusting the write.
   * The server compares this against the slot it asked for and discards the
   * enrolment if they differ, so a sensor that silently relocated the template
   * cannot leave a teacher bound to the wrong finger. */
  finger.getTemplateCount();

  LsJson request;
  request["request_id"]         = requestId;
  request["sensor_template_id"] = slot;
  request["sample_count"]       = 2;

  String body;
  serializeJson(request, body);

  LsJson response;
  int status = signedRequest("POST", "/api/fingerprint/enrollment/complete", body, &response);

  if (status == 200 || status == 201) {
    Serial.printf("Enrol: DONE — %s is enrolled in slot %d\n", teacherName, slot);
    Serial.printf("       sensor now holds %d template(s)\n", finger.templateCount);
  } else {
    /* The template is in the sensor but the server did not record it, so the
     * slot now holds a finger nobody owns. Removing it keeps the two in step;
     * the administrator just starts the enrolment again. */
    finger.deleteModel(slot);
    Serial.printf("Enrol: the server refused the result (HTTP %d, %s)\n",
                  status, (const char *) (response["code"] | "-"));
    Serial.println("       the template was removed from the sensor again.");
  }

  enrolling = false;
}

/**
 * Delete templates the server says nothing owns.
 *
 * A registration captured at this sensor and then abandoned leaves a print in
 * the flash occupying a slot. The server cannot reach into the sensor, so it
 * asks; and it only frees the slot once this board confirms the delete, because
 * handing out a slot that still holds a print would enrol the next person right
 * over the top of somebody else's finger.
 */
static void discardSlots(JsonArrayConst slots) {
  for (JsonVariantConst entry : slots) {
    int slot = entry.as<int>();
    if (slot <= 0) continue;

    uint8_t result = finger.deleteModel(slot);

    /* A slot that is already empty is the outcome we want, not a failure —
     * it happens whenever a confirmation was lost on the way back. */
    if (result != FINGERPRINT_OK && result != FINGERPRINT_DELETEFAIL) {
      Serial.printf("Enrol: could not clear slot %d (sensor said %d)\n", slot, result);
      continue;
    }

    LsJson request;
    request["sensor_template_id"] = slot;

    String body;
    serializeJson(request, body);

    LsJson response;
    int status = signedRequest("POST", "/api/fingerprint/enrollment/discarded", body, &response);

    if (status == 200 || status == 201) {
      Serial.printf("Enrol: slot %d cleared and released.\n", slot);
    }
  }
}

/** Ask whether the Fingerprints page has queued somebody for this terminal. */
/**
 * Say why enrolment is not being picked up.
 *
 * Every reason this board had for skipping a poll used to be a bare return,
 * while the web page sat on "Waiting for the terminal to pick this up…" —
 * true, and useless. The board knows exactly why it is not picking anything
 * up; it just was not saying. Announced once when a reason starts and once
 * when it clears, so the Serial Monitor does not fill with it.
 *
 * Compared by pointer, which is why every caller passes a string literal.
 */
static void announceEnrollSkip(const char *reason) {
  static const char *last = nullptr;

  if (reason == last) return;

  last = reason;

  if (reason != nullptr) {
    Serial.printf("\nENROLL: not polling — %s\n", reason);
  } else {
    Serial.println("\nENROLL: polling for enrolment requests again.");
  }
}

static void pollEnrollment() {
  if (!fingerReady) {
    announceEnrollSkip("the fingerprint sensor did not start");
    return;
  }

  /* A finger can still be matched against templates already in the sensor's
   * flash without any of this — matching is local to the R307. So verification
   * keeps working and printing while enrolment is dead, which is exactly how
   * this failure hides. */
  if (!clockSet) {
    announceEnrollSkip("the clock is not synced, so requests cannot be signed");
    return;
  }

  if (enrolling) return;  // normal and brief; not worth announcing

  /* The sensor cannot verify a teacher and enrol another at the same time, and
   * an open session means it is in use. */
  if (sessionOpen) {
    announceEnrollSkip("an attendance session is open on this terminal");
    return;
  }

  if (millis() - lastEnrollPoll < ENROLL_POLL_MS) return;
  lastEnrollPoll = millis();

  LsJson response;
  int status = signedRequest("GET", "/api/fingerprint/enrollment", "", &response);

  if (status != 200) {
    /* Rate-limited rather than announced once: unlike the conditions above,
     * this one can change from request to request. */
    static uint32_t lastComplaintAt = 0;

    if (lastComplaintAt == 0 || millis() - lastComplaintAt > 15000) {
      lastComplaintAt = millis();

      const char *code = response["code"] | "";
      Serial.printf("\nENROLL: the server refused the poll (HTTP %d%s%s)\n",
                    status, code[0] ? ", " : "", code);

      if (status == 403) {
        Serial.println("  403 here is almost always the terminal's IP allowlist. This board");
        Serial.printf("  is on %s. Clear the IP allowlist on the\n", WiFi.localIP().toString().c_str());
        Serial.println("  device's Edit page unless you set it deliberately — the router");
        Serial.println("  hands out a different address sooner or later.");
      } else if (status == 401) {
        Serial.println("  401 means the API key and HMAC secret do not match the pair the");
        Serial.println("  server holds. Download the provisioning file ONCE and copy all");
        Serial.println("  four values out of that same file — each download replaces the last.");
      } else if (status < 0) {
        Serial.println("  A negative number is not an HTTP status: the board could not open");
        Serial.printf("  a connection to %s at all.\n", SERVER_URL);
      }
    }

    return;
  }

  announceEnrollSkip(nullptr);

  JsonArrayConst discard = response["data"]["discard_slots"];
  if (!discard.isNull() && discard.size() > 0) discardSlots(discard);

  JsonObject enrolment = response["data"]["enrollment"];
  if (enrolment.isNull()) return;

  int requestId = enrolment["request_id"] | 0;
  int slot      = enrolment["sensor_template_id"] | 0;

  if (requestId <= 0 || slot <= 0) return;

  const char *name = enrolment["teacher_name"] | "teacher";

  runEnrollment(requestId, slot, name);
}

/* ----------------------------------------------------------- fingerprint -- */

/**
 * The finger opens the session; nothing else does.
 *
 * Matching happens on the sensor — only a slot number and a confidence score
 * cross the wire. The server then checks that this teacher is scheduled for
 * THIS classroom right now, so a verified finger with no matching schedule is
 * still refused. That check is the real authorisation step.
 */
static void handleFingerprint() {
  if (!fingerReady) return;
  if (millis() - lastFingerAt < FINGER_COOLDOWN_MS) return;

  if (finger.getImage() != FINGERPRINT_OK) return;

  lastFingerAt = millis();

  if (finger.image2Tz() != FINGERPRINT_OK) {
    Serial.println("\nFinger: could not read the print — try again, flatter.");
    return;
  }

  if (finger.fingerFastSearch() != FINGERPRINT_OK) {
    Serial.println("\nFinger: NOT RECOGNISED (no matching template on this sensor)");
    return;
  }

  Serial.printf("\nFinger: matched slot %d (confidence %d)\n",
                finger.fingerID, finger.confidence);

  if (!clockSet && !syncClockFromServer()) {
    Serial.println("  no clock — cannot sign the request");
    return;
  }

  LsJson request;
  request["fingerprint_id"] = finger.fingerID;
  request["confidence"]     = finger.confidence;

  String body;
  serializeJson(request, body);

  LsJson response;
  int status = signedRequest("POST", "/api/attendance/start", body, &response, generateUuid());

  Serial.printf("  HTTP %d  %s\n", status, (const char *) (response["code"] | "-"));

  if (status == 201) {
    sessionOpen = true;
    Serial.printf("  SESSION OPEN — %s / %s, roster %d\n",
                  (const char *) (response["data"]["session"]["subject_code"] | "?"),
                  (const char *) (response["data"]["session"]["section_code"] | "?"),
                  (int) (response["data"]["session"]["roster_count"] | 0));
    Serial.println("  Now tap a student card.");
    return;
  }

  Serial.printf("  refused: %s\n", (const char *) (response["message"] | ""));

  const char *code = response["code"] | "";
  if (strcmp(code, "FINGERPRINT_UNKNOWN") == 0)
    Serial.println("  -> slot not enrolled in L-SIAMS (Fingerprints -> Enrol Fingerprint)");
  if (strcmp(code, "NO_SCHEDULE") == 0 || strcmp(code, "NOT_SCHEDULED") == 0)
    Serial.println("  -> this teacher has no class in this room at this time");
}

/* ------------------------------------------------------------------ card -- */

static String readCardUid() {
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

static void sendTap(const String &uid) {
  LsJson request;
  request["rfid_uid"] = uid;

  String requestId = generateUuid();
  request["request_id"] = requestId;

  String body;
  serializeJson(request, body);

  LsJson response;
  int status = signedRequest("POST", "/api/attendance/tap", body, &response, requestId);

  /* One retry after a clock correction. A device powered off for a while
   * drifts, and re-reading the epoch is cheaper than failing a real tap. */
  if (response["code"] == "TIMESTAMP_EXPIRED") {
    Serial.println("  timestamp rejected — resyncing clock and retrying");
    if (syncClockFromServer()) {
      status = signedRequest("POST", "/api/attendance/tap", body, &response, requestId);
    }
  }

  Serial.printf("  HTTP %d  %s\n", status, (const char *) (response["code"] | "-"));

  const char *line1 = response["display_line_1"] | "";
  const char *line2 = response["display_line_2"] | "";
  if (strlen(line1)) Serial.printf("  DISPLAY: %s / %s\n", line1, line2);

  if (status == 201) {
    Serial.printf("  RECORDED: %s\n", (const char *) (response["message"] | ""));
  } else if (strcmp(response["code"] | "", "SESSION_NOT_OPEN") == 0) {
    sessionOpen = false;
    Serial.println("  -> a teacher must scan their finger first");
  }
}

/* ----------------------------------------------------------- diagnostics -- */

static void diagnoseWifi() {
  Serial.println("Wi-Fi FAILED.");
  Serial.printf("  WiFi.status() = %d ", (int) WiFi.status());

  switch (WiFi.status()) {
    case WL_NO_SSID_AVAIL:   Serial.println("(network not found)"); break;
    case WL_CONNECT_FAILED:  Serial.println("(rejected — usually the password)"); break;
    case WL_CONNECTION_LOST: Serial.println("(connection lost)"); break;
    case WL_DISCONNECTED:    Serial.println("(disconnected)"); break;
    default:                 Serial.println(); break;
  }

  Serial.println("  Scanning to see what this board can actually reach...");
  WiFi.disconnect();
  delay(100);

  int found = WiFi.scanNetworks();
  if (found <= 0) {
    Serial.println("  No networks at all — nothing here is 2.4 GHz and in range.");
    return;
  }

  bool nameMatched = false;
  Serial.printf("  %d network(s) visible:\n", found);

  for (int i = 0; i < found; i++) {
    bool isTarget = (WiFi.SSID(i) == WIFI_SSID);
    if (isTarget) nameMatched = true;
    Serial.printf("    %-32s ch%-3d %4d dBm%s\n",
                  WiFi.SSID(i).c_str(), WiFi.channel(i), WiFi.RSSI(i),
                  isTarget ? "   <-- this is WIFI_SSID" : "");
  }

  Serial.println();
  if (!nameMatched) {
    Serial.printf("  \"%s\" is not in that list — it is 5 GHz (the ESP32 has no\n", WIFI_SSID);
    Serial.println("  5 GHz radio) or the name differs. Copy it from the list above.");
    Serial.println("  iPhone: Personal Hotspot -> Maximise Compatibility.");
    Serial.println("  Android: Hotspot -> AP Band -> 2.4 GHz.");
    return;
  }

  Serial.println("  The name matches, so it is reachable and 2.4 GHz.");
  Serial.println("  That leaves the password — check case, and l/1/I and O/0.");
}

/* ----------------------------------------------------------------- setup -- */

void setup() {
  Serial.begin(115200);
  delay(400);

  Serial.println();
  Serial.println("L-SIAMS bench terminal — RFID + fingerprint");
  Serial.println("------------------------------------------");

  /* ---- RFID ---- */
  SPI.begin();
  rfid.PCD_Init();
  byte version = rfid.PCD_ReadRegister(MFRC522::VersionReg);
  Serial.printf("MFRC522 version: 0x%02X %s\n", version,
                (version == 0x00 || version == 0xFF)
                  ? "<-- NOT RESPONDING, check wiring (and that it is on 3.3V)"
                  : "(ok)");

  /* ---- Fingerprint ---- */
  fingerSerial.begin(FINGERPRINT_BAUD, SERIAL_8N1, PIN_FINGER_RX, PIN_FINGER_TX);
  delay(100);

  if (finger.verifyPassword()) {
    finger.getTemplateCount();
    fingerReady = true;
    Serial.printf("R307: found — %d template(s) enrolled on this sensor\n",
                  finger.templateCount);
    if (finger.templateCount == 0) {
      Serial.println("  none enrolled yet — that is fine. Open Fingerprints in");
      Serial.println("  L-SIAMS, press Enrol Fingerprint, choose this terminal,");
      Serial.println("  and this board will ask for the finger itself.");
    }
  } else {
    Serial.println("R307: NOT FOUND — sensor TX must reach GPIO 16 and its RX");
    Serial.println("  GPIO 17 (they cross), and VCC must match the module:");
    Serial.println("  R307 wants 5 V, a bare AS608 wants 3.3 V.");
  }

  /* ---- Wi-Fi ---- */
  Serial.printf("Wi-Fi: connecting to %s", WIFI_SSID);
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASS);

  uint32_t startedAt = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - startedAt < 20000) {
    delay(400);
    Serial.print(".");
  }
  Serial.println();

  if (WiFi.status() != WL_CONNECTED) {
    diagnoseWifi();
    return;
  }

  Serial.print("Wi-Fi ok, IP ");
  Serial.println(WiFi.localIP());
  Serial.print("This ESP32's MAC: ");
  Serial.println(WiFi.macAddress());
  Serial.printf("Server: %s\n", SERVER_URL);

  Serial.println("Claiming...");
  if (!claimDevice()) {
    Serial.println("Cannot continue: every signed request is refused until the");
    Serial.println("device is claimed (DEVICE_UNCLAIMED).");
    return;
  }

  Serial.println("Syncing clock...");

  /* The return value used to be dropped. Without a clock nothing can be
   * signed, so enrolment and attendance both stop — while finger matching,
   * which never leaves the sensor, carries on as if all were well. Worth
   * saying out loud rather than leaving to be deduced. */
  if (!syncClockFromServer()) {
    Serial.println("  Enrolment and attendance will NOT work until this succeeds:");
    Serial.println("  every request to the server is signed, and a signature needs the");
    Serial.println("  time. Finger matching will still appear to work, because the");
    Serial.println("  sensor does that on its own without asking the server anything.");
  }

  sendHeartbeat();
  lastHeartbeatAt = millis();

  Serial.println();
  Serial.println("Ready. Teacher: scan a finger to open the session.");
  Serial.println("       Student: tap a card once it is open.");
  Serial.println("       Admin:   Fingerprints -> Enrol Fingerprint enrols from here.");
}

void loop() {
  if (millis() - lastHeartbeatAt >= HEARTBEAT_INTERVAL_MS) {
    lastHeartbeatAt = millis();
    sendHeartbeat();
  }

  watchReader();
  pollEnrollment();
  handleFingerprint();

  String uid = readCardUid();
  if (uid.length() == 0) {
    delay(40);
    return;
  }

  /* The reader reports a held card continuously; without this every tap
   * becomes dozens of requests. */
  if (uid == lastUid && millis() - lastTapAt < CARD_DEBOUNCE_MS) return;

  lastUid   = uid;
  lastTapAt = millis();

  Serial.printf("\nCard: %s\n", uid.c_str());

  if (!clockSet && !syncClockFromServer()) {
    Serial.println("  no clock — cannot sign the request");
    return;
  }

  sendTap(uid);
}
