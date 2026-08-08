/* ===========================================================================
 * L-SIAMS — RFID bench test
 *
 * A deliberately small sketch that proves one thing end to end: a card tapped
 * on the MFRC522 reaches the server, is authenticated, and comes back with the
 * server's decision. No fingerprint, no display, no offline queue, no state
 * machine — when this works, the hard part (request signing) is proven, and
 * the full terminal firmware is the same call wrapped in more behaviour.
 *
 * It differs from the production firmware in two ways, both on purpose:
 *
 *   1. Plain HTTP. The production firmware always opens a WiFiClientSecure,
 *      which cannot talk to the `php -S` development server on port 8080.
 *      This one picks the client from the URL scheme, so http:// works on the
 *      bench and https:// still works against a deployment.
 *
 *   2. It bootstraps its clock from the HTTP `Date` response header. Every
 *      signed request must carry a timestamp within 30 seconds of server time,
 *      and an ESP32 boots believing it is 1 January 1970 — so the very first
 *      request is unsignable without a clock, and NTP is not available on a
 *      school LAN with no route to the internet.
 *
 *      `Date` solves it: HTTP puts it on every response including the 401 that
 *      rejects an unsigned request, so one throwaway call yields the server's
 *      wall clock to the second. The exact epoch is then taken from
 *      GET /api/device/time once signing works.
 *
 *      The obvious alternative does not currently work. DeviceAuthMiddleware
 *      attaches `server_epoch` to its TIMESTAMP_EXPIRED rejection specifically
 *      so a device can correct itself, but App::respondHttp() returns early for
 *      every 401 through Response::fail(), which drops the context — the field
 *      never reaches the wire. Verified against a live server: that body comes
 *      back as "data":[].
 *
 * Wiring (MFRC522 → ESP32, SPI):
 *      SDA/SS → GPIO 5      SCK → GPIO 18     MOSI → GPIO 23
 *      MISO   → GPIO 19     RST → GPIO 27
 *      3.3V   → 3V3  (NEVER 5V — 5 V destroys the module)
 *      GND    → GND
 *
 * Libraries: MFRC522 by GithubCommunity, ArduinoJson v7.
 * Board: ESP32 Dev Module.
 * ======================================================================== */

#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <SPI.h>
#include <MFRC522.h>
#include <ArduinoJson.h>
#include <sys/time.h>
#include <esp_system.h>      /* esp_random() */
#include "mbedtls/md.h"

/* ---------------------------------------------------------------- config -- */
/* Fill these five in from the provisioning JSON you downloaded when you
 * registered the terminal on the Devices page. That download is the only copy
 * of the key and secret that will ever exist. */

static const char *WIFI_SSID   = "YOUR_WIFI_NAME";
static const char *WIFI_PASS   = "YOUR_WIFI_PASSWORD";

/* The host PC's address on the LAN, with the port. Not localhost — that would
 * mean the ESP32 itself. Get it on the PC with:
 *   (Get-NetIPConfiguration | Where-Object {$_.IPv4DefaultGateway -ne $null}).IPv4Address.IPAddress */
static const char *SERVER_URL  = "http://192.168.1.14:8080";

static const char *DEVICE_ID   = "DEV-2026-0001";          /* "device_id"   */
static const char *API_KEY     = "lsk_xxxxxxxx.yyyyyyyy";  /* "api_key"     */
static const char *HMAC_SECRET = "zzzzzzzzzzzzzzzz";       /* "hmac_secret" */

/* A freshly registered device is `unclaimed`, and DeviceAuthMiddleware refuses
 * every signed request from one — the terminal has to present its single-use
 * claim token first. That call is the one device route with no signature
 * requirement, so it works before the clock is set.
 *
 * Both values come from the same provisioning JSON. The MAC must be the one
 * you typed when registering: the server compares it, and a mismatch is
 * treated as a leaked provisioning file, not a typo. The ESP32's own MAC is
 * printed at boot below, so register with that and paste it here.
 *
 * Claiming is idempotent from your side — once done, the token is consumed and
 * this call is skipped on every later boot. */
static const char *CLAIM_TOKEN = "";                       /* "claim_token" */
static const char *DEVICE_MAC  = "AA:BB:CC:DD:EE:FF";      /* as registered  */

/* ------------------------------------------------------------------ pins -- */

#define PIN_RFID_SS   5
#define PIN_RFID_RST  27

#define CARD_DEBOUNCE_MS 2500

MFRC522 rfid(PIN_RFID_SS, PIN_RFID_RST);

static String   lastUid;
static uint32_t lastTapAt = 0;
static bool     clockSet  = false;
static String   lastDateHeader;   /* HTTP Date from the most recent response */

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

/* SHA-256 through the generic message-digest interface rather than
 * mbedtls_sha256(), whose signature changed between mbedtls 2.x and 3.x and so
 * breaks depending on which ESP32 core is installed. This spelling compiles on
 * both. Returns lowercase hex, which is what PHP's hash('sha256', …) produces
 * and therefore what the server rebuilds and compares against. */
static String sha256Hex(const String &data) {
  uint8_t digest[32];
  const mbedtls_md_info_t *info = mbedtls_md_info_from_type(MBEDTLS_MD_SHA256);

  mbedtls_md_context_t ctx;
  mbedtls_md_init(&ctx);
  mbedtls_md_setup(&ctx, info, 0);            /* 0 = plain digest, not HMAC */
  mbedtls_md_starts(&ctx);
  mbedtls_md_update(&ctx, (const unsigned char *) data.c_str(), data.length());
  mbedtls_md_finish(&ctx, digest);
  mbedtls_md_free(&ctx);

  return toHexLower(digest, sizeof(digest));
}

/* HMAC-SHA256, lowercase hex. The server compares with hash_equals(), which is
 * byte-exact — uppercase hex fails as surely as a wrong key would, and gives
 * exactly the same SIGNATURE_INVALID, so this must stay lowercase.
 *
 * The secret is used as raw key bytes exactly as it appears in the
 * provisioning JSON: no base64 decode, no hex decode, no trimming. */
static String hmacSha256Hex(const String &message, const char *key) {
  uint8_t out[32];
  const mbedtls_md_info_t *info = mbedtls_md_info_from_type(MBEDTLS_MD_SHA256);

  mbedtls_md_context_t ctx;
  mbedtls_md_init(&ctx);
  mbedtls_md_setup(&ctx, info, 1);            /* 1 = HMAC */
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

/* Days since 1970-01-01 for a civil date, by Howard Hinnant's algorithm.
 *
 * Written out rather than reaching for timegm() or strptime()+mktime(): the
 * first is not in every ESP32 toolchain, and the second depends on the process
 * timezone, which is not UTC here. Pure integer arithmetic has neither
 * problem and is exact for any date this system will ever see. */
static long daysFromCivil(long y, unsigned m, unsigned d) {
  y -= (m <= 2);
  const long     era = (y >= 0 ? y : y - 399) / 400;
  const unsigned yoe = (unsigned) (y - era * 400);
  const unsigned doy = (153u * (m + (m > 2 ? -3 : 9)) + 2) / 5 + d - 1;
  const unsigned doe = yoe * 365 + yoe / 4 - yoe / 100 + doy;

  return era * 146097L + (long) doe - 719468L;
}

/* Parse an RFC 7231 HTTP date: "Sat, 08 Aug 2026 14:42:16 GMT".
 * Always GMT by specification, so no timezone handling is needed. */
static time_t parseHttpDate(const String &value) {
  char monthName[4] = {0};
  int  day = 0, year = 0, hour = 0, minute = 0, second = 0;

  /* Skip the day-of-week and its comma; it carries no information. */
  int comma = value.indexOf(',');
  String rest = (comma >= 0) ? value.substring(comma + 1) : value;
  rest.trim();

  if (sscanf(rest.c_str(), "%d %3s %d %d:%d:%d",
             &day, monthName, &year, &hour, &minute, &second) != 6) {
    return 0;
  }

  static const char *months = "JanFebMarAprMayJunJulAugSepOctNovDec";
  const char *found = strstr(months, monthName);
  if (found == nullptr) return 0;

  unsigned month = (unsigned) ((found - months) / 3) + 1;

  return (time_t) (daysFromCivil(year, month, (unsigned) day) * 86400L
                   + hour * 3600L + minute * 60L + second);
}

/* A version-4 UUID. The server validates the shape and uses it to make a tap
 * idempotent: the same request_id replayed never records attendance twice. */
static String generateUuid() {
  uint8_t b[16];
  for (int i = 0; i < 16; i++) b[i] = (uint8_t) (esp_random() & 0xFF);

  b[6] = (b[6] & 0x0F) | 0x40;   /* version 4  */
  b[8] = (b[8] & 0x3F) | 0x80;   /* variant 10 */

  char buf[37];
  snprintf(buf, sizeof(buf),
           "%02x%02x%02x%02x-%02x%02x-%02x%02x-%02x%02x-%02x%02x%02x%02x%02x%02x",
           b[0], b[1], b[2],  b[3],  b[4],  b[5],  b[6],  b[7],
           b[8], b[9], b[10], b[11], b[12], b[13], b[14], b[15]);

  return String(buf);
}

/* ------------------------------------------------------------- transport -- */

/**
 * One signed request.
 *
 * The canonical string is rebuilt byte-for-byte by the server before it checks
 * the signature (ApiKeyService::canonicalString):
 *
 *     METHOD \n path \n device_id \n timestamp \n nonce \n sha256_hex(body)
 *
 * `path` is the path alone — no scheme, no host, no query string. Getting any
 * one of those six fields wrong produces SIGNATURE_INVALID with no hint as to
 * which, so they are assembled in exactly one place, here.
 */
static int signedRequest(const char *method, const String &path, const String &body,
                         JsonDocument *responseOut, const String &requestId = "") {
  if (WiFi.status() != WL_CONNECTED) return -1;

  String url = String(SERVER_URL) + path;
  bool  isTls = url.startsWith("https://");

  WiFiClient       plain;
  WiFiClientSecure secure;
  HTTPClient http;

  if (isTls) {
    /* No certificate pinning in a bench test. A deployment pins the
     * fingerprint from the provisioning file — see docs/SECURITY.md. */
    secure.setInsecure();
    if (!http.begin(secure, url)) return -2;
  } else {
    if (!http.begin(plain, url)) return -2;
  }

  http.setTimeout(8000);
  http.addHeader("Content-Type", "application/json");

  /* Must be declared before the request or HTTPClient discards it. This is the
   * clock bootstrap: it arrives even on the 401 that rejects an unsigned or
   * badly-timestamped call. */
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

    if (responseOut != nullptr) {
      deserializeJson(*responseOut, http.getString());
    }
  }

  http.end();
  return status;
}

/**
 * Unsigned POST — used only by the claim call, which runs before this device
 * has anything to sign with and before the clock is set.
 */
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

/**
 * Activate this terminal, if it has not been activated already.
 *
 * CLAIM_TOKEN_USED is not a failure here: it means a previous boot already
 * claimed the device, which is exactly the state we want to be in. Anything
 * else is worth stopping for, because no signed request will be accepted until
 * this succeeds.
 */
static bool claimDevice() {
  if (strlen(CLAIM_TOKEN) == 0) {
    Serial.println("  no claim token set — skipping (fine if already claimed)");
    return true;
  }

  JsonDocument request;
  request["claim_token"] = CLAIM_TOKEN;
  request["device_id"]   = DEVICE_ID;
  request["mac_address"] = DEVICE_MAC;

  String body;
  serializeJson(request, body);

  JsonDocument response;
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
    Serial.println("  -> DEVICE_MAC or DEVICE_ID does not match the registration");
  }

  return false;
}

/**
 * Set the clock from the server.
 *
 * A rejected timestamp is not a dead end. The signed call is tried first and
 * gives the exact epoch when the clock is already close enough; when it is
 * not, the 401 that comes back still carries an HTTP Date header, which is
 * good to the second and enough to make the retry succeed. Either way a device
 * with no battery-backed clock and no internet corrects itself.
 */
static void setClock(time_t epoch) {
  /* Fields assigned rather than designated-initialised: the latter is a GNU
   * extension in C++ and its acceptance varies with the core's -std flag. */
  struct timeval tv;
  tv.tv_sec  = epoch;
  tv.tv_usec = 0;

  settimeofday(&tv, nullptr);
  clockSet = true;
}

static bool syncClockFromServer() {
  JsonDocument response;

  /* First attempt. With a dead clock this is rejected as TIMESTAMP_EXPIRED,
   * which is fine — the response still carries a Date header. */
  int status = signedRequest("GET", "/api/device/time", "", &response);

  if (status == 200) {
    long epoch = response["data"]["server_epoch"] | 0L;

    if (epoch > 0) {
      setClock((time_t) epoch);
      Serial.printf("  clock set from /api/device/time: %ld\n", epoch);
      return true;
    }
  }

  /* Fall back to the transport's own clock and retry, so the exact epoch is
   * used from here on. */
  time_t fromHeader = lastDateHeader.length() ? parseHttpDate(lastDateHeader) : 0;

  if (fromHeader <= 0) {
    Serial.printf("  clock sync failed (HTTP %d, code %s, no usable Date header)\n",
                  status, (const char *) (response["code"] | "-"));
    return false;
  }

  setClock(fromHeader);
  Serial.printf("  clock set from HTTP Date: %ld (%s)\n",
                (long) fromHeader, lastDateHeader.c_str());

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
  JsonDocument request;
  request["rfid_uid"] = uid;

  String requestId = generateUuid();
  request["request_id"] = requestId;

  String body;
  serializeJson(request, body);

  JsonDocument response;
  int status = signedRequest("POST", "/api/attendance/tap", body, &response, requestId);

  /* One retry after a clock correction. A device that has been powered off for
   * a while drifts, and re-reading the server's epoch is cheaper than failing
   * a real student's tap. */
  if (response["code"] == "TIMESTAMP_EXPIRED") {
    Serial.println("  timestamp rejected — resyncing clock and retrying");
    if (syncClockFromServer()) {
      status = signedRequest("POST", "/api/attendance/tap", body, &response, requestId);
    }
  }

  Serial.printf("  HTTP %d\n", status);
  serializeJsonPretty(response, Serial);
  Serial.println();

  const char *line1 = response["display_line_1"] | "";
  const char *line2 = response["display_line_2"] | "";
  if (strlen(line1)) Serial.printf("  DISPLAY: %s / %s\n", line1, line2);
}

/* ----------------------------------------------------------------- setup -- */

void setup() {
  Serial.begin(115200);
  delay(400);

  Serial.println();
  Serial.println("L-SIAMS RFID bench test");
  Serial.println("-----------------------");

  SPI.begin();
  rfid.PCD_Init();

  /* Version 0x00 or 0xFF means the reader is not answering at all — almost
   * always wiring, and almost always SS, RST or a 5 V supply that has already
   * destroyed the module. */
  byte version = rfid.PCD_ReadRegister(MFRC522::VersionReg);
  Serial.printf("MFRC522 version: 0x%02X %s\n", version,
                (version == 0x00 || version == 0xFF) ? "<-- NOT RESPONDING, check wiring" : "(ok)");

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
    Serial.println("Wi-Fi FAILED. Check the SSID and password, and that the");
    Serial.println("network is 2.4 GHz — the ESP32 cannot see 5 GHz networks.");
    return;
  }

  Serial.print("Wi-Fi ok, IP ");
  Serial.println(WiFi.localIP());

  /* Register the terminal with this MAC, and put the same value in
   * DEVICE_MAC above — the claim call compares them. */
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
  syncClockFromServer();

  Serial.println();
  Serial.println("Ready — tap a card.");
}

void loop() {
  String uid = readCardUid();

  if (uid.length() == 0) {
    delay(50);
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
