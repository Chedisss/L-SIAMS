/* ===========================================================================
 * L-SIAMS classroom terminal
 * ESP32 + MFRC522 (RFID) + AS608/R307 (fingerprint)
 * ---------------------------------------------------------------------------
 * One board runs a whole classroom. A teacher's finger opens the attendance
 * session; a student's card records against it; the server decides every
 * outcome. The terminal reports facts and obeys — it never judges whether a
 * tap is an arrival, whether somebody is late, or whether a session may open.
 * That is what keeps two terminals in one room consistent with each other, and
 * what stops a board with a wrong clock manufacturing attendance.
 *
 * Two rules the whole design follows:
 *
 *   Students cannot tap until a teacher has opened the session. This is
 *   enforced by the server, not here — a tap with no open session comes back
 *   SESSION_NOT_OPEN — but the terminal states it plainly so nobody stands
 *   there wondering why the card does nothing.
 *
 *   A fingerprint enrolled on ONE terminal works on ALL of them. The sensor
 *   can only match templates in its own flash, so at enrolment this board
 *   reads the template back out and uploads it; every other board pulls what
 *   it is missing and writes it into its own sensor. Without that, a teacher
 *   could open a register in one room and nowhere else.
 *
 * ---------------------------------------------------------------------------
 * WIRING
 *
 *   MFRC522 (SPI)                 AS608 / R307 (UART2, 57600)
 *     SDA/SS -> GPIO 5              TX  -> GPIO 17   (ESP32 receives)
 *     SCK    -> GPIO 18             RX  -> GPIO 16   (ESP32 transmits)
 *     MOSI   -> GPIO 23             VCC -> 3V3 for a bare AS608
 *     MISO   -> GPIO 19                    VIN for an R307 (it has a regulator)
 *     RST    -> GPIO 22             GND -> GND
 *     3.3V   -> 3V3
 *     GND    -> GND
 *
 *   The sensor pair is the REVERSE of the silkscreen, and that is deliberate:
 *   17/16 is the pair proven working on this hardware. UART2 is not fixed to
 *   16/17 in either direction — the ESP32 routes any pin to any UART signal —
 *   so only the wiring decides. PIN_FINGER_RX is where the ESP32 LISTENS, so
 *   the SENSOR'S TX wire goes there. Wired the other way both ends transmit,
 *   neither listens, and the sensor never answers with no error anywhere.
 *
 *   5 V on a bare AS608 destroys it. The MFRC522 is 3.3 V only, always.
 *
 * ---------------------------------------------------------------------------
 * SHARED POWER
 *
 *   Both modules want 3.3 V and the ESP32 has one 3V3 pin, so they share it.
 *   Do not stack two solder joints on that pin — the upper one takes all the
 *   strain and cracks, giving a connection that works on the bench and fails
 *   when the board is moved. Join the two wires to each other, run one wire to
 *   the pin.
 *
 *   Fit 100 uF + 100 nF across 3V3/GND at each module. The averages are fine;
 *   the peaks are not, and they coincide:
 *
 *       MFRC522   ~26 mA idle,  ~100 mA peak driving the RF field
 *       AS608     ~50 mA idle,  ~150 mA peak during capture
 *       ESP32    ~80-160 mA,    ~500 mA peak on a Wi-Fi transmit burst
 *
 *   A dip on that rail is what produces a brownout reset, a reader reporting
 *   a different version every read, and a sensor whose reply arrives corrupt
 *   — three symptoms that each look like a different fault. Power the board
 *   from a 1 A wall supply, never a laptop USB port.
 *
 * ---------------------------------------------------------------------------
 * SETUP
 *
 *   1. Edit the seven values in the block below — Wi-Fi, server address, and
 *      the four from the provisioning JSON. That block explains where each
 *      one comes from, and what update.bat does to them.
 *   2. Board: ESP32 Dev Module.  Serial Monitor: 115200.
 *   3. Libraries, by the exact name Library Manager shows:
 *        "MFRC522" by GithubCommunity            (NOT MFRC522v2 — different API)
 *        "Adafruit Fingerprint Sensor Library" by Adafruit
 *        "ArduinoJson" by Benoit Blanchon        (6 or 7; both compile)
 * =========================================================================== */

#include <Arduino.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <SPI.h>
#include <MFRC522.h>
#include <Adafruit_Fingerprint.h>
#include <ArduinoJson.h>
#include <esp_system.h>
#include <esp_attr.h>
#if defined(__has_include)
#  if __has_include(<esp_mac.h>)
#    include <esp_mac.h>          /* esp_read_mac moved here in core 3.x */
#  endif
#endif
#include <time.h>
#include <sys/time.h>
#include "mbedtls/md.h"

/* ArduinoJson 7 made JsonDocument concrete and self-sizing. In 6 it is an
 * abstract base and only DynamicJsonDocument can be declared. Library Manager
 * installs whichever the sketch asks for and happily leaves an older one in
 * place; the failure under 6 reads "cannot declare variable to be of abstract
 * type 'JsonDocument'", which names nothing you would think to change. One
 * alias covers both. */
#if ARDUINOJSON_VERSION_MAJOR < 7
struct LsJson : public DynamicJsonDocument { LsJson() : DynamicJsonDocument(4096) {} };
#else
using LsJson = JsonDocument;
#endif

/* ===========================================================================
 * EDIT THESE SEVEN LINES, THEN UPLOAD
 * ---------------------------------------------------------------------------
 * Four come from the provisioning JSON you downloaded when you registered this
 * terminal on the Devices page. That download is the only copy of the API key
 * and HMAC secret that will ever exist, and downloading again rotates them, so
 * use the file you already have.
 *
 *      LS_DEVICE_ID    <- "device_id"    in the JSON
 *      LS_API_KEY      <- "api_key"
 *      LS_HMAC_SECRET  <- "hmac_secret"
 *      LS_CLAIM_TOKEN  <- "claim_token"   (single use; blank it once claimed)
 *
 * The other three you type yourself, in these shapes:
 *
 *      LS_WIFI_SSID    "StaffRoom-2G"
 *      LS_WIFI_PASS    "your wifi password"
 *      LS_SERVER_URL   "http://192.168.1.14:8080"
 *
 * LS_SERVER_URL is the one people get wrong. It must be the PC's LAN address
 * WITH the port — the address start.bat prints. Never localhost or 127.0.0.1,
 * which to this board mean this board, so the request never leaves it.
 *
 * ---------------------------------------------------------------------------
 * ONE WARNING, WORTH READING ONCE
 *
 * This file is tracked by git, and update.bat updates by replacing every
 * tracked file. Your values here are therefore replaced by the placeholders on
 * the next update — silently, which presents as a terminal that worked
 * yesterday and cannot find the Wi-Fi today.
 *
 * update.bat now saves a copy first, as
 *
 *      firmware\L_SIAMS_Bench\L_SIAMS_Bench.ino.your-copy
 *
 * so nothing is lost — but you do have to paste the seven values back out of
 * it after updating.
 *
 * To avoid that entirely: put the same seven #defines in a file called
 * secrets.h beside this one. It is gitignored, so updates leave it alone, and
 * anything defined there wins over the block below. Optional, and worth it
 * once you stop changing them.
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

static const char *WIFI_SSID   = LS_WIFI_SSID;
static const char *WIFI_PASS   = LS_WIFI_PASS;
static const char *SERVER_URL  = LS_SERVER_URL;
static const char *DEVICE_ID   = LS_DEVICE_ID;
static const char *API_KEY     = LS_API_KEY;
static const char *HMAC_SECRET = LS_HMAC_SECRET;
static const char *CLAIM_TOKEN = LS_CLAIM_TOKEN;

/* ------------------------------------------------------------------ pins -- */

#define PIN_RFID_SS        5
#define PIN_RFID_RST       22
#define PIN_FINGER_RX      17    /* ESP32 listens here — SENSOR TX wire */
#define PIN_FINGER_TX      16    /* ESP32 speaks here  — SENSOR RX wire */
#define FINGERPRINT_BAUD   57600

/* ---------------------------------------------------------------- timing -- */

#define HEARTBEAT_MS       30000   /* server marks a terminal offline at 90 s */
#define ENROLL_POLL_MS      2000   /* somebody is standing and waiting        */
#define SYNC_POLL_MS       15000   /* background catch-up; blocks the loop    */
#define CARD_DEBOUNCE_MS    2500   /* a held card reports continuously        */
#define FINGER_COOLDOWN_MS  3000
#define PLACEMENT_TIMEOUT_MS 20000 /* per finger placement during enrolment   */
#define HTTP_TIMEOUT_MS     8000

#define FP_TEMPLATE_MAX     1024   /* 512 is real; headroom for clones        */

/* ----------------------------------------------------------------- state -- */

MFRC522              rfid(PIN_RFID_SS, PIN_RFID_RST);
HardwareSerial       fingerSerial(2);
Adafruit_Fingerprint finger(&fingerSerial);

static bool     fingerReady   = false;
static bool     rfidReady     = false;
static bool     clockSet      = false;
static bool     busy          = false;   /* an enrolment owns the board */

static uint32_t lastHeartbeat = 0;
static uint32_t lastFpPoll    = 0;
static uint32_t lastCardPoll  = 0;
static uint32_t lastSyncPoll  = 0;
static uint32_t lastFingerAt  = 0;
static uint32_t lastTapAt     = 0;
static String   lastUid       = "";
static uint32_t bootMillis    = 0;

/* =========================================================================
 * Small helpers
 * ========================================================================= */

static String toHexLower(const uint8_t *data, size_t len) {
  static const char *digits = "0123456789abcdef";
  String out;
  out.reserve(len * 2);
  for (size_t i = 0; i < len; i++) {
    out += digits[data[i] >> 4];
    out += digits[data[i] & 0x0F];
  }
  return out;
}

/* esp_random() is the hardware RNG; the Arduino random() is a seeded PRNG and
 * would produce the same nonce sequence on every board after a power cut. A
 * repeated nonce is refused by the server as a replay. */
static String randomHex(size_t bytes) {
  String out;
  out.reserve(bytes * 2);
  for (size_t i = 0; i < bytes; i++) {
    uint8_t b = (uint8_t) (esp_random() & 0xFF);
    out += toHexLower(&b, 1);
  }
  return out;
}

/* The board's own MAC, straight out of eFuse.
 *
 * NOT WiFi.macAddress(). That reads through the Wi-Fi driver, and WiFi.mode()
 * only *requests* the mode change — the driver comes up a moment later. Read
 * it too soon and you get 00:00:00:00:00:00, which is not an error anybody
 * would recognise as a timing problem: it looks like a dead board, and it is
 * the value you would then type into the Devices page.
 *
 * esp_read_mac() reads the factory value out of eFuse. No driver, no radio, no
 * network, no waiting — and it is the same address the STA interface ends up
 * using, so what is registered matches what the claim later presents. */
static String boardMac() {
  uint8_t mac[6] = { 0, 0, 0, 0, 0, 0 };
  esp_read_mac(mac, ESP_MAC_WIFI_STA);

  char buf[18];
  snprintf(buf, sizeof(buf), "%02X:%02X:%02X:%02X:%02X:%02X",
           mac[0], mac[1], mac[2], mac[3], mac[4], mac[5]);

  return String(buf);
}

static String sha256Hex(const String &message) {
  uint8_t digest[32];
  mbedtls_md_context_t ctx;
  mbedtls_md_init(&ctx);
  mbedtls_md_setup(&ctx, mbedtls_md_info_from_type(MBEDTLS_MD_SHA256), 0);
  mbedtls_md_starts(&ctx);
  mbedtls_md_update(&ctx, (const unsigned char *) message.c_str(), message.length());
  mbedtls_md_finish(&ctx, digest);
  mbedtls_md_free(&ctx);
  return toHexLower(digest, sizeof(digest));
}

static String hmacSha256Hex(const String &message, const char *key) {
  uint8_t digest[32];
  mbedtls_md_context_t ctx;
  mbedtls_md_init(&ctx);
  mbedtls_md_setup(&ctx, mbedtls_md_info_from_type(MBEDTLS_MD_SHA256), 1);
  mbedtls_md_hmac_starts(&ctx, (const unsigned char *) key, strlen(key));
  mbedtls_md_hmac_update(&ctx, (const unsigned char *) message.c_str(), message.length());
  mbedtls_md_hmac_finish(&ctx, digest);
  mbedtls_md_free(&ctx);
  return toHexLower(digest, sizeof(digest));
}

/* UUIDv4 for the attendance request_id. The server records it for 24 h and
 * replays the ORIGINAL response for a repeat, so retrying after a timeout can
 * never record a second tap. */
static String generateUuid() {
  uint8_t b[16];
  for (uint8_t i = 0; i < 16; i++) b[i] = (uint8_t) (esp_random() & 0xFF);
  b[6] = (uint8_t) ((b[6] & 0x0F) | 0x40);
  b[8] = (uint8_t) ((b[8] & 0x3F) | 0x80);
  String h = toHexLower(b, 16);
  return h.substring(0, 8) + "-" + h.substring(8, 12) + "-" + h.substring(12, 16)
       + "-" + h.substring(16, 20) + "-" + h.substring(20);
}

static String jsonToString(const LsJson &document) {
  String out;
  serializeJson(document, out);
  return out;
}

/* =========================================================================
 * Talking to the server
 * ========================================================================= */

/* Every request is signed over method, path, device id, timestamp, nonce and
 * a hash of the body — the same canonical string the server rebuilds. The
 * nonce is single use, so a captured request cannot be replayed. */
static int signedRequest(const char *method, const String &path, const String &body,
                         LsJson *responseOut, const String &requestId = "") {
  if (WiFi.status() != WL_CONNECTED) return -1;

  String url   = String(SERVER_URL) + path;
  bool   isTls = url.startsWith("https://");

  WiFiClient       plain;
  WiFiClientSecure secure;
  HTTPClient       http;

  if (isTls) {
    secure.setInsecure();
    if (!http.begin(secure, url)) return -2;
  } else {
    if (!http.begin(plain, url)) return -2;
  }

  String timestamp = String((long) time(nullptr));
  String nonce     = randomHex(16);
  String canonical = String(method) + "\n" + path + "\n" + DEVICE_ID + "\n"
                   + timestamp + "\n" + nonce + "\n" + sha256Hex(body);

  http.setTimeout(HTTP_TIMEOUT_MS);
  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-LSIAMS-Device-Id", DEVICE_ID);
  http.addHeader("X-LSIAMS-Api-Key",   API_KEY);
  http.addHeader("X-LSIAMS-Timestamp", timestamp);
  http.addHeader("X-LSIAMS-Nonce",     nonce);
  http.addHeader("X-LSIAMS-Signature", hmacSha256Hex(canonical, HMAC_SECRET));
  if (requestId.length()) http.addHeader("X-LSIAMS-Request-Id", requestId);

  int status = (strcmp(method, "GET") == 0) ? http.GET() : http.POST(body);

  if (status > 0 && responseOut != nullptr) {
    deserializeJson(*responseOut, http.getString());
  }

  http.end();
  return status;
}

/* The claim is the one request that cannot be signed: the board has no proven
 * key until the server accepts it. */
static int unsignedPost(const String &path, const String &body, LsJson *responseOut) {
  if (WiFi.status() != WL_CONNECTED) return -1;

  String url   = String(SERVER_URL) + path;
  bool   isTls = url.startsWith("https://");

  WiFiClient       plain;
  WiFiClientSecure secure;
  HTTPClient       http;

  if (isTls) {
    secure.setInsecure();
    if (!http.begin(secure, url)) return -2;
  } else {
    if (!http.begin(plain, url)) return -2;
  }

  http.setTimeout(HTTP_TIMEOUT_MS);
  http.addHeader("Content-Type", "application/json");

  int status = http.POST(body);

  if (status > 0 && responseOut != nullptr) {
    deserializeJson(*responseOut, http.getString());
  }

  http.end();
  return status;
}

/* The board has no battery-backed clock, and every signature covers a
 * timestamp the server checks against a 30-second window. Without this the
 * first request of every boot is refused as expired. */
static bool syncClockFromServer() {
  LsJson response;
  int    status = signedRequest("GET", "/api/device/time", "", &response);

  if (status != 200) {
    Serial.printf("Clock: server refused the time request (HTTP %d)\n", status);
    return false;
  }

  long epoch = response["data"]["server_epoch"] | 0L;
  if (epoch <= 0) return false;

  struct timeval tv = { .tv_sec = (time_t) epoch, .tv_usec = 0 };
  settimeofday(&tv, nullptr);
  clockSet = true;

  Serial.printf("Clock: set from server (epoch %ld)\n", epoch);
  return true;
}

static bool claimDevice() {
  if (strlen(CLAIM_TOKEN) == 0) return true;   /* already claimed */

  LsJson body;
  body["claim_token"] = CLAIM_TOKEN;
  body["device_id"]   = DEVICE_ID;
  body["mac_address"] = boardMac();

  LsJson response;
  int    status = unsignedPost("/api/device/claim", jsonToString(body), &response);

  if (status == 200 || status == 201) {
    Serial.println("Claim: accepted — this terminal is now active.");
    return true;
  }

  const char *code = response["code"] | "";

  Serial.printf("Claim: REFUSED (HTTP %d, %s)\n", status, code);
  Serial.printf("       %s\n", (const char *) (response["message"] | ""));

  /* Two refusals mean the opposite things and need opposite responses, so
   * they are told apart here rather than left to be guessed. */
  if (strcmp(code, "CLAIM_TOKEN_USED") == 0) {
    Serial.println("       This token was already spent — the board is claimed.");
    Serial.println("       Blank LS_CLAIM_TOKEN in secrets.h and re-flash.");
    return true;
  }
  if (strcmp(code, "CLAIM_IDENTITY_MISMATCH") == 0) {
    Serial.println("       The MAC registered on the server is not this board's.");
    Serial.printf("       This board is %s — correct it on the Devices page.\n",
                  boardMac().c_str());
  }
  if (status < 0) {
    Serial.println("       The server could not be reached at all. Check that start.bat is");
    Serial.println("       running, that LS_SERVER_URL is the PC's LAN address rather than");
    Serial.println("       localhost, and that Windows Firewall allows the port.");
  }

  return false;
}

static void sendHeartbeat() {
  LsJson body;
  body["firmware"]    = "2.0.0";
  body["uptime"]      = (millis() - bootMillis) / 1000;
  body["free_heap"]   = ESP.getFreeHeap();
  body["wifi_signal"] = WiFi.RSSI();
  body["queue"]       = 0;

  /* What the sensor itself holds, so the server can spot the case where its
   * records and the sensor's flash have drifted apart. That mismatch used to
   * be invisible: the Fingerprints page read "Active" for everybody while the
   * reader answered NOT RECOGNISED, with nothing connecting the two facts. */
  if (fingerReady && finger.getTemplateCount() == FINGERPRINT_OK) {
    body["fp_templates"] = finger.templateCount;
  }

  signedRequest("POST", "/api/device/heartbeat", jsonToString(body), nullptr);
}

/* =========================================================================
 * Fingerprint sensor: raw template transfer
 *
 * The Adafruit library cannot move a template. getModel() sends the UpChar
 * command and then discards the bytes the sensor returns, and there is no
 * DownChar at all. Both directions are written against the R30x packet
 * protocol:
 *
 *     EF 01 | addr(4) | PID(1) | length(2) | payload | checksum(2)
 *
 *   length counts the payload AND the two checksum bytes.
 *   checksum is the sum of PID, both length bytes and every payload byte.
 *   PID: 01 command   02 data   07 acknowledge   08 last data packet
 * ========================================================================= */

#define FP_ADDR         0xFFFFFFFFUL
#define FP_PID_COMMAND  0x01
#define FP_PID_DATA     0x02
#define FP_PID_ACK      0x07
#define FP_PID_END      0x08

static void fpSendPacket(uint8_t pid, const uint8_t *payload, uint16_t len) {
  uint16_t declared = len + 2;
  uint16_t checksum = pid + (declared >> 8) + (declared & 0xFF);

  fingerSerial.write((uint8_t) 0xEF);
  fingerSerial.write((uint8_t) 0x01);
  fingerSerial.write((uint8_t) (FP_ADDR >> 24));
  fingerSerial.write((uint8_t) (FP_ADDR >> 16));
  fingerSerial.write((uint8_t) (FP_ADDR >> 8));
  fingerSerial.write((uint8_t) (FP_ADDR & 0xFF));
  fingerSerial.write(pid);
  fingerSerial.write((uint8_t) (declared >> 8));
  fingerSerial.write((uint8_t) (declared & 0xFF));

  for (uint16_t i = 0; i < len; i++) {
    fingerSerial.write(payload[i]);
    checksum += payload[i];
  }

  fingerSerial.write((uint8_t) (checksum >> 8));
  fingerSerial.write((uint8_t) (checksum & 0xFF));
  fingerSerial.flush();
}

static int fpReadByte(uint32_t timeoutMs) {
  uint32_t started = millis();
  while (millis() - started < timeoutMs) {
    if (fingerSerial.available()) return fingerSerial.read();
    delay(1);
  }
  return -1;
}

/* A bad checksum stops the transfer rather than being passed on. A corrupted
 * template written into another sensor produces a finger that enrols cleanly
 * and never matches — which reads as the teacher's finger being at fault. */
static bool fpReadPacket(uint8_t *pid, uint8_t *buf, uint16_t maxLen,
                         uint16_t *lenOut, uint32_t timeoutMs) {
  uint32_t started = millis();
  int      b       = 0;

  /* Hunt for the header: a previous timeout can leave stray bytes behind. */
  while (millis() - started < timeoutMs) {
    b = fpReadByte(timeoutMs);
    if (b < 0)    return false;
    if (b != 0xEF) continue;
    b = fpReadByte(timeoutMs);
    if (b == 0x01) break;
  }
  if (b != 0x01) return false;

  for (uint8_t i = 0; i < 4; i++) {
    if (fpReadByte(timeoutMs) < 0) return false;   /* address, not checked */
  }

  int p  = fpReadByte(timeoutMs);
  int hi = fpReadByte(timeoutMs);
  int lo = fpReadByte(timeoutMs);
  if (p < 0 || hi < 0 || lo < 0) return false;

  uint16_t declared = ((uint16_t) hi << 8) | (uint16_t) lo;
  if (declared < 2) return false;

  uint16_t payloadLen = declared - 2;
  if (payloadLen > maxLen) return false;

  uint16_t checksum = (uint16_t) p + (uint16_t) hi + (uint16_t) lo;

  for (uint16_t i = 0; i < payloadLen; i++) {
    int v = fpReadByte(timeoutMs);
    if (v < 0) return false;
    buf[i]    = (uint8_t) v;
    checksum += (uint8_t) v;
  }

  int chi = fpReadByte(timeoutMs);
  int clo = fpReadByte(timeoutMs);
  if (chi < 0 || clo < 0) return false;
  if ((((uint16_t) chi << 8) | (uint16_t) clo) != checksum) return false;

  *pid    = (uint8_t) p;
  *lenOut = payloadLen;
  return true;
}

/* Read the template in character buffer 1. Call loadModel(slot) first — that
 * is what puts a stored template there. */
static bool fpReadTemplate(uint8_t *out, size_t max, size_t *lenOut) {
  while (fingerSerial.available()) fingerSerial.read();

  uint8_t command[2] = { 0x08, 0x01 };            /* UpChar, buffer 1 */
  fpSendPacket(FP_PID_COMMAND, command, 2);

  uint8_t  pid = 0, buf[288];
  uint16_t len = 0;

  if (!fpReadPacket(&pid, buf, sizeof(buf), &len, 2000)) return false;
  if (pid != FP_PID_ACK || len < 1 || buf[0] != 0x00)    return false;

  size_t total = 0;

  /* The sensor chooses its packet size (32, 64, 128 or 256), so this loops
   * until the end-of-data packet rather than counting to a fixed number. */
  for (uint8_t packets = 0; packets < 64; packets++) {
    if (!fpReadPacket(&pid, buf, sizeof(buf), &len, 2000)) return false;
    if (pid != FP_PID_DATA && pid != FP_PID_END)          return false;
    if (total + len > max)                                return false;

    memcpy(out + total, buf, len);
    total += len;

    if (pid == FP_PID_END) {
      *lenOut = total;
      return total >= 256;     /* smaller is a truncated transfer */
    }
  }

  return false;
}

/* Write a template into character buffer 1. storeModel(slot) then commits it
 * to flash — this call alone changes nothing permanent. */
static bool fpWriteTemplate(const uint8_t *data, size_t len) {
  while (fingerSerial.available()) fingerSerial.read();

  uint8_t command[2] = { 0x09, 0x01 };            /* DownChar, buffer 1 */
  fpSendPacket(FP_PID_COMMAND, command, 2);

  uint8_t  pid = 0, buf[64];
  uint16_t got = 0;

  if (!fpReadPacket(&pid, buf, sizeof(buf), &got, 2000)) return false;
  if (pid != FP_PID_ACK || got < 1 || buf[0] != 0x00)    return false;

  /* 128 is accepted by every module in this family. Larger packets are legal
   * in the protocol and refused by some clones. */
  const size_t chunk = 128;

  for (size_t sent = 0; sent < len; sent += chunk) {
    size_t take = (len - sent > chunk) ? chunk : (len - sent);
    bool   last = (sent + take >= len);
    fpSendPacket(last ? FP_PID_END : FP_PID_DATA, data + sent, (uint16_t) take);
    delay(10);
  }

  return true;
}

/* ---- base64, for carrying a template through JSON ------------------------ */

static const char FP_B64[] = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";

static String fpBase64Encode(const uint8_t *data, size_t len) {
  String out;
  out.reserve(((len + 2) / 3) * 4 + 1);

  for (size_t i = 0; i < len; i += 3) {
    uint32_t block = (uint32_t) data[i] << 16;
    if (i + 1 < len) block |= (uint32_t) data[i + 1] << 8;
    if (i + 2 < len) block |= (uint32_t) data[i + 2];

    out += FP_B64[(block >> 18) & 0x3F];
    out += FP_B64[(block >> 12) & 0x3F];
    out += (i + 1 < len) ? FP_B64[(block >> 6) & 0x3F] : '=';
    out += (i + 2 < len) ? FP_B64[block & 0x3F]        : '=';
  }

  return out;
}

static size_t fpBase64Decode(const char *text, uint8_t *out, size_t max) {
  uint32_t block = 0;
  int      bits  = 0;
  size_t   total = 0;

  for (const char *p = text; *p; p++) {
    int v;
    if      (*p >= 'A' && *p <= 'Z') v = *p - 'A';
    else if (*p >= 'a' && *p <= 'z') v = *p - 'a' + 26;
    else if (*p >= '0' && *p <= '9') v = *p - '0' + 52;
    else if (*p == '+')              v = 62;
    else if (*p == '/')              v = 63;
    else continue;                       /* padding, whitespace, newlines */

    block = (block << 6) | (uint32_t) v;
    bits += 6;

    if (bits >= 8) {
      bits -= 8;
      if (total >= max) return 0;
      out[total++] = (uint8_t) ((block >> bits) & 0xFF);
    }
  }

  return total;
}

/* =========================================================================
 * Fingerprint enrolment, driven from the browser
 * ========================================================================= */

static void reportEnrollStage(int requestId, const char *stage, const char *message) {
  LsJson body;
  body["request_id"] = requestId;
  body["stage"]      = stage;
  if (message && strlen(message)) body["message"] = message;

  signedRequest("POST", "/api/fingerprint/enrollment/progress", jsonToString(body), nullptr);
}

static void reportEnrollFailed(int requestId, const char *reason) {
  LsJson body;
  body["request_id"] = requestId;
  body["reason"]     = reason;

  signedRequest("POST", "/api/fingerprint/enrollment/failed", jsonToString(body), nullptr);
  Serial.printf("Enrol: failed — %s\n", reason);
}

/* One placement, into the given character buffer. */
static bool captureFinger(int requestId, const char *stage, uint8_t buffer) {
  reportEnrollStage(requestId, stage, nullptr);

  uint32_t started = millis();

  while (millis() - started < PLACEMENT_TIMEOUT_MS) {
    uint8_t r = finger.getImage();

    if (r == FINGERPRINT_NOFINGER) { delay(50); continue; }

    if (r != FINGERPRINT_OK) {
      Serial.printf("       capture failed (code %d)\n", r);
      return false;
    }

    if (finger.image2Tz(buffer) != FINGERPRINT_OK) {
      Serial.println("       image not usable — press flatter, cover more of the window");
      reportEnrollStage(requestId, stage, "Press flatter and cover more of the sensor.");
      delay(400);
      continue;
    }

    Serial.println("       captured");
    return true;
  }

  return false;
}

static void runEnrollment(int requestId, int slot, const char *teacherName) {
  busy = true;
  Serial.printf("\nEnrol: %s into slot %d\n", teacherName, slot);

  if (!captureFinger(requestId, "place_finger", 1)) {
    reportEnrollFailed(requestId, "No usable fingerprint was captured in time.");
    busy = false;
    return;
  }

  Serial.println("       lift the finger off...");
  reportEnrollStage(requestId, "remove_finger", nullptr);
  while (finger.getImage() != FINGERPRINT_NOFINGER) delay(50);

  if (!captureFinger(requestId, "place_again", 2)) {
    reportEnrollFailed(requestId, "The second scan was not captured in time.");
    busy = false;
    return;
  }

  reportEnrollStage(requestId, "storing", nullptr);

  if (finger.createModel() != FINGERPRINT_OK) {
    reportEnrollFailed(requestId, "The two scans did not match. Use the same finger at the same angle.");
    busy = false;
    return;
  }

  if (finger.storeModel(slot) != FINGERPRINT_OK) {
    reportEnrollFailed(requestId, "The sensor refused to store the template.");
    busy = false;
    return;
  }

  /* Prove it can be read back before calling this a success.
   *
   * storeModel() returning OK is the sensor saying it ACCEPTED the write, not
   * that anything is retrievable afterwards. That gap produced enrolments
   * that announced DONE, pushed the template count up, and then failed every
   * search — the first sign being a teacher told their finger was unknown,
   * minutes later. loadModel() pulls this specific slot back, so it fails
   * when the slot is empty or unreadable. */
  if (finger.loadModel(slot) != FINGERPRINT_OK) {
    Serial.printf("       slot %d says stored but reads back EMPTY\n", slot);
    reportEnrollFailed(requestId,
      "The sensor reported the template as stored but cannot read it back. "
      "Power-cycle the sensor, wipe it, and enrol again.");
    busy = false;
    return;
  }

  /* loadModel() left the template in buffer 1, so read the bytes out and send
   * them with the completion. This is what lets every OTHER terminal be given
   * the same template. A failure here is not an enrolment failure — this room
   * works either way; only the copy to other rooms is lost. */
  static uint8_t templateBytes[FP_TEMPLATE_MAX];
  size_t         templateLen = 0;
  String         templateB64;

  if (fpReadTemplate(templateBytes, sizeof(templateBytes), &templateLen)) {
    templateB64 = fpBase64Encode(templateBytes, templateLen);
    Serial.printf("       read %u template bytes back for syncing\n", (unsigned) templateLen);
  } else {
    Serial.println("       the template could not be read off the sensor.");
    Serial.println("       This terminal will recognise the finger; other rooms cannot be");
    Serial.println("       given a copy of it.");
  }

  finger.getTemplateCount();

  LsJson body;
  body["request_id"]         = requestId;
  body["sensor_template_id"] = slot;
  body["sample_count"]       = 2;
  if (templateB64.length()) body["template"] = templateB64;

  LsJson response;
  int    status = signedRequest("POST", "/api/fingerprint/enrollment/complete",
                                jsonToString(body), &response);

  if (status == 200 || status == 201) {
    Serial.printf("       ENROLLED — %d template(s) on this sensor now\n", finger.templateCount);
  } else {
    Serial.printf("       the server refused the completion (HTTP %d, %s)\n",
                  status, (const char *) (response["code"] | "-"));
  }

  busy = false;
}

/* Slots holding a template nothing owns — a registration captured and then
 * abandoned. Only the terminal can clear them, because the server cannot
 * reach into the sensor's flash. */
static void discardOrphanSlots(JsonArrayConst slots) {
  for (JsonVariantConst entry : slots) {
    int slot = entry.as<int>();
    if (slot <= 0) continue;

    if (finger.deleteModel(slot) == FINGERPRINT_OK) {
      Serial.printf("Sensor: cleared abandoned slot %d\n", slot);

      LsJson body;
      body["sensor_template_id"] = slot;
      signedRequest("POST", "/api/fingerprint/enrollment/discarded", jsonToString(body), nullptr);
    }
  }
}

static void pollEnrollment() {
  if (!fingerReady || busy) return;
  if (millis() - lastFpPoll < ENROLL_POLL_MS) return;
  lastFpPoll = millis();

  LsJson response;
  if (signedRequest("GET", "/api/fingerprint/enrollment", "", &response) != 200) return;

  JsonArrayConst discard = response["data"]["discard_slots"];
  if (!discard.isNull()) discardOrphanSlots(discard);

  JsonVariantConst enrolment = response["data"]["enrollment"];
  if (enrolment.isNull()) return;

  int         requestId = enrolment["request_id"] | 0;
  int         slot      = enrolment["sensor_template_id"] | 0;
  const char *name      = enrolment["teacher_name"] | "teacher";

  if (requestId > 0 && slot > 0) runEnrollment(requestId, slot, name);
}

/* =========================================================================
 * Collecting templates enrolled on other terminals
 * ========================================================================= */

static void pollTemplateSync() {
  if (!fingerReady || busy) return;
  if (millis() - lastSyncPoll < SYNC_POLL_MS) return;
  lastSyncPoll = millis();

  LsJson response;
  if (signedRequest("GET", "/api/fingerprint/sync", "", &response) != 200) return;

  JsonVariantConst tpl = response["data"]["template"];
  if (tpl.isNull()) return;

  int         slot = tpl["slot"] | 0;
  const char *name = tpl["teacher_name"] | "";
  const char *data = tpl["data"] | "";

  if (slot <= 0 || !strlen(data)) return;

  Serial.printf("\nSync: writing %s into slot %d\n", name, slot);

  static uint8_t incoming[FP_TEMPLATE_MAX];
  size_t         len = fpBase64Decode(data, incoming, sizeof(incoming));

  /* Every outcome is reported. A slot left pending is offered again on the
   * next poll forever, and a terminal silently refusing the same template
   * every fifteen seconds is invisible from the server. */
  bool        ok     = false;
  const char *reason = "";

  if (len < 256) {
    reason = "The template did not decode to a usable size.";
    Serial.println("      the template did not decode to a sensible size");
  } else if (!fpWriteTemplate(incoming, len)) {
    reason = "The sensor refused the template transfer.";
    Serial.println("      the sensor refused the transfer");
  } else if (finger.storeModel(slot) != FINGERPRINT_OK) {
    reason = "The sensor refused to store the template.";
    Serial.printf("      the sensor refused to store into slot %d\n", slot);
  } else if (finger.loadModel(slot) != FINGERPRINT_OK) {
    reason = "Stored but reads back empty; the sensor flash is not accepting writes.";
    Serial.printf("      slot %d says stored but reads back empty\n", slot);
  } else {
    ok = true;
    finger.getTemplateCount();
    Serial.printf("      stored — %d template(s) on this sensor now\n", finger.templateCount);
  }

  LsJson body;
  body["slot"]   = slot;
  body["stored"] = ok;
  if (!ok) body["reason"] = reason;

  signedRequest("POST", "/api/fingerprint/sync/stored", jsonToString(body), nullptr);
}

/* =========================================================================
 * A teacher's finger opens the session
 * ========================================================================= */

static void handleFingerprint() {
  if (!fingerReady || busy) return;
  if (millis() - lastFingerAt < FINGER_COOLDOWN_MS) return;
  if (finger.getImage() != FINGERPRINT_OK) return;

  lastFingerAt = millis();

  if (finger.image2Tz() != FINGERPRINT_OK) {
    Serial.println("\nFinger: image not usable — press flatter and cover more of the window");
    return;
  }

  /* fingerFastSearch() sends HighSpeedSearch (0x1B). Plenty of sensors sold
   * as AS608 or R307 are clones that do not implement it, or cover a narrower
   * page range than they claim, and answer with an error rather than a polite
   * "no match" — so every enrolled finger looks unknown. The ordinary Search
   * (0x04) is the same operation without the optimisation, and falling back
   * separates a clone from a genuinely unknown finger. */
  uint8_t search = finger.fingerFastSearch();
  if (search != FINGERPRINT_OK && search != FINGERPRINT_NOTFOUND) {
    search = finger.fingerSearch();
  }

  if (search == FINGERPRINT_NOTFOUND) {
    Serial.println("\nFinger: NOT RECOGNISED — this print is not enrolled on this terminal.");
    return;
  }

  if (search != FINGERPRINT_OK) {
    /* Not "unknown finger" — the sensor could not complete the search. Saying
     * so points at power and wiring rather than sending somebody off to enrol
     * the same finger again, which cannot help. */
    Serial.printf("\nFinger: the sensor could not search (code %d)\n", search);
    Serial.println("        A sensor fault, not an unknown finger. Enrolling again will not help.");
    Serial.println("        Check the 3.3 V supply, the shared GND, and the RX/TX pair, and fit");
    Serial.println("        100 uF + 100 nF across 3V3/GND at the sensor.");
    return;
  }

  Serial.printf("\nFinger: matched slot %d (confidence %d)\n", finger.fingerID, finger.confidence);

  if (!clockSet && !syncClockFromServer()) {
    Serial.println("        no clock — cannot sign the request");
    return;
  }

  LsJson body;
  body["fingerprint_id"] = finger.fingerID;
  body["confidence"]     = finger.confidence;

  LsJson response;
  int    status = signedRequest("POST", "/api/attendance/start",
                                jsonToString(body), &response, generateUuid());

  if (status == 200 || status == 201) {
    Serial.printf("        SESSION OPEN — %s with %s, roster %d\n",
                  (const char *) (response["data"]["subject_code"] | "?"),
                  (const char *) (response["data"]["section_code"] | "?"),
                  (int) (response["data"]["roster_count"] | 0));
    Serial.println("        Students may tap their cards now.");
    return;
  }

  const char *code = response["code"] | "-";

  Serial.printf("        refused (HTTP %d, %s)\n", status, code);
  Serial.printf("        %s\n", (const char *) (response["message"] | ""));

  if (strcmp(code, "FINGERPRINT_WRONG_TERMINAL") == 0) {
    Serial.println("        This finger is enrolled on a different terminal. It will arrive");
    Serial.println("        here within a minute once the sync catches up, or enrol again.");
  }
}

/* =========================================================================
 * Card issuance, driven from the browser
 * ========================================================================= */

static String readCardUid() {
  if (!rfidReady) return String();
  if (!rfid.PICC_IsNewCardPresent()) return String();
  if (!rfid.PICC_ReadCardSerial())   return String();

  String uid = toHexLower(rfid.uid.uidByte, rfid.uid.size);
  uid.toUpperCase();

  rfid.PICC_HaltA();
  rfid.PCD_StopCrypto1();

  return uid;
}

static void runCardEnrollment(int requestId, const char *label, int waitSeconds) {
  busy = true;
  Serial.printf("\nCard: waiting for a card for %s (%d s)\n", label, waitSeconds);

  {
    LsJson body;
    body["request_id"] = requestId;
    body["stage"]      = "present_card";
    signedRequest("POST", "/api/rfid/enrollment/progress", jsonToString(body), nullptr);
  }

  uint32_t started = millis();
  String   uid;

  while (millis() - started < (uint32_t) waitSeconds * 1000UL) {
    uid = readCardUid();
    if (uid.length()) break;
    delay(60);
  }

  if (uid.length() == 0) {
    LsJson body;
    body["request_id"] = requestId;
    body["reason"]     = "No card was presented in time.";
    signedRequest("POST", "/api/rfid/enrollment/failed", jsonToString(body), nullptr);
    Serial.println("      timed out — no card presented");
    busy = false;
    return;
  }

  LsJson body;
  body["request_id"] = requestId;
  body["card_uid"]   = uid;

  LsJson response;
  int    status = signedRequest("POST", "/api/rfid/enrollment/captured",
                                jsonToString(body), &response);

  if (status == 200 || status == 201) {
    Serial.printf("      ISSUED — %s\n", uid.c_str());
  } else {
    Serial.printf("      refused (HTTP %d, %s) %s\n", status,
                  (const char *) (response["code"] | "-"),
                  (const char *) (response["message"] | ""));
  }

  /* The same card is still against the reader. Without this the loop reads it
   * again the moment issuance finishes and reports it as a tap. */
  lastUid   = uid;
  lastTapAt = millis();
  busy      = false;
}

static void pollCardEnrollment() {
  if (!rfidReady || busy) return;
  if (millis() - lastCardPoll < ENROLL_POLL_MS) return;
  lastCardPoll = millis();

  LsJson response;
  if (signedRequest("GET", "/api/rfid/enrollment", "", &response) != 200) return;

  JsonVariantConst enrolment = response["data"]["enrollment"];
  if (enrolment.isNull()) return;

  int         requestId = enrolment["request_id"] | 0;
  const char *label     = enrolment["label"] | "a student";
  int         wait      = enrolment["wait_seconds"] | 45;

  if (requestId > 0) runCardEnrollment(requestId, label, wait);
}

/* =========================================================================
 * A student's card records attendance
 * ========================================================================= */

static void sendTap(const String &uid) {
  String requestId = generateUuid();

  LsJson body;
  body["rfid_uid"]   = uid;
  body["request_id"] = requestId;

  LsJson response;
  int    status = signedRequest("POST", "/api/attendance/tap",
                                jsonToString(body), &response, requestId);

  const char *code = response["code"] | "";

  /* One retry after a clock correction. A device powered off for a while
   * drifts, and re-reading the epoch is cheaper than failing a real tap. The
   * request_id is unchanged, so the server cannot record it twice. */
  if (strcmp(code, "TIMESTAMP_EXPIRED") == 0) {
    Serial.println("      timestamp rejected — resyncing the clock and retrying");
    if (syncClockFromServer()) {
      status = signedRequest("POST", "/api/attendance/tap",
                             jsonToString(body), &response, requestId);
      code = response["code"] | "";
    }
  }

  if (status == 200 || status == 201) {
    Serial.printf("      RECORDED: %s\n", (const char *) (response["message"] | ""));
    return;
  }

  Serial.printf("      refused (HTTP %d, %s)\n", status, code);
  Serial.printf("      %s\n", (const char *) (response["message"] | ""));

  /* The rule this terminal exists to enforce, said in the place somebody is
   * standing when it bites them. */
  if (strcmp(code, "SESSION_NOT_OPEN") == 0) {
    Serial.println("      -> A teacher must open the session first, by scanning their");
    Serial.println("         finger on this terminal. Until then no card will record.");
  }
}

/* =========================================================================
 * Startup
 * ========================================================================= */

static bool checkConfig() {
  struct { const char *value; const char *name; } required[] = {
    { WIFI_SSID,   "LS_WIFI_SSID"   },
    { SERVER_URL,  "LS_SERVER_URL"  },
    { DEVICE_ID,   "LS_DEVICE_ID"   },
    { API_KEY,     "LS_API_KEY"     },
    { HMAC_SECRET, "LS_HMAC_SECRET" },
  };
  /* Every placeholder starts with PASTE_ on purpose.
   *
   * The previous set used realistic-looking examples, and one of them was not
   * an example at all: the server issues DEV-{year}-0001 to the first terminal
   * registered, so "DEV-2026-0001" was simultaneously the placeholder AND a
   * real device id. A correctly configured board was told its device id was
   * still a placeholder, with no way to tell the difference.
   *
   * "http://192.168.0.100:8080" had the same problem waiting — that is a
   * perfectly ordinary LAN address for a PC to have.
   *
   * A value nobody would ever legitimately hold cannot collide. The format
   * examples moved into the comment block above, where they inform without
   * being mistaken for data. */
  const char *placeholders[] = {
    "PASTE_WIFI_NAME", "PASTE_SERVER_URL", "PASTE_DEVICE_ID",
    "PASTE_API_KEY", "PASTE_HMAC_SECRET",
  };

  bool ok = true;

  for (uint8_t i = 0; i < 5; i++) {
    if (strcmp(required[i].value, placeholders[i]) == 0) {
      Serial.printf("Config: %s is still the placeholder value.\n", required[i].name);
      ok = false;
    }
  }

  /* Not a placeholder, so the loop above cannot see it — but it fails in the
   * most confusing way available: the board joins the Wi-Fi, reports nothing,
   * and shows as Offline with no error anywhere, because every request went to
   * the ESP32 itself. */
  if (ok && (strstr(SERVER_URL, "localhost") != nullptr
          || strstr(SERVER_URL, "127.0.0.1") != nullptr)) {
    Serial.println("Config: LS_SERVER_URL points at localhost.");
    Serial.println();
    Serial.println("  To this board, localhost and 127.0.0.1 mean THIS BOARD — so nothing");
    Serial.println("  it sends would ever reach your PC. Use the PC's LAN address instead,");
    Serial.println("  the one start.bat prints, such as http://192.168.1.14:8080");
    return false;
  }

  if (!ok) {
    Serial.println();
    Serial.println("  Edit the seven values at the top of this sketch, from the provisioning");
    Serial.println("  JSON you downloaded when you registered this terminal on the Devices");
    Serial.println("  page, plus your Wi-Fi and the server's LAN address.");
    Serial.println();
    Serial.println("  Not registered yet? The MAC printed above is what the Devices page");
    Serial.println("  asks for. Register with it, download the provisioning file, and come");
    Serial.println("  back here.");
    Serial.println();
    Serial.println("  LS_SERVER_URL must be the PC's LAN address with the port, such as");
    Serial.println("  http://192.168.1.14:8080 — never localhost, which to this board");
    Serial.println("  means this board.");
  }

  return ok;
}

static void startRfid() {
  SPI.begin();
  rfid.PCD_Init();
  delay(50);

  /* Read the version several times. A reader answering 0x92 every time is
   * wired correctly; one answering a DIFFERENT value each time has a
   * connection problem, not a configuration problem, and no amount of
   * retrying in the card code will change that. */
  byte version = rfid.PCD_ReadRegister(MFRC522::VersionReg);
  bool stable  = true;

  for (uint8_t i = 0; i < 8; i++) {
    delay(5);
    if (rfid.PCD_ReadRegister(MFRC522::VersionReg) != version) stable = false;
  }

  bool known = (version == 0x91 || version == 0x92 || version == 0x88
             || version == 0x90 || version == 0x12);

  Serial.printf("Reader: version 0x%02X %s\n", version,
                known ? (stable ? "(ok)" : "(known version but UNSTABLE)")
                      : "<-- not a version any MFRC522 reports");

  rfidReady = known && stable;

  if (!rfidReady) {
    Serial.println("        No card will read until this is fixed. Check, in order:");
    Serial.println("          1. VCC on 3.3 V. NEVER 5 V — it damages this module.");
    Serial.println("          2. GND shared with the ESP32.");
    Serial.println("          3. MISO 19, MOSI 23, SCK 18, SDA/SS 5, RST 22 — MISO and");
    Serial.println("             MOSI are the pair people swap.");
    Serial.println("          4. Re-seat every jumper; breadboard contacts are the usual cause.");
    Serial.println("          5. A 1 A wall supply, and 100 uF + 100 nF at each module.");
  }

  rfid.PCD_AntennaOn();
}

static void startFingerprint() {
  fingerSerial.begin(FINGERPRINT_BAUD, SERIAL_8N1, PIN_FINGER_RX, PIN_FINGER_TX);
  delay(100);

  fingerReady = finger.verifyPassword();

  if (!fingerReady) {
    Serial.printf("Sensor: NOT FOUND — the sensor's TX must reach GPIO %d and its RX GPIO %d.\n",
                  PIN_FINGER_RX, PIN_FINGER_TX);
    Serial.println("        They cross: the sensor's transmit goes to the pin this board");
    Serial.println("        receives on. Wired straight through, both talk and neither");
    Serial.println("        listens, and it fails silently.");
    Serial.println("        VCC must match the module: a bare AS608 wants 3.3 V, an R307");
    Serial.println("        wants 5 V on VIN. 5 V on a bare AS608 destroys it.");
    return;
  }

  finger.getTemplateCount();
  Serial.printf("Sensor: found — %d template(s) enrolled here\n", finger.templateCount);

  /* Capacity is the number the server's slot allocation has to respect, and
   * it is not the same on every module sold as an AS608 — 127, 162 and 1000
   * all exist. Reading it back also proves the link works in both directions:
   * verifyPassword() shows the sensor answering, this shows it answering with
   * its own data. */
  if (finger.getParameters() == FINGERPRINT_OK) {
    Serial.printf("        capacity %u templates, security level %u\n",
                  finger.capacity, finger.security_level);
  }
}

static void reportBoot() {
  /* Survives a reset but not a power cycle, so "boot #7" after one power-up
   * says the board has restarted itself six times. */
  static RTC_DATA_ATTR uint32_t bootCount = 0;
  bootCount++;

  esp_reset_reason_t why = esp_reset_reason();

  Serial.printf("Boot #%lu, reason: ", (unsigned long) bootCount);

  switch (why) {
    case ESP_RST_POWERON: Serial.println("power on (normal)"); break;
    case ESP_RST_SW:      Serial.println("software reset (normal after upload)"); break;
    case ESP_RST_EXT:     Serial.println("reset button"); break;
    case ESP_RST_PANIC:   Serial.println("*** CRASH — the sketch faulted and restarted ***"); break;
    case ESP_RST_INT_WDT:
    case ESP_RST_TASK_WDT:
    case ESP_RST_WDT:     Serial.println("*** WATCHDOG — something blocked too long ***"); break;
    case ESP_RST_BROWNOUT:
      Serial.println("*** BROWNOUT — the 3.3 V rail sagged below the reset threshold ***");
      Serial.println("      Not a sketch fault. Something drew more than the supply could");
      Serial.println("      deliver at that instant — usually a Wi-Fi transmit burst landing");
      Serial.println("      on the reader's RF field or the sensor's capture.");
      Serial.println("      Use a 1 A wall supply, then fit 100 uF + 100 nF at each module.");
      break;
    default: Serial.printf("code %d\n", (int) why); break;
  }
}

/* The commonest way a terminal joins the Wi-Fi, reports nothing, and shows as
 * Offline with no error anywhere: the PC's address was typed by hand and the
 * router hands out a different subnet. Comparing costs nothing. */
static void checkSubnet() {
  String host = String(SERVER_URL);
  int    from = host.indexOf("//");
  if (from >= 0) host = host.substring(from + 2);

  int colon = host.indexOf(':');
  if (colon > 0) host = host.substring(0, colon);

  IPAddress server;
  if (!server.fromString(host)) return;      /* a name, not an address */

  IPAddress self = WiFi.localIP();

  if (self[0] != server[0] || self[1] != server[1] || self[2] != server[2]) {
    Serial.println("Network: this board and the server are on DIFFERENT subnets.");
    Serial.printf("         board %d.%d.%d.%d, server %s\n",
                  self[0], self[1], self[2], self[3], host.c_str());
    Serial.println("         Nothing will reach the server. Check LS_SERVER_URL is the PC's");
    Serial.println("         current LAN address — it changes when the PC reconnects.");
  } else {
    Serial.println("Network: the server is on this subnet — good.");
  }
}

void setup() {
  Serial.begin(115200);
  delay(600);
  bootMillis = millis();

  Serial.println();
  Serial.println("L-SIAMS classroom terminal");
  Serial.println("==========================");

  reportBoot();

  /* The MAC, before anything can stop the sketch.
   *
   * Registering this terminal on the Devices page asks for its MAC, and the
   * provisioning JSON that registration produces is what fills in the values
   * below. So a board fresh out of the box cannot get past checkConfig() —
   * which means the MAC has to be printed before it, or there is no way to
   * read it off this sketch at all.
   *
   * boardMac() reads it out of eFuse, so it needs no radio, no network and no
   * credentials — and cannot return zeros because the driver was still
   * starting. */
  String mac = boardMac();

  Serial.print("MAC:   ");
  Serial.println(mac);

  /* Belt and braces. eFuse should never read back as zeros, but printing
   * 00:00:00:00:00:00 as though it were an address is worse than saying so —
   * that value looks plausible enough to type into the Devices page, and the
   * claim would then be refused for a reason that points nowhere near here. */
  if (mac == "00:00:00:00:00:00") {
    Serial.println("       ^ that is not a real address. The MAC could not be read from");
    Serial.println("       eFuse, which on a genuine ESP32 should not happen — suspect a");
    Serial.println("       clone or a damaged module before registering anything.");
  } else {
    Serial.println("       Register this terminal with that MAC on the Devices page.");
  }

  Serial.println();

  if (!checkConfig()) return;

  startRfid();
  startFingerprint();

  /* Two modules on two different buses do not usually fail in the same boot.
   * What they share is the 3.3 V rail and the ground, so when both go quiet
   * together that is the first suspect — not two separate faults, which is
   * how it reads if each result is taken on its own. */
  if (!rfidReady && !fingerReady) {
    Serial.println();
    Serial.println("  ---- BOTH modules are silent ----");
    Serial.println("  They sit on different buses and share only the 3.3 V rail and the");
    Serial.println("  ground. Two separate faults in one boot is the unlikely reading; one");
    Serial.println("  supply problem is the likely one. Before replacing anything: use a");
    Serial.println("  1 A wall supply, check the shared 3V3 and GND joints, fit 100 uF +");
    Serial.println("  100 nF at each module, then unplug one and reboot to isolate.");
    Serial.println();
  }

  Serial.printf("Wi-Fi: connecting to %s", WIFI_SSID);
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASS);

  uint32_t started = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - started < 20000) {
    delay(400);
    Serial.print(".");
  }
  Serial.println();

  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("Wi-Fi: could not join. Check the name and password in secrets.h, and");
    Serial.println("       that the network is 2.4 GHz — an ESP32 cannot see 5 GHz.");
    return;
  }

  Serial.print("Wi-Fi: connected, IP ");
  Serial.println(WiFi.localIP());
  Serial.printf("       server: %s\n", SERVER_URL);

  checkSubnet();

  if (!claimDevice()) {
    Serial.println("Stopping: every signed request is refused until the board is claimed.");
    return;
  }

  if (!syncClockFromServer()) {
    Serial.println("Stopping: without the server's clock, every signature is refused.");
    return;
  }

  sendHeartbeat();

  Serial.println();
  Serial.println("Ready. A teacher scans a finger to open the session; students tap after.");
  Serial.println();
}

/* =========================================================================
 * The loop
 * ========================================================================= */

void loop() {
  /* Every poll below is a blocking HTTP request, and a card held against the
   * reader while one is in flight is not seen — invisible from outside, and
   * it reads as a dead reader. Card reading therefore comes last, after the
   * polls have had their turn, rather than being starved behind them. */
  if (!busy && millis() - lastHeartbeat >= HEARTBEAT_MS) {
    lastHeartbeat = millis();
    sendHeartbeat();
  }

  pollEnrollment();
  pollCardEnrollment();
  pollTemplateSync();
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
    Serial.println("      no clock — cannot sign the request");
    return;
  }

  sendTap(uid);
}
