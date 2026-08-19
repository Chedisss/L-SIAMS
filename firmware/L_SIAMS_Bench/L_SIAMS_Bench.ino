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
 * Wiring — AS608 fingerprint (UART2, 57600):
 *      TX  -> GPIO 16  (silkscreen RX2)   sensor transmits, ESP32 receives
 *      RX  -> GPIO 17  (silkscreen TX2)   ESP32 transmits, sensor receives
 *      VCC -> 3V3  (3.3 V — a bare AS608 has NO regulator; 5 V destroys it)
 *      GND -> GND
 *
 *      An R307 is the same sensor in a 5 V housing with a regulator on
 *      board. If you swap back to one, that VCC moves to VIN.
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
/* Most ESP32 dev boards print these two as RX2 and TX2 rather than as 16 and
 * 17, which is why they look absent — they are the same pins under the names
 * their second serial port is known by.
 *
 * On a WROVER board they really are gone: its PSRAM occupies 16 and 17, and
 * they are not brought out. UART2 is not fixed to them, though, so any free
 * output-capable pin works. 25 and 26 are the safe pair here — nothing else
 * in this sketch uses them, neither is a strapping pin, and both can drive
 * output, which 34-39 cannot.
 *
 * Change the two numbers and nothing else; the port is opened with whatever
 * they say. */
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
#define CARD_POLL_MS           2000
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
static uint32_t lastCardPoll      = 0;
static bool     enrollingCard     = false;

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

  /* What the sensor is actually holding. The server records which teacher owns
   * which slot but has never been able to see the templates themselves, so the
   * two could disagree — a sensor erased, or one holding a print whose
   * enrolment never finished — with nothing on any screen saying so, and the
   * only symptom a reader that recognises nobody. Sending the count lets the
   * Fingerprints page compare the two and say which way they differ. */
  if (finger.getTemplateCount() == FINGERPRINT_OK) {
    request["fp_templates"] = (int) finger.templateCount;
  }

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
 * Still no template on the wire: it is built inside the sensor from two images
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

  /* Prove the template can be read back before calling this a success.
   *
   * storeModel() returning OK is the sensor saying it accepted the write, not
   * that anything is retrievable afterwards. That gap produced an enrolment
   * that announced DONE, pushed the template count up, and then failed every
   * search — the print reported as stored was not in the library at all, and
   * the first sign of it was a teacher being told their finger was unknown
   * minutes later.
   *
   * getTemplateCount() cannot close that gap: it counts, it does not look at
   * this slot. loadModel() pulls the template at this specific slot back into
   * a character buffer, so it fails when the slot is empty or unreadable. */
  uint8_t readBack = finger.loadModel(slot);

  if (readBack != FINGERPRINT_OK) {
    Serial.printf("Enrol: the sensor accepted the write but slot %d reads back empty (code %d)\n",
                  slot, readBack);
    Serial.println("       The template is not really stored. This is a sensor fault, not a bad scan.");
    Serial.println("       Power the sensor off and on — a full power cycle, not just the ESP32 reset —");
    Serial.println("       then type 'wipe' and enrol again.");

    reportEnrollFailed(requestId,
      "The sensor reported the template as stored but cannot read it back. "
      "Power-cycle the fingerprint sensor, wipe it, and enrol again.");

    enrolling = false;
    return;
  }

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
   * flash without any of this — matching is local to the sensor. So verification
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

  /* Two different failures were being reported as one.
   *
   * fingerFastSearch() sends HighSpeedSearch (0x1B). Plenty of sensors sold as
   * AS608 or R307 are clones that either do not implement it or implement it over a
   * narrower page range than they claim, and they answer with an error rather
   * than a polite "no match". The ordinary Search (0x04) is the same operation
   * without the optimisation and is supported everywhere, so an error from the
   * fast path is worth retrying on the slow one before telling somebody their
   * finger is unknown.
   *
   * NOTFOUND is left alone: that is the sensor doing its job and saying this
   * print is not in its library, which no retry will change. */
  /* Three outcomes have to be told apart, and only one of them is "unknown
   * finger".
   *
   * fingerFastSearch() sends HighSpeedSearch (0x1B). Plenty of sensors sold as
   * AS608 or R307 are clones that do not implement it, or cover a narrower page range
   * than they claim, and answer with an error rather than a polite "no match".
   * The ordinary Search (0x04) is the same operation without the optimisation
   * and is supported everywhere.
   *
   * Beyond that, a search is the longest and most power-hungry thing the
   * sensor does, and it is where a marginal supply rail or a noisy UART pair
   * shows up first. The give-away is a confirmation code outside the
   * datasheet's table — 0x17 is not a code the AS608/R307 family defines, so a reply
   * carrying it was corrupted in transit rather than sent deliberately.
   * Corruption is transient, so it is worth asking again.
   *
   * NOTFOUND is never retried: that is the sensor working correctly and saying
   * this print is not in its library, and asking again cannot change it. */
  uint8_t search   = finger.fingerFastSearch();
  bool    usedSlow = false;

  for (int attempt = 0; attempt < 3; attempt++) {
    if (search == FINGERPRINT_OK || search == FINGERPRINT_NOTFOUND) break;

    delay(60);
    search   = finger.fingerSearch();
    usedSlow = true;
  }

  if (search == FINGERPRINT_OK && usedSlow) {
    Serial.println("\nFinger: the fast search failed but the ordinary one worked.");
    Serial.println("        Harmless in itself, but it usually means the sensor's supply rail");
    Serial.println("        or its RX/TX pair is marginal. Worth tightening before it bites.");
  }

  if (search == FINGERPRINT_NOTFOUND) {
    Serial.println("\nFinger: NOT RECOGNISED (this print is not in the sensor's library)");
    Serial.println("        Type 'count' to see how many templates the sensor is holding.");
    return;
  }

  if (search != FINGERPRINT_OK) {
    /* Not "unknown finger" — the sensor could not complete the search, three
     * times running. Saying so points at power and wiring rather than sending
     * somebody off to enrol the same finger again, which cannot help. */
    Serial.printf("\nFinger: the sensor could not search — 3 attempts, last code %d\n", search);
    Serial.println("        Codes outside the datasheet's table mean the reply was corrupted,");
    Serial.println("        not that the finger is unknown. Enrolling again will not help.");
    Serial.println("        Check, in this order:");
    Serial.println("          1. The sensor's VCC on 3V3 for an AS608 (5 V destroys it), or on VIN");
    Serial.println("             for an R307. The wrong one browns out mid-search or kills the module.");
    Serial.println("          2. A shared GND between the sensor and the ESP32.");
    Serial.println("          3. The RX/TX pair re-seated; breadboard contacts are the usual culprit.");
    Serial.println("          4. Powering the ESP32 from a wall charger rather than a laptop port.");
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

/**
 * Serialise whatever card is currently selected.
 *
 * Split out because the two callers disagree about which cards count, and only
 * about that — see cardPresent() and cardPresentOrResting() below.
 */
static String selectedCardUid() {
  if (!rfid.PICC_ReadCardSerial()) return "";

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

/**
 * A card that has just arrived in the field.
 *
 * PICC_IsNewCardPresent() sends REQA, which only cards in IDLE answer. That is
 * exactly right for attendance: a card left lying on the reader is halted after
 * its first read and stays halted until it leaves the field, so one tap is one
 * record rather than a hundred.
 */
static String readCardUid() {
  if (!rfid.PICC_IsNewCardPresent()) return "";

  return selectedCardUid();
}

/**
 * Any card in the field, including one already resting there.
 *
 * The same REQA behaviour that makes attendance sane makes enrolment fail. Put
 * the card down, then press the button in the browser, and the card is already
 * halted from an earlier read — REQA gets no answer, the wait window runs to
 * nothing, and the person tapping is told no card was presented while holding
 * one against the reader.
 *
 * WUPA wakes cards in HALT as well as IDLE, which is the question enrolment
 * actually wants to ask: is there a card here, however it came to be here.
 *
 * Two counters come back out because "no card was presented" covers two
 * completely different faults — nothing in the field, and a card that answers
 * but will not select — and telling them apart from the outside is impossible.
 */
static String readCardUidIncludingResting(uint16_t *sawCard, uint16_t *selectFailed) {
  bool present = rfid.PICC_IsNewCardPresent();

  if (!present) {
    /* PICC_IsNewCardPresent() resets these three before it asks; PICC_WakeupA()
     * does not, and inherits whatever the last transaction left behind. Asking
     * WUPA on top of stale baud-rate registers is asking it to fail. */
    rfid.PCD_WriteRegister(MFRC522::TxModeReg, 0x00);
    rfid.PCD_WriteRegister(MFRC522::RxModeReg, 0x00);
    rfid.PCD_WriteRegister(MFRC522::ModWidthReg, 0x26);

    byte atqa[2];
    byte length = sizeof(atqa);
    MFRC522::StatusCode status = rfid.PICC_WakeupA(atqa, &length);

    present = (status == MFRC522::STATUS_OK || status == MFRC522::STATUS_COLLISION);
  }

  if (!present) return "";

  if (sawCard != nullptr) (*sawCard)++;

  /* Selecting is anticollision plus a UID read, and it is the step that fails
   * on a marginal antenna or a card held at an angle. One retry costs nothing
   * and turns most of those into a successful read. */
  for (uint8_t attempt = 0; attempt < 3; attempt++) {
    String uid = selectedCardUid();

    if (uid.length() > 0) return uid;

    delay(15);
  }

  if (selectFailed != nullptr) (*selectFailed)++;

  return "";
}

/* ------------------------------------------------- card enrolment (issue) -- */

static String jsonToString(const LsJson &document) {
  String body;
  serializeJson(document, body);
  return body;
}

static void reportCardStage(int requestId, const char *stage) {
  LsJson request;
  request["request_id"] = requestId;
  request["stage"]      = stage;

  LsJson response;
  signedRequest("POST", "/api/rfid/enrollment/progress", jsonToString(request), &response);
}

static void reportCardFailed(int requestId, const char *reason) {
  LsJson request;
  request["request_id"] = requestId;
  request["reason"]     = reason;

  LsJson response;
  signedRequest("POST", "/api/rfid/enrollment/failed", jsonToString(request), &response);
}

/**
 * Read one card for issuance, with the reader to ourselves.
 *
 * This is the whole reason the feature exists as its own mode. In the ordinary
 * loop the board interleaves a card read with a heartbeat, an enrolment poll
 * and a sync — every one of them a blocking HTTP request — so a card held
 * against the reader during one of those windows is simply not seen, and the
 * person tapping has no way to tell a missed read from a broken card.
 *
 * Inside this function nothing else runs. No heartbeat, no poll, no sync: just
 * the reader, polled tightly until a card appears or the window closes. A card
 * presented at any moment during it is read.
 */
static void runCardEnrollment(int requestId, const char *label, int waitSeconds) {
  Serial.println();
  Serial.printf("=== CARD: waiting for a card for %s ===\n", label);
  Serial.println("    (heartbeats paused - the reader has this board to itself)");

  enrollingCard = true;

  reportCardStage(requestId, "present_card");

  uint32_t startedAt = millis();
  uint32_t windowMs  = (uint32_t) (waitSeconds > 0 ? waitSeconds : 45) * 1000UL;
  String   uid;

  uint32_t lastAntennaReset = millis();
  uint32_t lastTick         = millis();
  uint16_t sawCard          = 0;
  uint16_t selectFailed     = 0;

  /* Worth one line at the start: a reader answering with a version that is not
   * a real one is the difference between "hold it flatter" and "check the
   * wiring", and that is not deducible from a failed read. */
  byte version = rfid.PCD_ReadRegister(MFRC522::VersionReg);
  Serial.printf("    reader version 0x%02X%s\n", version,
                (version == 0x91 || version == 0x92 || version == 0x88 || version == 0x90 || version == 0x12)
                  ? "" : "  <-- not a version any MFRC522 reports; suspect wiring or power");

  while (millis() - startedAt < windowMs) {
    /* Resting cards included: somebody who puts the card down and then presses
     * the button in the browser is holding a card the reader must find. */
    uid = readCardUidIncludingResting(&sawCard, &selectFailed);

    if (uid.length() > 0) break;

    /* A card held continuously never leaves the field, so it never returns to
     * IDLE by itself. Dropping the antenna power-cycles it, which is the only
     * way to get a wedged or stuck card back to a state that answers.
     *
     * 50 ms, not 5: a card's onboard capacitor holds it alive across a shorter
     * gap, so the shorter one looks like a fix and changes nothing. */
    if (millis() - lastAntennaReset > 4000) {
      lastAntennaReset = millis();
      rfid.PCD_AntennaOff();
      delay(50);
      rfid.PCD_AntennaOn();
      delay(10);
    }

    /* Somebody standing at a reader that says nothing cannot tell waiting from
     * broken, which is the whole reason this mode exists. */
    if (millis() - lastTick > 3000) {
      lastTick = millis();
      Serial.printf("CARD: still waiting — %us left.%s\n",
                    (unsigned) ((windowMs - (millis() - startedAt)) / 1000UL),
                    sawCard == 0
                      ? " Nothing in the field yet — hold the card flat on the reader."
                      : "");

      if (sawCard > 0) {
        Serial.printf("      a card is answering but will not select (%u attempt(s)) — "
                      "try it flatter, or slightly further away.\n", selectFailed);
      }
    }

    /* Short enough that a card touched briefly still lands inside a poll, and
     * the reader is the only thing being asked. */
    delay(30);
  }

  if (uid.length() == 0) {
    /* The two failures need different actions, so they get different words
     * here and in the browser rather than one message covering both. */
    const bool answered = sawCard > 0;

    Serial.printf("CARD: gave up. Card detected %u time(s), select failed %u time(s).\n",
                  sawCard, selectFailed);

    if (answered) {
      Serial.println("      The reader sees a card but cannot read its serial. That is an antenna");
      Serial.println("      or power problem, not a card problem: check the module is on 3.3 V,");
      Serial.println("      that its GND shares the ESP32's, and that the SPI leads are short.");
    } else {
      Serial.println("      Nothing answered at all. Either the card is not 13.56 MHz (a thick");
      Serial.println("      white 125 kHz fob will never read), or the reader is not wired right.");
      Serial.println("      Try the card that already works for attendance to tell those apart.");
    }

    reportCardFailed(requestId, answered
      ? "The reader detected a card but could not read its serial — hold it flatter, or check the reader's power and wiring."
      : "No card answered the reader. Check the card is 13.56 MHz, and that the reader is wired and powered correctly.");

    enrollingCard = false;
    return;
  }

  Serial.printf("CARD: read %s\n", uid.c_str());
  reportCardStage(requestId, "reading");

  LsJson request;
  request["request_id"] = requestId;
  request["card_uid"]   = uid;

  LsJson response;
  int status = signedRequest("POST", "/api/rfid/enrollment/captured", jsonToString(request), &response);

  if (status == 200) {
    Serial.println("CARD: reported to the server — choose the student in L-SIAMS.");
  } else {
    Serial.printf("CARD: the server refused the read (HTTP %d, %s)\n",
                  status, (const char *) (response["code"] | "-"));
  }

  /* Wait for the card to be taken away. Issuing runs card after card, so
   * without this the one just read is still in the field when the next request
   * opens and gets captured a second time — the resting-card wakeup above
   * makes that certain rather than merely likely.
   *
   * Bounded: a card genuinely left behind must not stop the terminal working.
   */
  Serial.println("CARD: take the card off the reader.");

  uint32_t clearedAt = millis();
  while (millis() - clearedAt < 8000 && readCardUidIncludingResting(nullptr, nullptr).length() > 0) {
    delay(80);
  }

  enrollingCard = false;
}

/** Is a card wanted? Mirrors pollEnrollment(), and skips for the same reasons. */
static void pollCardEnrollment() {
  if (!clockSet || enrolling || enrollingCard) return;

  if (millis() - lastCardPoll < CARD_POLL_MS) return;
  lastCardPoll = millis();

  LsJson response;
  int status = signedRequest("GET", "/api/rfid/enrollment", "", &response);

  if (status != 200) {
    static uint32_t lastComplaintAt = 0;

    if (lastComplaintAt == 0 || millis() - lastComplaintAt > 15000) {
      lastComplaintAt = millis();
      Serial.printf("\nCARD: the server refused the poll (HTTP %d, %s)\n",
                    status, (const char *) (response["code"] | "-"));
    }

    return;
  }

  JsonObject enrolment = response["data"]["enrollment"];
  if (enrolment.isNull()) return;

  int requestId = enrolment["request_id"] | 0;
  if (requestId <= 0) return;

  const char *label = enrolment["label"] | "the next card";
  int waitSeconds   = enrolment["wait_seconds"] | 45;

  runCardEnrollment(requestId, label, waitSeconds);
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

/* ------------------------------------------------------- config sanity -- */

/**
 * Catch the values that were never filled in.
 *
 * Each one below has cost a debugging session. A placeholder SERVER_URL
 * produces "HTTP -1" from a board that is otherwise perfect, which reads as a
 * network fault and sends people to the firewall; a placeholder API_KEY
 * produces a storm of 401s that reads as a rotation problem. The sketch knows
 * the shipped defaults and can say so before anything else runs, which turns
 * an afternoon into one line.
 *
 * Warnings only. A board with a placeholder still boots, still reports its
 * MAC, and still runs its reader checks — all of which are useful while the
 * rest is being filled in.
 */
static void checkConfig() {
  uint8_t problems = 0;

  if (strcmp(WIFI_SSID, "YOUR_WIFI_NAME") == 0) {
    Serial.println("CONFIG: WIFI_SSID is still the placeholder.");
    problems++;
  }

  /* The shipped example address. Nobody's PC is ever actually on it, and
   * leaving it produces a connection refused that looks like a firewall. */
  if (strstr(SERVER_URL, "192.168.0.100") != nullptr) {
    Serial.println("CONFIG: SERVER_URL is still the example address (192.168.0.100).");
    Serial.println("        Put your PC's own address here — start.bat prints it as");
    Serial.println("        \"On other devices\". Every request fails with HTTP -1 until you do.");
    problems++;
  }

  if (strstr(SERVER_URL, "localhost") != nullptr || strstr(SERVER_URL, "127.0.0.1") != nullptr) {
    Serial.println("CONFIG: SERVER_URL points at localhost, which to this board means");
    Serial.println("        this board. It has to be the PC's address on the network.");
    problems++;
  }

  /* http://x.x.x.x:8080 — the colon after the host is what a missing port
   * looks like, and a port typed as :080 is the same mistake once removed. */
  const char *hostStart = strstr(SERVER_URL, "//");
  const char *portMark  = hostStart == nullptr ? nullptr : strchr(hostStart + 2, ':');

  if (portMark == nullptr) {
    Serial.println("CONFIG: SERVER_URL has no port. It must end in :8080 (or whatever");
    Serial.println("        port start.bat reports) — without it the board tries port 80.");
    problems++;
  } else if (portMark[1] == '0') {
    /* :080 rather than :8080. It parses, it connects to port 80, and nothing
     * is listening there — so it fails exactly like a wrong address. */
    Serial.printf("CONFIG: SERVER_URL's port is \"%s\", which starts with a zero.\n", portMark + 1);
    Serial.println("        :080 is the usual way :8080 gets mistyped, and it silently");
    Serial.println("        connects to port 80 instead, where nothing is listening.");
    problems++;
  }

  if (strncmp(API_KEY, "lsk_xxxx", 8) == 0 || strchr(API_KEY, '.') == nullptr) {
    Serial.println("CONFIG: API_KEY is not a real key. It looks like lsk_<id>.<secret>");
    Serial.println("        and comes from the provisioning file. Every signed request");
    Serial.println("        will be refused with API_KEY_INVALID until it is right.");
    problems++;
  }

  if (strncmp(HMAC_SECRET, "zzzz", 4) == 0) {
    Serial.println("CONFIG: HMAC_SECRET is still the placeholder.");
    problems++;
  }

  if (problems > 0) {
    Serial.println("        API_KEY and HMAC_SECRET must come from the SAME download —");
    Serial.println("        each download replaces the pair the server holds.");
    Serial.println();
  }
}

/* ------------------------------------------------- fingerprint discovery -- */

/**
 * Find the sensor when it is not on the configured pins.
 *
 * Two things go wrong here and neither announces itself. UART2 is not fixed
 * to GPIO 16 and 17 — a WROVER has no such pins at all, and most boards print
 * them as RX2 and TX2, so people wire to whatever is free. And TX/RX cross,
 * which means a perfectly reasonable straight-through wiring leaves both ends
 * talking and neither listening.
 *
 * Rather than asking which pins were used, try the plausible ones. Each pair
 * is tried both ways round, so a reversed connection is found and named
 * instead of reported as a missing sensor. Both baud rates are tried too:
 * 57600 is the AS608's default, but modules ship configured at 9600 and the
 * symptom is identical.
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

  Serial.println("Sensor: not on the configured pins — looking for it...");

  for (uint8_t b = 0; b < sizeof(bauds) / sizeof(bauds[0]); b++) {
    for (uint8_t i = 0; i < sizeof(candidates) / sizeof(candidates[0]); i++) {
      const Pair &p = candidates[i];

      /* Skip the pair already tried by the caller, at its baud rate. */
      if (bauds[b] == FINGERPRINT_BAUD && p.rx == PIN_FINGER_RX && p.tx == PIN_FINGER_TX) {
        continue;
      }

      fingerSerial.end();
      delay(20);
      fingerSerial.begin(bauds[b], SERIAL_8N1, p.rx, p.tx);
      delay(120);

      if (!finger.verifyPassword()) continue;

      Serial.println();
      Serial.printf("Sensor: FOUND on RX %d, TX %d at %lu baud.\n",
                    p.rx, p.tx, (unsigned long) bauds[b]);
      Serial.println();
      Serial.println("  It works from here, but the sketch is still configured for");
      Serial.println("  something else. Make it permanent so the next boot does not");
      Serial.println("  have to search:");
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

  /* Nothing answered anywhere — put the port back where it was configured so
   * the failure message below describes the state the board is actually in. */
  fingerSerial.end();
  delay(20);
  fingerSerial.begin(FINGERPRINT_BAUD, SERIAL_8N1, PIN_FINGER_RX, PIN_FINGER_TX);
  delay(100);

  Serial.println("Sensor: no answer on any pin pair tried.");

  return false;
}

/* ----------------------------------------------------------------- setup -- */

void setup() {
  Serial.begin(115200);
  delay(400);

  Serial.println();
  Serial.println("L-SIAMS bench terminal — RFID + fingerprint");
  Serial.println("------------------------------------------");

  checkConfig();

  /* ---- RFID ---- */
  SPI.begin();
  rfid.PCD_Init();

  /* Read it several times. A reader that answers 0x92 every time is wired
   * correctly; one that answers a different value each time has a connection
   * problem, not a configuration problem, and no amount of retrying in the
   * card code will change that. The two need to be told apart here, once,
   * rather than inferred later from reads that fail. */
  byte version = rfid.PCD_ReadRegister(MFRC522::VersionReg);
  bool stable  = true;

  for (uint8_t i = 0; i < 8; i++) {
    delay(5);
    if (rfid.PCD_ReadRegister(MFRC522::VersionReg) != version) stable = false;
  }

  const bool known = (version == 0x91 || version == 0x92 || version == 0x88
                   || version == 0x90 || version == 0x12);

  Serial.printf("MFRC522 version: 0x%02X %s\n", version,
                known ? (stable ? "(ok)" : "(known version but UNSTABLE - see below)")
                      : "<-- NOT a version any MFRC522 reports");

  /* The version register alone cannot separate a faulty chip from an odd
   * clone, and that distinction decides whether to keep debugging or buy a
   * replacement. The chip's own self test can: it runs a known input through
   * the internal CRC engine and compares the result against the signature NXP
   * burned into the part. Passing it is proof the silicon works.
   *
   * Run here rather than in a separate sketch because this is the sketch
   * people actually flash, and a diagnostic nobody runs answers nothing. */
  if (!known || !stable) {
    Serial.print("  Chip self test: ");
    Serial.println(rfid.PCD_PerformSelfTest()
      ? "PASSED — the silicon is genuine and working, so the odd\n"
        "                  version is cosmetic. Cards should read; if they do not,\n"
        "                  the antenna or the 3.3 V supply is the next suspect."
      : "FAILED — the chip cannot produce its own signature.\n"
        "                  With the reads consistent, that means the module itself is\n"
        "                  faulty. No wiring change will fix it.");

    /* The self test leaves the chip reset and idle; without this everything
     * afterwards fails and looks like a second, separate fault. */
    rfid.PCD_Init();
    delay(50);

    /* A chip that answers every register read with its transmitter switched
     * off will never see a card, and nothing else here would mention it. */
    byte tx = rfid.PCD_ReadRegister(MFRC522::TxControlReg);

    if ((tx & 0x03) != 0x03) {
      Serial.printf("  Antenna: OFF (TxControlReg 0x%02X) — switching it on.\n", tx);
      rfid.PCD_AntennaOn();
      delay(10);
    }

    /* Said plainly because the alternative is hours spent on the card. */
    Serial.println("  The reader is not talking properly, so NO card will ever read.");
    Serial.println("  Real values are 0x91, 0x92 or 0x88. 0x00 and 0xFF mean nothing is");
    Serial.println("  answering at all; anything else means the SPI link is unreliable.");
    Serial.printf("  Reads were %s.\n", stable ? "at least consistent" : "DIFFERENT each time - a loose wire or bad power");
    Serial.println("  Check, in this order:");
    Serial.println("    1. VCC on 3.3 V. NEVER 5 V - it damages this module.");
    Serial.println("    2. GND shared with the ESP32.");
    Serial.println("    3. MISO 19, MOSI 23, SCK 18, SDA/SS 5, RST 22 - MISO and MOSI");
    Serial.println("       are the pair people swap.");
    Serial.println("    4. Re-seat every jumper. Breadboard contacts are the usual cause.");
    Serial.println("    5. Unplug the fingerprint sensor and reboot. It shares the supply and draws");
    Serial.println("       bursts; if the version steadies without it, the rail is weak.");
  }

  /* ---- Fingerprint ---- */
  fingerSerial.begin(FINGERPRINT_BAUD, SERIAL_8N1, PIN_FINGER_RX, PIN_FINGER_TX);
  delay(100);

  /* Configured pins first, always. The search below only runs when those do
   * not answer, so a board that is wired as documented behaves exactly as it
   * did and pays nothing for the search existing. */
  if (!finger.verifyPassword() && findFingerprintSensor()) {
    /* findFingerprintSensor() has already reopened the port on whatever it
     * found and said so; fall through into the success branch. */
  }

  if (finger.verifyPassword()) {
    finger.getTemplateCount();
    fingerReady = true;
    Serial.printf("Sensor: found — %d template(s) enrolled on this sensor\n",
                  finger.templateCount);
    Serial.println("       console: type count, slots or wipe into the Serial Monitor");
    if (finger.templateCount == 0) {
      Serial.println("  none enrolled yet — that is fine. Open Fingerprints in");
      Serial.println("  L-SIAMS, press Enrol Fingerprint, choose this terminal,");
      Serial.println("  and this board will ask for the finger itself.");
    }
  } else {
    /* The pins are printed rather than hard-coded into the sentence, because
     * they are configurable and a message naming 16 and 17 while the sketch
     * uses 25 and 26 sends somebody to check wiring that is already right. */
    Serial.printf("Sensor: NOT FOUND — sensor TX must reach GPIO %d and its RX GPIO %d.\n",
                  PIN_FINGER_RX, PIN_FINGER_TX);
    Serial.println("  They cross: the sensor's transmit goes to the pin this board");
    Serial.println("  receives on. Wired straight through, both talk and neither listens.");
    Serial.println("  VCC must match the module: a bare AS608 wants 3.3 V on 3V3, an");
    Serial.println("  R307 wants 5 V on VIN. 5 V on a bare AS608 destroys it.");
    Serial.println("  If your board has no 16 or 17, look for RX2 and TX2 — same pins,");
    Serial.println("  different label. If it genuinely has neither (a WROVER uses them");
    Serial.println("  for PSRAM), set PIN_FINGER_RX 25 and PIN_FINGER_TX 26 and rewire.");
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

  /* The IP above and the URL above it were printed next to each other and left
   * for a person to compare. They are the commonest thing to get wrong — the
   * PC's address is typed in by hand, and a router handing out 192.168.1.x
   * while the URL says 192.168.0.100 produces a terminal that joins the Wi-Fi,
   * reports nothing, and shows as Offline with no error anywhere. Comparing
   * them costs nothing and turns that into a sentence. */
  {
    String host = String(SERVER_URL);
    int    from = host.indexOf("//");

    if (from >= 0) host = host.substring(from + 2);

    int cut = host.indexOf(':');
    if (cut < 0) cut = host.indexOf('/');
    if (cut >= 0) host = host.substring(0, cut);

    IPAddress serverIp;

    if (serverIp.fromString(host)) {
      IPAddress mine = WiFi.localIP();

      if (serverIp[0] != mine[0] || serverIp[1] != mine[1] || serverIp[2] != mine[2]) {
        Serial.println();
        Serial.println("  *** The server address is on a different network from this board. ***");
        Serial.printf("      This ESP32 is %d.%d.%d.%d and the URL points at %s.\n",
                      mine[0], mine[1], mine[2], mine[3], host.c_str());
        Serial.println("      Nothing this board sends can reach that address, so the terminal");
        Serial.println("      will stay Offline however long you wait.");
        Serial.println("      start.bat prints the right address as \"On other devices\".");
        Serial.println("      Put that in SERVER_URL, keep the :8080, and re-upload.");
        Serial.println();
      } else {
        Serial.println("Server is on this network — good.");
      }
    }
  }

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

/* ---------------------------------------------------------------- console --
 *
 * Type a word into the Serial Monitor and press Enter.
 *
 * The sensor keeps its own copy of every template, and until now nothing here
 * could look at that copy or clear it. That mattered once a template ended up
 * in the sensor with no matching row on the server: the reader went on
 * matching it happily, the server answered FINGERPRINT_UNKNOWN, and there was
 * no way to see what the sensor was holding, let alone remove it.
 *
 *   count  how many templates the sensor is storing
 *   slots  which slot numbers those are
 *   wipe   erase every template on the sensor
 *
 * `wipe` clears the sensor only. The server's records are untouched, so every
 * teacher has to be enrolled again afterwards — which is the point: it is the
 * way back to the sensor and the server agreeing with each other.
 */
void handleConsole() {
  if (!Serial.available()) return;

  /* The Serial Monitor's line-ending dropdown decides whether a newline is
   * ever sent. Set to "No line ending" there is none, and the default one
   * second timeout would stall the whole loop — no card read, no heartbeat —
   * every time somebody typed. 60 ms is longer than a line takes to arrive at
   * 115200 baud and short enough not to matter if it never does. */
  Serial.setTimeout(60);

  String command = Serial.readStringUntil('\n');
  command.trim();
  command.toLowerCase();

  if (command.length() == 0) return;

  if (command == "count") {
    finger.getTemplateCount();
    Serial.printf("\nSensor holds %d template(s).\n", finger.templateCount);
    return;
  }

  if (command == "slots") {
    Serial.println("\nOccupied slots:");
    int found = 0;

    /* No bulk "list" exists in the AS608/R307 protocol, so each slot is probed by
     * asking the sensor to load it. Capped at 200 to keep this quick — a
     * bench sensor never holds more. */
    for (uint16_t slot = 1; slot <= 200; slot++) {
      if (finger.loadModel(slot) == FINGERPRINT_OK) {
        Serial.printf("  slot %d\n", slot);
        found++;
      }
    }

    if (found == 0) Serial.println("  (none)");
    Serial.printf("Total: %d\n", found);
    return;
  }

  if (command == "wipe") {
    Serial.println("\nErasing every template on the sensor…");

    uint8_t erased = finger.emptyDatabase();

    if (erased != FINGERPRINT_OK) {
      Serial.printf("The sensor refused the erase (code %d). Check power and wiring.\n", erased);
      return;
    }

    /* Believing the OK is not enough. A sensor whose flash has stopped
     * accepting writes acknowledges the command and keeps every template, and
     * that is indistinguishable from success unless the count is read back.
     *
     * It is worth catching precisely, because it is the difference between a
     * sensor that needs its templates re-enrolled and a sensor that needs
     * replacing — and enrolling into flash that cannot be written is what
     * produces a template stored "successfully" that no search can ever find. */
    finger.getTemplateCount();

    if (finger.templateCount == 0) {
      Serial.println("Done — the sensor is empty.");
      Serial.println("Re-enrol every teacher from Fingerprints in L-SIAMS.");
      return;
    }

    Serial.printf("The sensor accepted the erase and still holds %d template(s).\n",
                  finger.templateCount);
    Serial.println();
    Serial.println("That is a hardware fault, and a conclusive one: the flash is not");
    Serial.println("accepting writes. Every enrolment will report success and store");
    Serial.println("nothing findable, which is why the same finger keeps coming back");
    Serial.println("as not recognised however many times it is enrolled.");
    Serial.println();
    Serial.println("Try once: unplug the sensor's power completely, wait five seconds,");
    Serial.println("reconnect, and run wipe again. If the count still will not reach 0,");
    Serial.println("this sensor needs replacing — no change to the code or the wiring");
    Serial.println("will fix it.");

    return;
  }

  Serial.printf("\nUnknown command \"%s\". Try: count, slots, wipe\n", command.c_str());
}

void loop() {
  handleConsole();

  /* The heartbeat is a blocking HTTP request, and so is every poll below it.
   * A card held against the reader while one of them is in flight is not seen
   * — which is invisible from the outside and reads as a dead reader.
   *
   * During a capture the reader owns the board: runCardEnrollment() does not
   * return until it has a UID or the window closes, and nothing here runs in
   * the meantime. The heartbeat it delays is bounded by that window, and a
   * terminal that goes quiet for under a minute while somebody issues cards
   * at it is not a terminal anybody needs alerting about. */
  if (!enrollingCard && millis() - lastHeartbeatAt >= HEARTBEAT_INTERVAL_MS) {
    lastHeartbeatAt = millis();
    sendHeartbeat();
  }

  watchReader();
  pollEnrollment();
  pollCardEnrollment();
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
