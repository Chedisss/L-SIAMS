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
#include <Preferences.h>
#include "mbedtls/md.h"

/* The school's root certificate, written by `console.bat tls:generate`.
 *
 * Optional on purpose. A bench setup talking http:// has no use for it, and
 * requiring the file would stop the sketch compiling before anyone had a
 * server to point it at. Drop ls_root_ca.h next to this file and it is picked
 * up automatically — there is nothing to switch on. */
#if defined(__has_include)
#  if __has_include("ls_root_ca.h")
#    include "ls_root_ca.h"
#    define LS_HAVE_ROOT_CA 1
#  endif
#endif

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

/* Building an array of objects differs between the two as well, and the v6
 * spelling was removed in v7 rather than merely deprecated. Same reasoning as
 * the alias above: one place to look, so a sketch that compiles under one
 * library does not fail under the other with a message about a member that
 * does not exist. */
#if ARDUINOJSON_VERSION_MAJOR < 7
#  define LS_ARRAY(doc, key)  (doc).createNestedArray(key)
#  define LS_ADD_OBJECT(arr)  (arr).createNestedObject()
#else
#  define LS_ARRAY(doc, key)  (doc)[key].template to<JsonArray>()
#  define LS_ADD_OBJECT(arr)  (arr).template add<JsonObject>()
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
 * LS_SERVER_URL is the one people get wrong, in three ways:
 *
 *   1. Leaving off the http://  — "192.168.1.193:8080" looks complete and is
 *      not. The board completes it for you and says so, but write it in full.
 *   2. localhost or 127.0.0.1  — to this board those mean THIS BOARD, so the
 *      request never leaves it.
 *   3. A stale address. The PC's IP is a DHCP lease and moves when the router
 *      restarts. If the terminal worked yesterday and not today, check this
 *      before anything else: run ipconfig on the PC and compare.
 *
 * It must be the PC's current LAN address WITH the port — the address
 * start.bat prints. Reserve that address in the router so it stops moving.
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

/* The address every request is built on, after normalisation.
 *
 * LS_SERVER_URL is typed by hand, and "192.168.1.193:8080" — no http:// — is
 * the natural way to write an address you have just read off ipconfig. It is
 * also unusable: HTTPClient splits a URL at the first colon to find the
 * protocol, so it took "192.168.1.193" to be the scheme and the rest to be the
 * host, and every request then failed while sending its headers.
 *
 * The failure gave no hint of the cause. It surfaced as error -2 on the clock
 * request, which reads as a network fault and sent the search to the Wi-Fi,
 * the router and the PC — none of which were wrong. A missing five characters
 * should not cost that.
 *
 * So a bare host:port is accepted and completed, out loud. Guessing silently
 * would be worse than refusing: the log says exactly what it used. */
static String serverBase;

static void normaliseServerUrl() {
  serverBase = String(SERVER_URL);
  serverBase.trim();

  /* A trailing slash would double up against paths that all begin with one. */
  while (serverBase.endsWith("/")) serverBase.remove(serverBase.length() - 1);

  if (serverBase.indexOf("://") < 0) {
    Serial.println("Config: LS_SERVER_URL has no http:// in front of it.");
    Serial.printf("        Reading it as http://%s\n", serverBase.c_str());
    Serial.println("        Add the http:// to the sketch — this is a guess, and the next");
    Serial.println("        person to read that line deserves not to have to make it.");

    serverBase = "http://" + serverBase;
  }

  /* No port is not a typo the board can fix, but it is one it can name.
   *
   * start.bat serves on 8080. An address with the port left off goes to 80
   * instead, and on a machine running XAMPP something IS listening there —
   * Apache, which knows nothing about this system and answers 404. So the
   * board gets a clean HTTP reply from a real web server that is not the
   * right one, and "404" reads as a missing route rather than a missing port.
   *
   * Left as a warning rather than a correction: 80 is a legitimate choice
   * behind a reverse proxy, and guessing 8080 for somebody who meant 80 would
   * be a harder fault to see than this one. */
  int afterScheme = serverBase.indexOf("://") + 3;
  int colon       = serverBase.indexOf(':', afterScheme);

  if (colon < 0) {
    Serial.println("Config: LS_SERVER_URL has no port.");
    Serial.printf("        %s goes to port 80, and start.bat serves on 8080.\n",
                  serverBase.c_str());
    Serial.println("        On a PC running XAMPP, Apache answers on 80 and returns 404 —");
    Serial.println("        a real reply from the wrong server, which is why it looks like a");
    Serial.println("        missing page rather than a missing port.");
    Serial.printf("        Unless you meant port 80, this should read %s:8080\n",
                  serverBase.c_str());
  }
}

/* Credentials pasted with a stray space are the commonest way a correct key
 * fails, and the least visible: the quotes hide it, the compiler keeps it, and
 * the signature it produces is wrong in a way that looks like a wrong key.
 * Reported by name and position rather than silently trimmed, because a value
 * that is not what the file says it is causes worse confusion later. */
static bool warnIfPadded(const char *value, const char *name) {
  size_t length = strlen(value);

  if (length == 0) return false;

  bool leading  = (value[0] == ' ' || value[0] == '\t');
  bool trailing = (value[length - 1] == ' ' || value[length - 1] == '\t');

  if (!leading && !trailing) return false;

  Serial.printf("Config: %s has a stray %s.\n", name,
                leading && trailing ? "space at both ends"
                                    : (leading ? "space at the start" : "space at the end"));
  Serial.println("        It is inside the quotes, so it is part of the value. Every");
  Serial.println("        signature built from it will be wrong, and the server will");
  Serial.println("        report an invalid key rather than a mistyped one.");
  Serial.println("        Delete the space and upload again.");

  return true;
}
static const char *DEVICE_ID   = LS_DEVICE_ID;
static const char *API_KEY     = LS_API_KEY;
static const char *HMAC_SECRET = LS_HMAC_SECRET;
static const char *CLAIM_TOKEN = LS_CLAIM_TOKEN;

/* ------------------------------------------------------------------ pins -- */

#define PIN_RFID_SS        5
#define PIN_RFID_RST       22

/* The three SPI pins, named rather than left to the default.
 *
 * SPI.begin() with no arguments uses the ESP32's VSPI defaults — 18, 19, 23 —
 * which are these exact values, so nothing changes by writing them down. What
 * changes is that they can now be MOVED, and that is a diagnostic the sketch
 * could not perform before.
 *
 * When the reader answers 0x00 and 0xFF alternately, MISO is floating: nothing
 * is driving it. Three things can cause that — the wire, the module, or the
 * ESP32's own pin — and swapping the module rules out one of them. Moving MISO
 * to a free GPIO here, and moving the wire to match, rules out a second: if
 * the reading changes, GPIO 19 is damaged; if it does not, the fault is the
 * wire or its joints.
 *
 * Free pins on a 30-pin DOIT board, with nothing else in this sketch using
 * them: 21, 25, 26, 27, 32, 33. Avoid 34-39, which are input-only and cannot
 * be used for MOSI or SCK, and avoid 0, 2, 12 and 15, which are strapping pins
 * and affect how the board boots. */
#define PIN_RFID_SCK       18
#define PIN_RFID_MISO      19
#define PIN_RFID_MOSI      23
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

/* Why the terminal is not working, kept for the loop to keep saying.
 *
 * setup() gives up in four places — no credentials, no Wi-Fi, an unclaimed
 * board, no clock — and each one printed its reason and returned. But a
 * return from setup() does not stop an Arduino sketch: loop() is called
 * immediately afterwards regardless, and ran on happily with no clock, no
 * claim, and in some cases no modules. Every poll it made was refused, and
 * every poll discards its refusal without printing anything, so the board sat
 * there looking alive and doing nothing at all.
 *
 * That is the failure that reads as "the device does not connect to the
 * system". It does connect; it was told to stop and carried on anyway, and
 * the one line explaining why had long since scrolled off the top of the
 * serial monitor. So the reason is now held here and repeated. */
static const char *haltReason = nullptr;
static uint32_t    lastHaltNag = 0;

/* Set when the board halts but is still authenticated and still has a clock —
 * the dead-modules case. Such a board must go on heartbeating.
 *
 * If it stops, the server sees nothing for two minutes and marks it offline,
 * and the enrolment modal reverts to "this terminal has not reported in a
 * while", which sends somebody to check the network and the power on a board
 * that is sitting there perfectly connected. Continuing to report is what
 * keeps the accurate message on screen: online, and its sensors are dead. */
static bool     haltedButReporting = false;
static uint32_t lastModuleNag      = 0;
static uint32_t lastModuleRetry    = 0;

#define TAP_QUEUE_MAX 40

struct QueuedTap {
  char     uid[15];        /* up to a 7-byte UID in hex, plus terminator */
  char     requestId[37];  /* the UUID, so the server can refuse a duplicate */
  uint32_t at;             /* UTC epoch when the card was actually presented */
};

static RTC_DATA_ATTR QueuedTap tapQueue[TAP_QUEUE_MAX];
static RTC_DATA_ATTR uint16_t  tapQueueCount;

static uint32_t lastQueueFlush = 0;

/* RTC memory is only zeroed on a power-on reset, so after a crash the count
 * could be anything. A queue that claims 40000 entries would read far past
 * the array. */


/* Some halts are permanent and some are not, and treating them alike is what
 * turns a passing network fault into a site visit.
 *
 * Placeholder credentials and two dead modules need a person: no amount of
 * waiting fixes either. A clock that could not be read does not — the server
 * was unreachable for a moment and will very likely be reachable again in
 * one, and the terminal is on a wall in a classroom that may well be locked.
 * When the halt is this kind, the loop keeps trying and lifts it the moment
 * it succeeds. */
static bool     haltIsTransient = false;
static uint32_t lastHaltRetry   = 0;

/* Consecutive refused requests, and when that was last mentioned. */
static uint32_t failStreak     = 0;
static uint32_t lastFailReport = 0;

/* Defined below signedRequest, which is its only caller, but declared here so
 * the file still compiles as plain C++ — the Arduino IDE inserts prototypes
 * for you, and a sketch that only builds because of that cannot be checked
 * with firmware/tools/syntax-check. */
static void reportRequestHealth(const char *method, const String &path,
                                int status, const String &payload);

/* HTTPClient reports its own failures as negative numbers, and they were being
 * printed raw and lumped into one message.
 *
 * They are not one problem. "-1" means the board never got a connection; "-11"
 * means it connected, sent the whole request and the answer never came. Those
 * point at different machines — the first at the address or the network, the
 * second at something between the board and a server that is very probably
 * running fine. Printing "HTTP -11" and then blaming the server address sends
 * somebody to check a setting that was never wrong. */
static const char *httpErrorText(int status);
static void        describeHttpError(int status, const char *indent);

static uint32_t lastHeartbeat = 0;
static uint32_t lastFpPoll    = 0;
static uint32_t lastCardPoll  = 0;
static uint32_t lastSyncPoll  = 0;

/* How long to wait between sync polls, in milliseconds.
 *
 * Seeded from SYNC_POLL_MS and then replaced by whatever the server sends in
 * poll_seconds. The server has been sending that number since the sync route
 * existed and the firmware has been ignoring it, so FINGERPRINT sync ran at a
 * fixed fifteen seconds however the installation was configured — a setting
 * that looked adjustable and was not.
 *
 * It matters most on the day a school first enrols its staff. One template per
 * poll at fifteen seconds is forty teachers in ten minutes; at three seconds it
 * is two minutes, and dropping it for an afternoon costs nothing because there
 * is no attendance running yet. Afterwards it goes back up, where a slow
 * trickle is exactly right — a terminal that spends its day recording taps
 * should not be interrupting itself every three seconds to ask about
 * fingerprints. */
static uint32_t syncPollMs    = SYNC_POLL_MS;
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
 * TLS
 *
 * Two things have to be true before an https:// URL will work on this board,
 * and both fail silently if they are not.
 *
 * The first is the root certificate. Without one, WiFiClientSecure has nothing
 * to check the server against, and the usual workaround — setInsecure() —
 * accepts ANY certificate from ANY server. That is worth being blunt about: it
 * encrypts the traffic and then hands it to whoever answered, so a laptop on
 * the school Wi-Fi that can win a race to the address reads every card tap and
 * every fingerprint result in clear text. It looks identical to a working
 * system from both ends. So this sketch does not do it: no root, no https, and
 * it says so rather than quietly downgrading.
 *
 * The second is the clock. A certificate is only valid between two dates, and
 * mbedtls checks them — but an ESP32 powers on believing it is January 1970,
 * which is outside every certificate ever issued. The board would refuse its
 * own server's certificate as "not yet valid".
 *
 * That is a circle, because the board sets its clock by asking the server, and
 * now it cannot reach the server to ask. It gets broken the same way the
 * timestamp problem below it does: with a starting estimate good enough to get
 * one request through, after which the real time arrives and replaces it. Two
 * sources, whichever is later:
 *
 *   - the moment this sketch was compiled, which is necessarily after the
 *     certificate was issued, since the certificate has to exist before
 *     ls_root_ca.h can be generated from it;
 *   - the last time the board knew for certain, saved in flash. This is what
 *     covers a renewal: a certificate issued after the firmware was built
 *     starts later than the build date, and without a saved time a power cut
 *     would leave the board permanently unable to accept it.
 *
 * Neither is trusted for anything except getting the handshake open. The
 * server's own time replaces it seconds later, and every attendance record
 * carries the server's clock, not this one.
 * ========================================================================= */

/* Outside HTTPClient's own range (-1 to -11), so it cannot be mistaken for a
 * transport failure. This one is a build problem, not a network problem. */
#define LS_ERR_NO_ROOT_CA (-20)

static Preferences tlsStore;

/* When this sketch was compiled, as an epoch. __DATE__ is "Aug 26 2026" and
 * __TIME__ is "17:53:50" — fixed formats, so parsing them is safe. */
static time_t firmwareBuildEpoch() {
  static const char months[] = "JanFebMarAprMayJunJulAugSepOctNovDec";

  char monthName[4] = { 0 };
  int  day = 0, year = 0, hour = 0, minute = 0, second = 0;

  if (sscanf(__DATE__, "%3s %d %d", monthName, &day, &year) != 3) return 0;
  if (sscanf(__TIME__, "%d:%d:%d", &hour, &minute, &second) != 3) return 0;

  const char *found = strstr(months, monthName);
  if (found == nullptr) return 0;

  struct tm built{};
  built.tm_year = year - 1900;
  built.tm_mon  = (int) ((found - months) / 3);
  built.tm_mday = day;
  built.tm_hour = hour;
  built.tm_min  = minute;
  built.tm_sec  = second;

  time_t epoch = mktime(&built);
  return epoch > 0 ? epoch : 0;
}

/* Move the clock forward to the best estimate available. Only ever forward:
 * the real time, once the server supplies it, is always later than either
 * estimate, and winding backwards would invalidate it. */
static void seedClockForTls() {
  time_t now   = time(nullptr);
  time_t build = firmwareBuildEpoch();
  time_t saved = 0;

  if (tlsStore.begin("lsiams", true)) {
    saved = (time_t) tlsStore.getULong("lastgood", 0);
    tlsStore.end();
  }

  time_t best = build > saved ? build : saved;

  if (best <= now) return;

  struct timeval seed = { .tv_sec = best, .tv_usec = 0 };
  settimeofday(&seed, nullptr);

  struct tm readable;
  gmtime_r(&best, &readable);

  Serial.printf("Clock: starting estimate %04d-%02d-%02d %02d:%02d UTC (%s), so the\n",
                readable.tm_year + 1900, readable.tm_mon + 1, readable.tm_mday,
                readable.tm_hour, readable.tm_min,
                (saved > build) ? "last known good time" : "build date");
  Serial.println("       server's certificate can be checked. The server's real time");
  Serial.println("       replaces this on the first successful request.");
}

/* Remember a confirmed time, so a power cut does not send the board back to
 * its build date. Written at most hourly: flash has a finite erase budget and
 * this is not worth spending it on. */
static void rememberGoodTime(time_t epoch) {
  if (epoch <= 0) return;

  if (!tlsStore.begin("lsiams", false)) return;

  time_t saved = (time_t) tlsStore.getULong("lastgood", 0);

  if (epoch > saved + 3600) {
    tlsStore.putULong("lastgood", (uint32_t) epoch);
  }

  tlsStore.end();
}

/* Returns false when the sketch was built without a root certificate, which
 * is the one case where the caller must not continue. */
static bool configureTlsClient(WiFiClientSecure &client) {
#ifdef LS_HAVE_ROOT_CA
  client.setCACert(LS_ROOT_CA);
  return true;
#else
  (void) client;
  return false;
#endif
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

  String url   = serverBase + path;
  bool   isTls = url.startsWith("https://");

  WiFiClient       plain;
  WiFiClientSecure secure;
  HTTPClient       http;

  if (isTls) {
    /* No root certificate compiled in means nothing can be verified, and
     * connecting anyway would encrypt the traffic while trusting whoever
     * answered. Refusing is the safe answer, and LS_ERR_NO_ROOT_CA says
     * exactly what to do about it. */
    if (!configureTlsClient(secure)) return LS_ERR_NO_ROOT_CA;
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

  String payload = (status > 0) ? http.getString() : String();

  if (status > 0 && responseOut != nullptr) {
    deserializeJson(*responseOut, payload);
  }

  http.end();

  reportRequestHealth(method, path, status, payload);

  return status;
}

/* Whether the server is answering at all, said out loud.
 *
 * The three polls in the loop each end with `if (... != 200) return;` — the
 * refusal is discarded and nothing is printed. That is correct for the normal
 * case, where the answer is simply "nothing to do" and printing it every two
 * seconds would bury everything else. It is badly wrong for the abnormal one:
 * a terminal whose every request is being refused looks identical to a
 * terminal with nothing to do. Silence for both.
 *
 * So refusals are counted here, at the one place every request passes
 * through. The first one speaks, then at most one line every thirty seconds
 * while the condition persists, and one more when it clears. Enough to see
 * the problem from the serial monitor; not enough to flood it. */
static void reportRequestHealth(const char *method, const String &path,
                                int status, const String &payload) {
  if (status == 200 || status == 201) {
    if (failStreak >= 3) {
      Serial.printf("Server: answering again (%lu request(s) had been refused)\n",
                    (unsigned long) failStreak);
    }
    failStreak = 0;
    return;
  }

  LsJson refusal;
  if (status > 0) deserializeJson(refusal, payload);

  const char *refusalCode = refusal["code"] | "";

  /* The one refusal that is not a fault.
   *
   * A board fresh from power-on has no clock, so its first request is signed
   * with a 1970 timestamp and the server refuses it as expired — on purpose,
   * with its own epoch attached so the board can set itself and retry. That
   * happens on every single boot, before anything is wrong.
   *
   * Reporting it would print "the board is not authenticated, re-register
   * this terminal" at the top of every successful startup, which is both
   * false and expensive: it is the wrong diagnosis, and it sends somebody off
   * to regenerate a key that was never the problem. Let the clock bootstrap
   * do its job silently and judge the retry instead. */
  if (!clockSet && status == 401 && strcmp(refusalCode, "TIMESTAMP_EXPIRED") == 0) {
    failStreak = 0;
    return;
  }

  failStreak++;

  bool first = (failStreak == 1);
  if (!first && millis() - lastFailReport < 30000) return;

  lastFailReport = millis();

  Serial.printf("Server: %s %s -> ", method, path.c_str());

  if (status <= 0) {
    /* Not a refusal — the server never answered. Different problem, different
     * place to look, so it must not be reported as though the server said no. */
    Serial.printf("%s\n", httpErrorText(status));
    describeHttpError(status, "        ");
    return;
  }

  Serial.printf("HTTP %d %s\n", status, refusalCode);

  const char *message = refusal["message"] | "";
  if (strlen(message)) Serial.printf("        %s\n", message);

  if (status == 401) {
    Serial.println("        The board is not authenticated. Re-register this terminal on the");
    Serial.println("        Devices page and paste the new provisioning values into the sketch.");
  } else if (status == 404) {
    /* Something answered, and it was not this system.
     *
     * Every device route exists on a working server, so a 404 is never a
     * missing endpoint — it is a reply from a different web server. The
     * commonest cause by far is a port: start.bat serves on 8080, and on a
     * PC running XAMPP, Apache is listening on 80 and will answer any
     * address it is given with a perfectly formed 404.
     *
     * That is worse than silence, because a clean HTTP reply looks like
     * progress. It sends somebody to look for a broken route in a server
     * that was never contacted. */
    Serial.println("        A 404 here means something answered that is NOT L-SIAMS.");
    Serial.println("        Every device route exists on a working server, so this is not a");
    Serial.println("        missing page — it is the wrong server.");
    Serial.printf("        LS_SERVER_URL is %s\n", serverBase.c_str());
    Serial.println("        1. Is the port right? start.bat serves on 8080. Without a port");
    Serial.println("           the request goes to 80, where XAMPP's Apache answers 404.");
    Serial.println("        2. Has another device taken that IP address?");
  } else if (status == 429) {
    Serial.println("        Rate limited. Raise DEVICE_RATE_LIMIT in .env, or set it to 0.");
  }
}

/* The HTTPClient error codes, in words. Values from the ESP32 core's
 * HTTPClient.h; anything outside that range is printed as itself rather than
 * guessed at. */
static const char *httpErrorText(int status) {
  switch (status) {
    case  0:   return "no reply at all";
    case -1:   return "could not connect";
    case -2:   return "failed to send the request headers";
    case -3:   return "failed to send the request body";
    case -4:   return "not connected";
    case -5:   return "the connection was lost mid-request";
    case -6:   return "no response stream";
    case -7:   return "the address answered, but not as an HTTP server";
    case -8:   return "not enough memory";
    case -9:   return "the reply used an encoding this client cannot read";
    case -10:  return "failed while writing the reply";
    case -11:  return "connected and sent, but the reply never arrived (timeout)";
    case LS_ERR_NO_ROOT_CA:
               return "https was asked for, but this sketch has no root certificate";
    default:   return "the request failed";
  }
}

/* What to actually go and check, which differs sharply by code. */
static void describeHttpError(int status, const char *indent) {
  switch (status) {
    case -1:
    case -4:
      Serial.printf("%sNothing accepted a connection at %s.\n", indent, serverBase.c_str());
      Serial.printf("%s1. Is start.bat running on the PC? It serves the site, and it must\n", indent);
      Serial.printf("%s   stay open. XAMPP's Apache does NOT serve L-SIAMS — Apache showing\n", indent);
      Serial.printf("%s   green in the XAMPP Control Panel does not mean the site is up.\n", indent);
      Serial.printf("%s   Only MySQL is needed from XAMPP.\n", indent);
      Serial.printf("%s2. Does the PC still hold that IP? A DHCP lease can move it.\n", indent);
      Serial.printf("%s3. Is the port right? start.bat uses 8080 unless APP_URL says else.\n", indent);
      break;

    case -11:
    case -5:
      /* The important distinction. The board reached the server and said its
       * piece; only the answer went missing. Sending somebody to re-check
       * LS_SERVER_URL here wastes their time on a value that just proved
       * itself correct by connecting. */
      Serial.printf("%sThe connection to %s succeeded and the request went out,\n", indent, serverBase.c_str());
      Serial.printf("%sso the address and the port are right and Apache is listening.\n", indent);
      Serial.printf("%sOnly the reply was lost. Usually a weak or busy Wi-Fi link — check\n", indent);
      Serial.printf("%sthe signal where the terminal is mounted. If it is persistent, look\n", indent);
      Serial.printf("%sfor something holding the PC busy, and confirm nothing ELSE on the\n", indent);
      Serial.printf("%snetwork has taken that IP and is answering on the port without\n", indent);
      Serial.printf("%sspeaking HTTP.\n", indent);
      break;

    case -7:
      Serial.printf("%sSomething is listening at %s but it is not this system.\n", indent, serverBase.c_str());
      Serial.printf("%sAnother device may have taken that IP address.\n", indent);
      break;

    case LS_ERR_NO_ROOT_CA:
      Serial.printf("%sLS_SERVER_URL starts with https://, but this sketch was compiled\n", indent);
      Serial.printf("%swithout the school's root certificate, so there is nothing to check\n", indent);
      Serial.printf("%sthe server against. Nothing was sent.\n", indent);
      Serial.printf("%s1. On the server, run: console.bat tls:generate\n", indent);
      Serial.printf("%s2. Copy storage\\tls\\ls_root_ca.h into this sketch's folder, next to\n", indent);
      Serial.printf("%s   L_SIAMS_Bench.ino.\n", indent);
      Serial.printf("%s3. Re-upload. The sketch finds the file on its own.\n", indent);
      Serial.printf("%sTo run without TLS instead, change LS_SERVER_URL back to http://.\n", indent);
      break;

    case -8:
      Serial.printf("%sThe board ran out of memory. If this repeats, the reply is larger\n", indent);
      Serial.printf("%sthan expected — report it rather than working around it.\n", indent);
      break;

    default:
      Serial.printf("%sThe request did not complete. The Wi-Fi link, and start.bat still\n", indent);
      Serial.printf("%srunning on the PC, are the two things to check.\n", indent);
      break;
  }
}

/* The claim is the one request that cannot be signed: the board has no proven
 * key until the server accepts it. */
static int unsignedPost(const String &path, const String &body, LsJson *responseOut) {
  if (WiFi.status() != WL_CONNECTED) return -1;

  String url   = serverBase + path;
  bool   isTls = url.startsWith("https://");

  WiFiClient       plain;
  WiFiClientSecure secure;
  HTTPClient       http;

  if (isTls) {
    /* No root certificate compiled in means nothing can be verified, and
     * connecting anyway would encrypt the traffic while trusting whoever
     * answered. Refusing is the safe answer, and LS_ERR_NO_ROOT_CA says
     * exactly what to do about it. */
    if (!configureTlsClient(secure)) return LS_ERR_NO_ROOT_CA;
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
 * first request of every boot is refused as expired.
 *
 * Which is a circle, and it has to be broken deliberately: the board asks
 * /api/device/time BECAUSE it has no clock, and that request is signed with
 * the clock it does not have. Fresh from power-on, time(nullptr) returns
 * seconds since boot — about fifty-six years adrift — so the server refuses it
 * with TIMESTAMP_EXPIRED and the board never gets the answer it asked for.
 *
 * The server anticipated this. A TIMESTAMP_EXPIRED refusal carries the
 * server's own epoch in its body, for exactly this purpose. So a rejection is
 * not a dead end here: read the epoch out of it, set the clock, and ask again
 * with a timestamp that will pass.
 *
 * That is safe to trust because the refusal only happens after the API key and
 * signature have been checked — the device is authenticated by then, and only
 * the clock was wrong. */
static bool syncClockFromServer() {
  LsJson response;
  int    status = signedRequest("GET", "/api/device/time", "", &response);

  /* Recover from the one refusal that contains its own remedy. */
  if (status == 401 && strcmp(response["code"] | "", "TIMESTAMP_EXPIRED") == 0) {
    long offered = response["data"]["server_epoch"] | 0L;

    if (offered > 0) {
      /* Measured BEFORE the clock is set. Reading it afterwards compares the
       * server's time with itself and always reports no drift at all, which
       * is how a board fifty-six years out printed "0 years adrift". */
      long drift = offered - (long) time(nullptr);
      if (drift < 0) drift = -drift;

      struct timeval seed = { .tv_sec = (time_t) offered, .tv_usec = 0 };
      settimeofday(&seed, nullptr);

      /* Years is the right unit for a board fresh from power-on, whose clock
       * starts at 1970, and the wrong one for a board that has merely drifted
       * a few minutes — where it rounds to zero and says nothing. */
      Serial.print("Clock: board was ");

      if (drift >= 31557600L)   Serial.printf("%ld year(s)", drift / 31557600L);
      else if (drift >= 86400L) Serial.printf("%ld day(s)", drift / 86400L);
      else if (drift >= 3600L)  Serial.printf("%ld hour(s)", drift / 3600L);
      else if (drift >= 60L)    Serial.printf("%ld minute(s)", drift / 60L);
      else                      Serial.printf("%ld second(s)", drift);

      Serial.println(" adrift; taking the server's time and retrying");

      status = signedRequest("GET", "/api/device/time", "", &response);
    }
  }

  if (status != 200) {
    const char *code = response["code"] | "";

    /* A negative status is not a refusal. The server said nothing at all —
     * it may never have seen the request — and reporting it as "the server
     * refused the time request" accuses a machine that is very likely
     * running perfectly, while hiding the fact that the fault is in the link
     * between here and there. */
    if (status <= 0) {
      Serial.printf("Clock: could not read the server's time — %s\n", httpErrorText(status));
      describeHttpError(status, "       ");
      return false;
    }

    Serial.printf("Clock: server refused the time request (HTTP %d, %s)\n",
                  status, strlen(code) ? code : "no code");
    Serial.printf("       %s\n", (const char *) (response["message"] | ""));

    /* 401 here means the signature chain failed, and it fails for five
     * distinct reasons with five different fixes. Printing only "HTTP 401"
     * threw all of that away and left somebody guessing between a wrong key,
     * a wrong secret and an unregistered board. */
    /* 403 DEVICE_UNCLAIMED, and the reason it is easy to arrive at by
     * accident. Regenerating a terminal's provisioning file — the fix for a
     * lost key, and for a server whose APP_KEY no longer matches its database
     * — also issues a fresh claim token and puts the device back to
     * unclaimed. A board whose LS_CLAIM_TOKEN was blanked after its FIRST
     * claim then has nothing to present, and every signed request is refused
     * with a message about activation that says nothing about where the token
     * went. */
    if (status == 403 && strcmp(code, "DEVICE_UNCLAIMED") == 0) {
      Serial.println("       This terminal has to claim its key before the server will");
      Serial.println("       accept anything signed, and LS_CLAIM_TOKEN is blank.");
      Serial.println("       If you just regenerated the provisioning file on the Devices");
      Serial.println("       page, that put this device back to unclaimed and issued a NEW");
      Serial.println("       claim token. Paste it into LS_CLAIM_TOKEN and upload again.");
      Serial.println("       It is spent on first use, so blank it again afterwards.");
      return false;
    }

    if (status == 401) {
      if (strcmp(code, "DEVICE_UNKNOWN") == 0) {
        Serial.println("       The server has no device with this LS_DEVICE_ID, or it was");
        Serial.println("       archived. Check the id against the Devices page.");
      } else if (strcmp(code, "API_KEY_INVALID") == 0) {
        Serial.println("       The key was rejected. The usual cause is downloading the");
        Serial.println("       provisioning file more than once: EVERY download issues a new");
        Serial.println("       key and revokes the previous one, so an older copy stops");
        Serial.println("       working the moment you download again.");
        Serial.println("       Download once more, then use ONLY that file — all four values");
        Serial.println("       together, not mixed with an earlier one.");
      } else if (strcmp(code, "SERVER_KEY_MISMATCH") == 0) {
        /* Nothing is wrong on this board. Every value in the sketch is
         * correct, and re-flashing it changes nothing. */
        Serial.println("       Nothing is wrong with this terminal — do not re-flash it.");
        Serial.println("       The SERVER cannot read its own copy of this device's secret.");
        Serial.println("       Its APP_KEY does not match the database it is using, which");
        Serial.println("       happens when a database is copied to another PC and new keys");
        Serial.println("       are generated there instead of carrying the original .env.");
        Serial.println("       On the PC: run doctor.bat and read the Encryption key section.");
        Serial.println("       The fix is to restore the original .env. Failing that, register");
        Serial.println("       this terminal again — but note the fingerprint templates cannot");
        Serial.println("       be recovered that way and will need re-enrolling.");
      } else if (strcmp(code, "SIGNATURE_INVALID") == 0) {
        Serial.println("       The key was accepted but the signature did not match, so");
        Serial.println("       LS_HMAC_SECRET is from a different provisioning file than");
        Serial.println("       LS_API_KEY. Take all four values from one download.");
      } else if (strcmp(code, "TIMESTAMP_EXPIRED") == 0) {
        Serial.println("       The clock was refused even after taking the server's own time,");
        Serial.println("       so the two drifted apart between the two requests. Check the");
        Serial.println("       PC's clock is correct — the window is 30 seconds either way.");
      } else {
        Serial.println("       Check LS_DEVICE_ID, LS_API_KEY and LS_HMAC_SECRET all came");
        Serial.println("       from the SAME provisioning download — mixing two is the");
        Serial.println("       commonest cause.");
      }

      Serial.println("       Using two boards? Each needs its own registration and its own");
      Serial.println("       file; one board's credentials will not work on the other.");
    }

    return false;
  }

  long epoch = response["data"]["server_epoch"] | 0L;
  if (epoch <= 0) return false;

  struct timeval tv = { .tv_sec = (time_t) epoch, .tv_usec = 0 };
  settimeofday(&tv, nullptr);
  clockSet = true;

  /* Kept across a power cut, so the next boot starts from a time that can
   * still accept a renewed certificate rather than from the build date. */
  rememberGoodTime((time_t) epoch);

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
    Serial.println("       Blank LS_CLAIM_TOKEN at the top of this sketch and re-flash.");
    return true;
  }
  if (strcmp(code, "CLAIM_IDENTITY_MISMATCH") == 0) {
    Serial.println("       The MAC registered on the server is not this board's.");
    Serial.printf("       This board is %s\n", boardMac().c_str());
    Serial.println("       Open this terminal on the Devices page. If that address is the");
    Serial.println("       board you mean to use, there is a button offering to adopt it —");
    Serial.println("       one click, then press EN/RST here. If it is NOT, some other board");
    Serial.println("       is holding this terminal's provisioning file: revoke the key.");
    Serial.println();

    /* The trap this refusal sets. A claim token is single use, so "claim
     * refused" reads as "that token is spent, regenerate the file" — and
     * regenerating issues another token, un-claims the device again, and
     * requires another edit and another upload. None of which was needed.
     *
     * A refused claim consumes nothing. The token below is still valid, and
     * once the MAC is corrected it works unchanged. Verified against the
     * server: fix the address, reset the board, same token, claim accepted. */
    Serial.println("       Do NOT regenerate the provisioning file for this. A refused claim");
    Serial.println("       does not spend the token — the one already in this sketch will");
    Serial.println("       work as soon as the MAC matches.");
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
  /* 2.1.0 is the first build that checks for a duplicate finger — the server
   * refuses a completion from anything older. 2.1.1 only labels the failures it
   * reports, so the wizard can emphasise a refusal; a terminal left on 2.1.0
   * enrols exactly as before. */
  body["firmware"]    = "2.1.1";
  body["uptime"]      = (millis() - bootMillis) / 1000;
  body["free_heap"]   = ESP.getFreeHeap();
  body["wifi_signal"] = WiFi.RSSI();
  /* The real depth, so the server's queue warning means something. It read a
   * hard-coded zero for as long as there was no queue to report. */
  body["queue"]       = tapQueueCount;

  /* Whether the two modules answered at boot.
   *
   * The board already knows this and prints it to a serial monitor nobody
   * watching the browser can see. Sending it is what lets the enrolment modal
   * say "this terminal's sensor is not responding" instead of "Waiting for
   * the terminal…" for ever — a terminal with dead modules heartbeats
   * perfectly and looks online by every other measure, which is exactly why
   * it was the one failure the server could not see. */
  body["rfid_ok"]        = rfidReady;
  body["fingerprint_ok"] = fingerReady;

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

/* `code` is optional and may be nullptr. It exists so the enrolment wizard can
 * tell a refusal from an ordinary failure without reading the sentence — a
 * finger that belongs to another teacher is shown as a refusal that stays on
 * screen, a capture that timed out as a retryable failure. A server that has
 * not been updated ignores the field. */
static void reportEnrollFailed(int requestId, const char *reason, const char *code = nullptr) {
  LsJson body;
  body["request_id"] = requestId;
  body["reason"]     = reason;
  if (code && strlen(code)) body["code"] = code;

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
    reportEnrollFailed(requestId, "No usable fingerprint was captured in time.", "CAPTURE_FAILED");
    busy = false;
    return;
  }

  /* Is this finger already in this sensor?
   *
   * The sensor will store the same finger in two slots without complaint, and
   * afterwards nothing can tell that it did: two teachers hold enrolments that
   * both match one person, and whichever slot the search returns first decides
   * whose class opens. Worse than a refused enrolment by a long way, and
   * silent — the register looks correct.
   *
   * The first capture is already in buffer 1, so the search costs nothing
   * extra. The slot number is all this board can report; only the server knows
   * whose it is, so the question goes there. A match against the teacher being
   * enrolled is a re-enrolment, which is allowed, and the server says so by
   * answering proceed. */
  if (finger.fingerFastSearch() == FINGERPRINT_OK) {
    Serial.printf("       this finger already matches slot %d (confidence %d)\n",
                  finger.fingerID, finger.confidence);

    LsJson body;
    body["request_id"]   = requestId;
    body["matched_slot"] = finger.fingerID;

    LsJson response;
    int    status = signedRequest("POST", "/api/fingerprint/enrollment/duplicate",
                                  jsonToString(body), &response, generateUuid());

    /* Only a clear "no" from the server lets this continue. A network failure
     * here must not be read as permission: the whole point is to refuse when
     * the answer is not known, because the failure it prevents is invisible
     * afterwards. */
    bool proceed = (status == 200 || status == 201) && (response["data"]["proceed"] | false);

    if (!proceed) {
      const char *who = response["data"]["teacher_name"] | "";

      if (status == 200 || status == 201) {
        Serial.printf("       REFUSED: already enrolled to %s\n", who);
      } else {
        Serial.println("       REFUSED: the server could not be asked whose finger this is.");
      }

      /* The server has already marked the request failed with the name in it
       * when it answered a duplicate; reporting again would overwrite that
       * message with a vaguer one. Only the unreachable case needs saying. */
      if (status != 200 && status != 201) {
        reportEnrollFailed(requestId,
          "The finger matched an existing enrolment and the server could not be reached "
          "to say whose. Nothing was written.",
          "DUPLICATE_UNVERIFIED");
      }

      busy = false;
      return;
    }

    Serial.println("       same teacher re-enrolling; continuing");
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
  /* Proof that the duplicate search above actually ran on this board.
   *
   * The check that stops one finger being enrolled to two teachers lives in
   * firmware, and firmware is flashed by hand — so a terminal still carrying
   * an older sketch performed no check at all and the server accepted the
   * enrolment anyway. That is not a control, it is a suggestion. The server
   * now refuses a completion that does not carry this, which makes an
   * un-updated terminal unable to enrol rather than able to enrol unsafely. */
  body["duplicate_checked"]  = true;
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
  if (millis() - lastSyncPoll < syncPollMs) return;
  lastSyncPoll = millis();

  LsJson response;
  if (signedRequest("GET", "/api/fingerprint/sync", "", &response) != 200) return;

  /* Adopt the server's cadence. Clamped rather than trusted outright: a zero
   * would busy-loop the sync against the rate limiter and starve the card
   * reader, and an absurd value would strand a terminal that is waiting for
   * templates. Two seconds is as fast as one template transfer can usefully
   * repeat; five minutes is slower than anybody would deliberately choose. */
  int serverPoll = response["data"]["poll_seconds"] | 0;

  if (serverPoll > 0) {
    if (serverPoll < 2)   serverPoll = 2;
    if (serverPoll > 300) serverPoll = 300;

    uint32_t wanted = (uint32_t) serverPoll * 1000UL;

    if (wanted != syncPollMs) {
      Serial.printf("Sync: poll interval now %d s (set by the server)\n", serverPoll);
      syncPollMs = wanted;
    }
  }

  /* An administrator asked for this sensor to be emptied.
   *
   * Only the server can decide this — a sensor holding templates the server
   * has no record of still matches fingers, and a scan landing on one is
   * refused as unrecognised, so the reader turns away a teacher who is
   * genuinely enrolled. Nothing on the terminal can tell which slots those
   * are; the server compares what the sensor reports holding against what it
   * recorded, and the only cure available is to start clean.
   *
   * Safe because everything the server holds is queued straight back: the
   * refill begins on the next poll. */
  if (response["data"]["wipe_sensor"] | false) {
    Serial.println("\nSensor: the server asked for a full erase");

    bool        ok     = finger.emptyDatabase() == FINGERPRINT_OK;
    const char *reason = "";

    if (ok) {
      finger.getTemplateCount();
      Serial.printf("      erased — %d template(s) on this sensor now\n", finger.templateCount);
      Serial.println("      the server will rewrite what it holds over the next few polls");
    } else {
      reason = "The sensor refused to erase its database.";
      Serial.println("      the sensor refused to erase. Check power and wiring.");
    }

    LsJson body;
    body["wiped"] = ok;
    if (!ok) body["reason"] = reason;

    signedRequest("POST", "/api/fingerprint/sync/wiped", jsonToString(body), nullptr);
    return;
  }

  /* The server may want something FROM this sensor rather than in it.
   *
   * A teacher enrolled here before the server kept templates has their finger
   * in this flash and nowhere else. loadModel() pulls that slot back into the
   * character buffer and the bytes come out of there — the same read done at
   * the end of every enrolment, minus the finger. Sending them up is what
   * lets that teacher work in every other room, and nobody has to be fetched
   * to a reader for it. */
  JsonVariantConst wanted = response["data"]["upload"];

  if (!wanted.isNull()) {
    int         slot = wanted["slot"] | 0;
    const char *who  = wanted["teacher_name"] | "";

    if (slot > 0) {
      Serial.printf("\nSync: server is missing %s — reading slot %d back out\n", who, slot);

      static uint8_t outgoing[FP_TEMPLATE_MAX];
      size_t         outLen = 0;

      if (finger.loadModel(slot) != FINGERPRINT_OK) {
        Serial.printf("      slot %d will not load — nothing to send\n", slot);
      } else if (!fpReadTemplate(outgoing, sizeof(outgoing), &outLen)) {
        Serial.println("      the template could not be read off the sensor");
      } else {
        LsJson body;
        body["slot"]     = slot;
        body["template"] = fpBase64Encode(outgoing, outLen);

        int status = signedRequest("POST", "/api/fingerprint/sync/captured",
                                   jsonToString(body), nullptr);

        Serial.printf("      sent %u bytes — HTTP %d\n", (unsigned) outLen, status);
      }
    }

    return;
  }

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
    /* Read it back and compare. loadModel() succeeding only proves the slot
     * holds SOMETHING — it says nothing about whether that something is the
     * template we sent, and a sensor that stores a mangled model reports
     * exactly the same success as one that stores a good one.
     *
     * This is not hypothetical. A terminal reported three templates stored,
     * the page showed a healthy sensor, and every finger presented to it came
     * back NOT RECOGNISED — because the check above passes on the strength of
     * bytes existing. Comparing what came back against what went in is the
     * only way to tell a working transfer from a convincing one.
     *
     * The comparison also splits the two failures that look identical from
     * the outside. Bytes that differ mean the transfer corrupted them.
     * Bytes that match while the finger still will not match mean the
     * transfer is fine and this sensor needs the model re-formed before it
     * can be stored — a hardware difference, not a bug in the transfer, and
     * not something re-sending will ever fix. */
    static uint8_t verify[FP_TEMPLATE_MAX];
    size_t         verifyLen = 0;

    if (!fpReadTemplate(verify, sizeof(verify), &verifyLen)) {
      reason = "Stored, but the slot could not be read back to check it.";
      Serial.printf("      slot %d stored but will not read back for checking\n", slot);
    } else if (verifyLen != len) {
      reason = "The sensor stored a different number of bytes than were sent.";
      Serial.printf("      slot %d holds %u bytes; %u were sent\n",
                    slot, (unsigned) verifyLen, (unsigned) len);
      Serial.println("      the sensor is not storing this template intact — it will match nobody");
    } else if (memcmp(verify, incoming, len) != 0) {
      size_t first = 0;
      while (first < len && verify[first] == incoming[first]) first++;

      reason = "The sensor stored something different from what was sent.";
      Serial.printf("      slot %d differs from what was sent, first at byte %u of %u\n",
                    slot, (unsigned) first, (unsigned) len);
      Serial.println("      this template would be present but unmatchable. Refusing it.");
    } else {
      ok = true;
      finger.getTemplateCount();
      Serial.printf("      stored and verified — %d template(s) on this sensor now\n",
                    finger.templateCount);
    }
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
    /* The session details live under data.session, not directly under data.
     *
     * This read was one level too shallow, and the effect was worse than a
     * cosmetic one: the session opened correctly and was written to the
     * database, but the terminal reported it as "? with ?, roster 0" — which
     * is exactly what a failure looks like to whoever is standing there. The
     * teacher walks away believing the scan did nothing and the class taps
     * into a session everyone has been told is not open. */
    JsonVariantConst session = response["data"]["session"];

    Serial.printf("        SESSION OPEN — %s with %s, roster %d\n",
                  (const char *) (session["subject_code"]  | "?"),
                  (const char *) (session["section_code"]  | "?"),
                  (int)          (session["roster_count"]  | 0));
    Serial.printf("        %s, teacher %s, until %s\n",
                  (const char *) (session["session_code"]  | "?"),
                  (const char *) (session["teacher_name"]  | "?"),
                  (const char *) (session["scheduled_end"] | "?"));
    /* Only if they actually can.
     *
     * The session opens on the fingerprint alone, so a terminal whose card
     * reader is dead reaches this line and invites a class to tap against a
     * reader that will never see them. The teacher then has an open session,
     * a room full of students who have all "tapped", and an attendance record
     * that stays empty — discovered at the end of the period, or the end of
     * the week, by which time nobody can reconstruct who was there.
     *
     * The session is still worth opening: it is on the server, the roster is
     * known, and an administrator can record attendance against it by hand.
     * What must not happen is anyone believing the taps landed. */
    if (rfidReady) {
      Serial.println("        Students may tap their cards now.");
    } else {
      Serial.println("        BUT this terminal's card reader is not working, so no tap will");
      Serial.println("        be recorded here. The session is open on the server and the");
      Serial.println("        roster is known, so attendance can be entered from the");
      Serial.println("        Attendance page — do not let the class tap and walk away.");
    }
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

/* =========================================================================
 * Taps that could not be sent
 *
 * The server has carried an offline-queue endpoint all along — POST
 * /api/device/sync, which takes a batch of taps WITH their original
 * timestamps, so a card presented at 08:05 is recorded at 08:05 and not at
 * whatever time the network came back. The firmware never used it, and
 * reported queue: 0 on every heartbeat because there was nothing to report.
 *
 * What that meant in a classroom: the Wi-Fi wavers for ninety seconds during
 * registration, and every student who tapped in that window is simply not
 * marked. Nobody finds out at the time — the terminal said "refused" to a
 * corridor nobody was watching — and by the time the register is checked, the
 * class has gone and there is no way to reconstruct who was there.
 *
 * A tap is a fact about a person that has already happened. It should not be
 * discarded because of a router.
 *
 * Held in RTC memory so a watchdog reset or a brownout does not take the
 * queue with it. That memory survives a reset but not a power cut, which is
 * the honest limit of this: forty taps through a network outage, not through
 * a mains failure.
 * ========================================================================= */

static void sanityCheckQueue() {
  if (tapQueueCount > TAP_QUEUE_MAX) tapQueueCount = 0;
}

static void queueTap(const String &uid, const String &requestId, uint32_t at) {
  if (tapQueueCount >= TAP_QUEUE_MAX) {
    /* Refusing the new one rather than dropping the oldest.
     *
     * Both lose a tap, and there is no version of a full queue that does not.
     * But the entries already held are facts that have been captured, and
     * overwriting them to make room for one that has not been confirmed
     * destroys known data to store unknown data. Keep what you have. */
    Serial.println("      QUEUE FULL — this tap could not be stored.");
    Serial.printf("      %d earlier taps are still waiting to be sent. Record this\n", TAP_QUEUE_MAX);
    Serial.println("      student by hand on the Attendance page.");
    return;
  }

  QueuedTap &slot = tapQueue[tapQueueCount];

  strncpy(slot.uid, uid.c_str(), sizeof(slot.uid) - 1);
  slot.uid[sizeof(slot.uid) - 1] = '\0';

  strncpy(slot.requestId, requestId.c_str(), sizeof(slot.requestId) - 1);
  slot.requestId[sizeof(slot.requestId) - 1] = '\0';

  slot.at = at;
  tapQueueCount++;

  Serial.printf("      HELD: the server could not be reached, so this tap is stored\n");
  Serial.printf("      on the terminal (%u waiting) and will be sent with its own\n",
                (unsigned) tapQueueCount);
  Serial.println("      timestamp when the network returns. Nothing has been lost.");
}

/* The whole queue in one request. Timestamps are sent as UTC, which is what
 * the board's clock holds after taking the server's epoch, and the server
 * converts on arrival. */
static void flushTapQueue() {
  sanityCheckQueue();

  if (tapQueueCount == 0 || !clockSet) return;

  LsJson body;
  JsonArray records = LS_ARRAY(body, "records");

  for (uint16_t i = 0; i < tapQueueCount; i++) {
    char when[24];
    time_t seconds = (time_t) tapQueue[i].at;
    struct tm utc;
    gmtime_r(&seconds, &utc);
    strftime(when, sizeof(when), "%Y-%m-%dT%H:%M:%SZ", &utc);

    JsonObject record = LS_ADD_OBJECT(records);
    record["rfid_uid"]   = tapQueue[i].uid;
    record["request_id"] = tapQueue[i].requestId;
    record["timestamp"]  = when;
  }

  Serial.printf("\nSync: sending %u held tap(s)\n", (unsigned) tapQueueCount);

  LsJson response;
  int    status = signedRequest("POST", "/api/device/sync",
                                jsonToString(body), &response, generateUuid());

  if (status != 200 && status != 201) {
    Serial.printf("      still not reachable (HTTP %d) — keeping them\n", status);
    return;
  }

  int accepted  = response["data"]["accepted"]  | 0;
  int duplicate = response["data"]["duplicate"] | 0;

  JsonArrayConst rejected = response["data"]["rejected"];
  int rejectedCount = rejected.isNull() ? 0 : (int) rejected.size();

  Serial.printf("      %d recorded, %d already known, %d refused\n",
                accepted, duplicate, rejectedCount);

  /* Refusals are printed rather than retried. The server refuses a queued tap
   * for reasons that do not change with time — an unknown card, a session
   * that was never open — so sending it again would fail identically and hide
   * the fact that somebody is not in the register. */
  for (int i = 0; i < rejectedCount; i++) {
    Serial.printf("      refused: %s — %s\n",
                  (const char *) (rejected[i]["code"]    | "?"),
                  (const char *) (rejected[i]["message"] | ""));
  }

  if (rejectedCount > 0) {
    Serial.println("      Those taps are NOT in the register. Enter them by hand.");
  }

  /* The server has now seen every record in the batch, whatever it decided
   * about each, so none of them should be sent again. */
  tapQueueCount = 0;
}

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

    /* The network is evidently up. If anything is waiting, this is the moment
     * to send it, rather than leaving it for the next timer. */
    if (tapQueueCount > 0) flushTapQueue();

    return;
  }

  /* A negative status is not a refusal — the server never answered, so it has
   * no opinion about this tap and the student is standing there having been
   * told nothing. Hold it.
   *
   * An HTTP refusal is different and must NOT be queued: the server considered
   * this tap and said no, for a reason that will not change by asking again.
   * Queueing those would turn a clear "no session is open" into a silent
   * retry loop that still ends in nothing being recorded. */
  if (status <= 0) {
    if (clockSet) {
      queueTap(uid, requestId, (uint32_t) time(nullptr));
    } else {
      Serial.println("      LOST: no clock, so this tap cannot be timestamped and was");
      Serial.println("      not stored. Record this student by hand.");
    }
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

  /* Every credential, not just the five above, because the claim token is
   * pasted the same way and fails the same way. */
  if (warnIfPadded(DEVICE_ID,   "LS_DEVICE_ID"))   ok = false;
  if (warnIfPadded(API_KEY,     "LS_API_KEY"))     ok = false;
  if (warnIfPadded(HMAC_SECRET, "LS_HMAC_SECRET")) ok = false;
  if (warnIfPadded(CLAIM_TOKEN, "LS_CLAIM_TOKEN")) ok = false;
  if (warnIfPadded(WIFI_SSID,   "LS_WIFI_SSID"))   ok = false;

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

  /* A scheme that is present but wrong. normaliseServerUrl() only supplies a
   * missing one; it cannot rescue "htp://" or "ws://", and HTTPClient will
   * refuse those in a way that looks like a network fault rather than a typo. */
  const char *scheme = strstr(SERVER_URL, "://");

  if (ok && scheme != nullptr
      && strncmp(SERVER_URL, "http://", 7) != 0
      && strncmp(SERVER_URL, "https://", 8) != 0) {
    Serial.println("Config: LS_SERVER_URL does not start with http:// or https://");
    Serial.printf("        It reads %s\n", SERVER_URL);
    Serial.println("        Only those two work. Anything else fails while sending the");
    Serial.println("        request, which reads as a network fault and is not one.");
    return false;
  }

  /* Caught here rather than at the first request, because the first request is
   * usually a card tap by somebody standing at the door. */
#ifndef LS_HAVE_ROOT_CA
  if (ok && strncmp(SERVER_URL, "https://", 8) == 0) {
    Serial.println("Config: LS_SERVER_URL is https://, but this sketch has no root certificate.");
    Serial.println("        Nothing can be sent: there would be no way to tell the real server");
    Serial.println("        from anything else on the Wi-Fi that answered first.");
    Serial.println("        On the server run  console.bat tls:generate  then copy");
    Serial.println("        storage\\tls\\ls_root_ca.h into this sketch's folder and re-upload.");
    Serial.println("        The sketch finds the file on its own — nothing to configure.");
    return false;
  }
#else
  /* The opposite mistake: the root is compiled in, so somebody has been
   * through the TLS setup, but the URL was never switched over and every tap
   * is still crossing the network in clear text. */
  if (ok && strncmp(SERVER_URL, "http://", 7) == 0) {
    Serial.println("Notice: this sketch has the school's root certificate, but LS_SERVER_URL");
    Serial.println("        still starts with http:// — card taps and fingerprint results are");
    Serial.println("        crossing the network unencrypted.");
    Serial.println("        Change it to https:// once Apache is serving it.");
  }
#endif

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

/* Read the version register directly, at a clock of our choosing.
 *
 * The MFRC522 library fixes its bus at 4 MHz — MFRC522_SPICLOCK, a constant
 * in its header — and that is the one variable the library will not let us
 * change. It matters because of what a STABLE single-bit error means.
 *
 * Random corruption is noise: supply ripple, a loose joint, interference.
 * Corruption that lands on the same bit every time is not random, and noise
 * does not behave that way. What does behave that way is a bus running faster
 * than the wiring can carry: the data has not finished settling when the clock
 * edge samples it, so the same bit is misread on every transfer. Long jumpers,
 * a breadboard, no ground return beside the signal — any of those cost enough
 * settling time to do it at 4 MHz while being perfectly fine at 1 MHz.
 *
 * So the test is to ask the same question more slowly. If the answer comes
 * back correct at a lower clock, the module, the wire and the supply are all
 * fine and the bus was simply being pushed too hard — which is a wiring
 * length problem, not a fault to be replaced.
 *
 * The address byte is the library's own encoding: register number shifted
 * left one, with the top bit set to mean read. */
static byte readVersionAtClock(uint32_t hz) {
  const byte VERSION_REG_READ = 0x80 | (0x37 << 1);

  SPI.beginTransaction(SPISettings(hz, MSBFIRST, SPI_MODE0));
  digitalWrite(PIN_RFID_SS, LOW);

  SPI.transfer(VERSION_REG_READ);
  byte value = SPI.transfer(0);

  digitalWrite(PIN_RFID_SS, HIGH);
  SPI.endTransaction();

  return value;
}

static void startRfid(bool verbose = true) {
  /* Is anything electrically attached to MISO?
   *
   * Every register read goes out over MISO, so once that line is dead the
   * SPI answer is 0x00 or 0xFF whatever the cause — a broken wire, a module
   * with no power, and a damaged pin are indistinguishable through the
   * library. This asks the question underneath it, using nothing but the
   * ESP32's own pull resistors.
   *
   * A powered MFRC522 that is connected holds MISO at a defined level and
   * will not be moved by a ~45 kOhm internal pull. A bare wire, or a wire to
   * an unpowered module, follows the pull exactly. So: pull it up and read,
   * pull it down and read. Two readings that follow the pull mean nothing is
   * on the other end. One that resists means something is.
   *
   * THE MODULE MUST BE SELECTED FIRST, and the first version of this did not
   * do it. Every SPI slave releases MISO to high impedance when its chip
   * select is idle — that is the whole point of the signal, since it lets
   * several devices share one bus. Measuring an unselected module therefore
   * reports "nothing is driving it" for hardware that is working perfectly,
   * and it did: a board whose SPI read returned 0x82 — a real MFRC522
   * answering with one bit corrupted — was told nothing was attached to MISO
   * at all. Two diagnostics on the same line, flatly contradicting each
   * other, and the wrong one was the confident one.
   *
   * NSS low, and RST high so the module is out of power-down, and only then
   * is the line worth reading.
   *
   * Only at boot, and only before SPI.begin(). Changing pinMode detaches the
   * pin from the SPI peripheral, and SPI.begin() re-attaches it — but it does
   * that only on the FIRST call, returning early once initialised, so running
   * this on a later retry would leave MISO detached and break a reader that
   * was working. */
  /* The MISO pull-test that used to sit here has been removed.
   *
   * It drove RST and NSS by hand before the library had initialised anything,
   * to see whether the module held MISO against an internal pull. Two things
   * were wrong with that. It answered "nothing is attached" for a module that
   * was demonstrably answering, because an unselected SPI device releases
   * MISO by design — that was patched. And it manipulated the module's pins
   * before PCD_Init(), which the minimal sketch that reads cards on this
   * hardware does not do.
   *
   * When a five-line sketch works and a long one does not, the difference is
   * the place to look, not the thing to defend. What is left below is what
   * that sketch does: begin the bus, initialise the reader, read.
   */
  SPI.begin(PIN_RFID_SCK, PIN_RFID_MISO, PIN_RFID_MOSI);
  rfid.PCD_Init();
  delay(50);

  /* Read the version nine times and take the majority.
   *
   * The first version of this demanded all nine be identical, and that gate
   * is stricter than the job it guards. A minimal sketch — SPI.begin(),
   * PCD_Init(), then straight into PICC_IsNewCardPresent() — reads cards on
   * this same hardware without ever touching the version register. So a
   * reader that glitches one read in nine reads cards perfectly, and was
   * being declared dead for it.
   *
   * That is the wrong trade. A glitch is worth reporting; it is not worth
   * refusing to read anybody's card over, and the brownout detector firing on
   * this hardware says glitches are to be expected rather than treated as
   * proof of a fault.
   *
   * Majority of nine: five agreeing readings is a reader that is answering.
   * The disagreements are still counted and still printed, because a reader
   * that needs a majority is a reader with a wiring problem worth fixing —
   * but it works in the meantime, and saying so is more useful than silence. */
  const uint8_t SAMPLES = 9;

  byte    values[SAMPLES];
  uint8_t counts[SAMPLES] = { 0 };
  uint8_t distinct        = 0;

  for (uint8_t i = 0; i < SAMPLES; i++) {
    if (i) delay(5);
    byte reading = rfid.PCD_ReadRegister(MFRC522::VersionReg);

    uint8_t seen = 0xFF;
    for (uint8_t j = 0; j < distinct; j++) if (values[j] == reading) seen = j;

    if (seen == 0xFF) { values[distinct] = reading; counts[distinct] = 1; distinct++; }
    else              { counts[seen]++; }
  }

  uint8_t best = 0;
  for (uint8_t j = 1; j < distinct; j++) if (counts[j] > counts[best]) best = j;

  byte    version   = values[best];
  uint8_t agreed    = counts[best];
  bool    stable    = (distinct == 1);

  /* One value that disagreed, for the report. */
  byte other = version;
  for (uint8_t j = 0; j < distinct; j++) if (j != best) other = values[j];

  static const byte KNOWN[] = { 0x91, 0x92, 0x88, 0x90, 0x12 };

  bool known = false;
  for (uint8_t i = 0; i < 5; i++) if (version == KNOWN[i]) known = true;

  /* How far the answer is from a real one, in bits.
   *
   * This is the difference between "no module" and "a module whose answer is
   * being corrupted", and it was being thrown away. 0x82 is 0x92 — a genuine
   * MFRC522 v2.0 — with a single bit missing. A module that is absent, or
   * unpowered, or wired to the wrong pins does not produce a value one bit
   * away from the right one; a marginal joint or a sagging supply does,
   * because the chip really is answering and the answer is not surviving the
   * trip.
   *
   * Reported as bits rather than as a verdict, because the number is the
   * evidence: one or two is a signal problem, eight is silence. */
  uint8_t nearestBits = 8;
  byte    nearest     = 0;

  for (uint8_t i = 0; i < 5; i++) {
    uint8_t bits = 0;
    for (byte diff = version ^ KNOWN[i]; diff; diff >>= 1) bits += diff & 1;

    if (bits < nearestBits) { nearestBits = bits; nearest = KNOWN[i]; }
  }

  /* On a quiet re-probe, speak only when the reading has moved.
   *
   * The version byte is the whole diagnostic while somebody is working on the
   * wiring, and a line every twenty seconds saying the same thing buries it —
   * the eye stops reading a repeating line. A CHANGE is the signal: it means
   * whatever was just touched altered how the module answers, which is how a
   * bad joint is found. Silence in between means nothing has changed. */
  static byte lastReported = 0x01;         /* no real reading is 0x01 */

  if (verbose || version != lastReported) {
    Serial.printf("Reader: version 0x%02X %s\n", version,
                  known ? (stable ? "(ok)" : "(ok, but not on every read)")
                        : "<-- not a version any MFRC522 reports");
  }

  lastReported = version;

  /* The gate is whether a module is there and talking — not whether one
   * register reads perfectly.
   *
   * This was backwards, and the evidence that it was backwards is a five-line
   * sketch: SPI.begin(), PCD_Init(), then straight into
   * PICC_IsNewCardPresent(). It reads cards on hardware that this firmware
   * declared dead, and it never looks at the version register at all. The
   * check was refusing to do the job on the strength of a measurement the
   * job does not depend on.
   *
   * The two are not equally trustworthy either, and the difference runs the
   * other way from what the old gate assumed. A version read is one raw byte
   * with no error detection of any kind: a single flipped bit turns 0x92 into
   * 0x82 and nothing notices. A card read is a protocol — anticollision, a
   * BCC check byte over the UID, a CRC — so a UID corrupted in transit is
   * rejected by the reader rather than handed up as somebody else's card.
   * Judging the safe operation by the unsafe measurement had it exactly
   * inverted.
   *
   * So: a module that answers with a real version, or within a bit or two of
   * one, is a module that is present and communicating, and cards may be
   * read. 0x00 and 0xFF are excluded explicitly — they are what an undriven
   * line reads, and 0x00 is coincidentally two bits from 0x88, which would
   * otherwise let an absent module through. */
  bool undriven      = (version == 0x00 || version == 0xFF);
  bool majorityKnown = known && agreed * 2 > SAMPLES;

  rfidReady = !undriven && (majorityKnown || nearestBits <= 2);

  /* Working, but not cleanly. Say both halves: that cards will read, so
   * nobody pulls a terminal out of a classroom that is doing its job, and
   * that the wiring is worth fixing, so nobody leaves it like this. */
  if (rfidReady && !majorityKnown && verbose) {
    Serial.printf("        0x%02X is %u bit(s) off 0x%02X, so the module is present and\n",
                  version, nearestBits, nearest);
    Serial.println("        answering — the version byte is arriving corrupted.");
    Serial.println("        CARDS WILL STILL READ. A card read is checked as it arrives —");
    Serial.println("        anticollision, a BCC byte over the UID, a CRC — so a UID damaged");
    Serial.println("        in transit is rejected rather than read as another student. The");
    Serial.println("        version byte has no such check, which is why it shows the damage");
    Serial.println("        first and why it is not a reason to refuse cards.");
    Serial.println("        Worth fixing all the same: shorter leads, a firmer joint, a");
    Serial.println("        steadier supply. Until then the reader works.");
  } else if (rfidReady && !stable && verbose) {
    Serial.printf("        %u of %u reads agreed; one returned 0x%02X.\n",
                  (unsigned) agreed, (unsigned) SAMPLES, other);
    Serial.println("        Cards will read. The disagreement is a wiring or supply problem");
    Serial.println("        worth fixing — shorter leads, a firmer joint, a steadier supply —");
    Serial.println("        but it is not stopping the reader from working.");
  }

  if (!rfidReady && verbose) {
    /* The reading, interpreted, before the checklist. Which of these four it
     * is decides whether the next hour goes on wiring or on a replacement. */
    if (!stable) {
      Serial.printf("        UNSTABLE: it answered 0x%02X and 0x%02X on the same boot.\n",
                    version, other);
      Serial.println("        A module that is absent answers the same way every time. One that");
      Serial.println("        changes its answer is connected and being disturbed — a cracked or");
      Serial.println("        dirty joint, or a supply dipping under load. Not a dead module.");
    } else if (version == (0x80 | (0x37 << 1))) {
      /* The board reading back the byte it just sent.
       *
       * 0xEE is the address the ESP32 transmits on MOSI to ask for this
       * register. Getting it back on MISO means the two lines are carrying
       * the same signal — swapped at one end, or shorted together — so the
       * board is listening to itself and the module is never heard.
       *
       * Worth naming precisely, because it is indistinguishable from noise
       * to anyone who does not know what 0xEE is, and it is the one wiring
       * mistake on this module that people make most. */
      Serial.println("        0xEE is the exact byte this board TRANSMITS to request that");
      Serial.println("        register. Reading it back means MISO is carrying what MOSI sent,");
      Serial.println("        so the two are swapped at one end or shorted together — the board");
      Serial.println("        is listening to itself and never hears the module.");
      Serial.printf("        MISO is the module's output and belongs on GPIO %d;\n", PIN_RFID_MISO);
      Serial.printf("        MOSI is this board's output and belongs on GPIO %d.\n", PIN_RFID_MOSI);
    } else if (version == 0x00) {
      Serial.println("        0x00 means nothing is driving the MISO line at all: no power to the");
      Serial.println("        module, or MISO not connected.");
    } else if (version == 0xFF) {
      Serial.println("        0xFF means the MISO line is sitting high with nothing driving it —");
      Serial.println("        usually MISO disconnected, or the module unpowered.");
    } else if (nearestBits <= 2) {
      Serial.printf("        0x%02X is 0x%02X with %u bit(s) lost — and 0x%02X IS a real MFRC522.\n",
                    version, nearest, nearestBits, nearest);
      Serial.println("        So the module is answering; the answer is being corrupted on the way.");
      Serial.println("        That is signal integrity, not a fault in the module: the supply, the");
      Serial.println("        ground, or the joints. Replacing the reader will not change it.");
    }

    /* The electrical answer, which narrows the list above to one line of it.
     *
     * This is measured rather than inferred, and it is the one question the
     * SPI reading cannot settle: whether a powered module is on the end of
     * that wire at all.
     *
     * Except when the SPI reading has already settled it the other way. A
     * value within two bits of a real version means a module received the
     * register address, decoded it, and shifted eight bits back — evidence no
     * pull-resistor test can outweigh. When the two disagree, the transaction
     * is the stronger witness and the floating verdict is suppressed rather
     * than printed beside it.
     *
     * Two diagnostics contradicting each other is worse than one of them
     * being absent: it costs the reader their trust in both, and they cannot
     * tell which to act on. */
    bool moduleDidAnswer = known || nearestBits <= 2;

    /* Ask the same question more slowly.
     *
     * Only worth doing when the module has proved it is there, because a
     * slower clock cannot conjure an answer out of a disconnected wire — it
     * would just return 0x00 twice more and waste the reader's attention. */
    if (verbose && moduleDidAnswer && !rfidReady) {
      byte slow   = readVersionAtClock(1000000);
      byte slower = readVersionAtClock(250000);

      Serial.println();
      Serial.printf("        Same register at 1 MHz: 0x%02X, at 250 kHz: 0x%02X\n", slow, slower);

      bool slowIsGood = (slow   == 0x91 || slow   == 0x92 || slow   == 0x88
                      || slow   == 0x90 || slow   == 0x12);
      bool slowerGood = (slower == 0x91 || slower == 0x92 || slower == 0x88
                      || slower == 0x90 || slower == 0x12);

      if (slowIsGood || slowerGood) {
        /* The decisive result. Nothing is broken; the bus is too fast for
         * the wiring, and the wiring is what has to change. */
        Serial.println("        CORRECT at the slower clock. Nothing is faulty — the module, the");
        Serial.println("        wires and the supply are all fine. The bus is being clocked");
        Serial.println("        faster than this wiring can carry, so the data has not settled");
        Serial.println("        when the clock samples it and the same bit is misread each time.");
        Serial.println("        Shorten the jumpers, take the reader off the breadboard, and run");
        Serial.println("        a ground wire alongside the SPI wires rather than to a far corner.");
        Serial.println("        Under about 10 cm, direct board-to-board, is reliable at 4 MHz.");
      } else {
        Serial.println("        Still wrong at the slower clock, so this is not bus speed. The");
        Serial.println("        module answers but the answer is corrupted at any rate, which");
        Serial.println("        points at the joints and the ground rather than the length.");
      }

      Serial.println();
    }

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

static void startFingerprint(bool verbose = true) {
  fingerSerial.begin(FINGERPRINT_BAUD, SERIAL_8N1, PIN_FINGER_RX, PIN_FINGER_TX);
  delay(100);

  fingerReady = finger.verifyPassword();

  if (!fingerReady) {
    if (!verbose) return;
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
  String host = serverBase;
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

  /* Before anything opens a TLS connection: a board that still believes it is
   * 1970 rejects its own server's certificate as not yet valid. */
  seedClockForTls();
  sanityCheckQueue();

  if (tapQueueCount > 0) {
    Serial.printf("Queue: %u tap(s) held from before this restart — they will be sent\n",
                  (unsigned) tapQueueCount);
    Serial.println("       with their original times once the server is reachable.");
  }

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

  if (!checkConfig()) {
    haltReason = "the credentials above are still placeholders — paste this "
                 "terminal's provisioning values into the top of the sketch";
    return;
  }

  /* Before anything builds a URL from it, and before checkSubnet() reads it. */
  normaliseServerUrl();

  startRfid();
  startFingerprint();

  /* Have the two modules swapped places since the last boot?
   *
   * One working and the other not is easy to read as two unrelated faults,
   * chased one at a time. But if the pair TRADE — the reader comes up on the
   * boot the sensor drops, and back again — that is not two faults. It is one
   * rail that cannot carry both, and every hour spent on the module that is
   * currently quiet is spent on the wrong thing.
   *
   * A single boot cannot see it; only the comparison can, so the previous
   * verdict is kept in RTC memory. It also explains why this arrives late in
   * a diagnosis: while one module was dead it drew almost nothing, and the
   * other had the whole rail to itself. Repairing the first is what creates
   * the contention that stops the second. */
  static RTC_DATA_ATTR bool  hadRfid    = false;
  static RTC_DATA_ATTR bool  hadFinger  = false;
  static RTC_DATA_ATTR bool  haveSeenAny = false;

  if (haveSeenAny && rfidReady != hadRfid && fingerReady != hadFinger
      && rfidReady != fingerReady) {
    Serial.println();
    Serial.println("  ---- THE TWO MODULES HAVE SWAPPED ----");
    Serial.printf("  Last boot: reader %s, sensor %s\n",
                  hadRfid ? "OK" : "silent", hadFinger ? "OK" : "silent");
    Serial.printf("  This boot: reader %s, sensor %s\n",
                  rfidReady ? "OK" : "silent", fingerReady ? "OK" : "silent");
    Serial.println();
    Serial.println("  They are on different buses and cannot interfere with each other's");
    Serial.println("  signals. What they share is the 3.3 V rail and the ground, so a pair");
    Serial.println("  that trades places is one supply that cannot carry both — not two");
    Serial.println("  faults taking turns.");
    Serial.println("  Note that repairing one is what creates this: a dead module draws");
    Serial.println("  almost nothing, so the other had the whole rail until now.");
    Serial.println();
    Serial.println("  The fix is to stop sharing. If the sensor is an R307 — a sealed");
    Serial.println("  cylinder on a cable — move its VCC from 3V3 to VIN. It has its own");
    Serial.println("  regulator, runs from 5 V, and that takes it off the 3.3 V rail");
    Serial.println("  entirely, leaving the whole of it for the reader.");
    Serial.println("  A bare AS608 has no regulator and must stay on 3V3; then the answer");
    Serial.println("  is 100 uF + 100 nF at EACH module and a supply that can deliver 1 A.");
    Serial.println();
  }

  hadRfid     = rfidReady;
  hadFinger   = fingerReady;
  haveSeenAny = true;

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

  /* Deliberately NOT a halt yet, even though a terminal with two dead modules
   * can do nothing at all. It still has one useful job left: joining the
   * network and heartbeating, which is how the server — and the person
   * staring at "Waiting for the terminal…" in a browser — finds out that the
   * modules are dead. Halting here would take that away and leave the board
   * looking simply absent, which points at the network instead of at the
   * wiring. The halt is set after the first heartbeat has gone out. */

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
    haltReason = "not joined to Wi-Fi";
    return;
  }

  Serial.print("Wi-Fi: connected, IP ");
  Serial.println(WiFi.localIP());
  Serial.printf("       server: %s\n", serverBase.c_str());

  checkSubnet();

  if (!claimDevice()) {
    Serial.println("Stopping: every signed request is refused until the board is claimed.");
    haltReason = "this board has not claimed its API key — see the refusal above";
    return;
  }

  /* Retried, because one lost packet at boot used to cost a site visit.
   *
   * A single failure here halted the terminal until somebody walked over and
   * pressed EN/RST — and the commonest cause is a timeout, which is transient
   * by definition: a busy Wi-Fi moment while the classroom fills up, an access
   * point still settling after a power cut. The board is mounted on a wall and
   * may be powered on before the network is ready, so the first attempt is the
   * least likely of the five to succeed.
   *
   * Backing off between tries rather than hammering: if the network is
   * genuinely still coming up, spacing the attempts is what lets it. */
  bool haveClock = false;

  for (uint8_t attempt = 1; attempt <= 5 && !haveClock; attempt++) {
    if (attempt > 1) {
      Serial.printf("Clock: retrying (%u of 5)\n", attempt);
      delay(attempt * 2000);
    }

    haveClock = syncClockFromServer();
  }

  if (!haveClock) {
    Serial.println("Pausing: without the server's clock, every signature is refused.");
    Serial.println("         This one recovers by itself — the terminal will keep trying");
    Serial.println("         and start working the moment the server answers.");
    haltReason      = "no clock yet — the server's time could not be read, so nothing "
                      "can be signed. Still trying.";
    haltIsTransient = true;
    return;
  }

  /* Now the server knows which modules answered, so the browser can stop
   * guessing. This has to happen before any halt below it. */
  sendHeartbeat();

  Serial.println();

  /* "Ready" used to be printed unconditionally, and that was the last thing a
   * board with two dead modules ever said. It then sat in the loop doing
   * nothing, because every poll begins by checking the module it needs and
   * returning silently when it is missing — so an enrolment request was never
   * picked up, a card was never read, and a finger never opened a session.
   * From the outside that is indistinguishable from a terminal that is not
   * running at all, and the word "Ready" actively argued against looking at
   * the wiring.
   *
   * What the terminal can actually do is now what it claims. */
  if (!rfidReady && !fingerReady) {
    haltReason         = "neither module answered — this terminal cannot read a card or a "
                         "finger, so nothing will ever be picked up (see the wiring notes above)";
    haltedButReporting = true;
    Serial.println("NOT ready: both modules are silent. The server has been told, so the");
    Serial.println("           Fingerprints and RFID Cards pages will say so instead of");
    Serial.println("           waiting for a terminal that cannot answer.");
    Serial.println();
    return;
  }

  if (!fingerReady) {
    Serial.println("HALF ready: the card reader works, the fingerprint sensor does not.");
    Serial.println("            Students can tap, but no teacher can open a session here");
    Serial.println("            by fingerprint and no enrolment can be performed on this");
    Serial.println("            terminal. Use the password failover on the teacher");
    Serial.println("            dashboard until the sensor is fixed.");
    Serial.println();
    Serial.println("            If the sensor worked before the reader did, suspect the");
    Serial.println("            shared 3.3 V rail rather than the sensor. A dead reader draws");
    Serial.println("            almost nothing; a working one takes its share, and the sensor");
    Serial.println("            is what runs short. An R307 — the sealed cylinder on a cable —");
    Serial.println("            has its own regulator: move its VCC from 3V3 to VIN and it");
    Serial.println("            leaves the 3.3 V rail to the reader entirely.");
  } else if (!rfidReady) {
    Serial.println("HALF ready: the fingerprint sensor works, the card reader does not.");
    Serial.println("            A teacher can open a session here, but no student card");
    Serial.println("            will be read and no card can be issued at this terminal.");
  } else {
    Serial.println("Ready. A teacher scans a finger to open the session; students tap after.");
  }

  Serial.println();
}

/* =========================================================================
 * The loop
 * ========================================================================= */

void loop() {
  /* setup() gave up, so there is nothing this loop can usefully do. Say why,
   * once every ten seconds, instead of polling an endpoint that will refuse
   * every request and throwing the refusal away.
   *
   * Ten seconds is deliberate: often enough that the reason is on screen
   * whenever somebody opens the serial monitor, rare enough that it does not
   * bury a line they are trying to read. */
  if (haltReason != nullptr) {
    if (lastHaltNag == 0 || millis() - lastHaltNag >= 10000) {
      lastHaltNag = millis();
      Serial.printf("%s: %s\n", haltIsTransient ? "PAUSED" : "HALTED", haltReason);
      Serial.println("        Nothing will be read or recorded until that clears.");

      if (!haltIsTransient) {
        Serial.println("        Fix it, then press the EN/RST button on the board.");
      }
    }

    /* Still reporting, so the server keeps showing the true reason rather
     * than letting the terminal fade to "offline" and point at the network. */
    if (haltedButReporting && millis() - lastHeartbeat >= HEARTBEAT_MS) {
      lastHeartbeat = millis();
      sendHeartbeat();
    }

    /* Re-probe the modules while halted for them.
     *
     * The modules are started once in setup(), so a wire re-seated afterwards
     * changes nothing until somebody presses EN/RST — and the person holding
     * the screwdriver is usually not the person watching the serial monitor.
     * Worse, the commonest fault here is a cracked or dirty joint, which is
     * found by moving connections one at a time and seeing what happens. That
     * loop is unusable at one reset per attempt.
     *
     * Fifteen seconds is the width of that loop: wiggle a connection, wait,
     * read the line. The reader's version byte is printed every time on
     * purpose — 0x00 or 0xFF means no communication at all, while a value
     * that CHANGES between probes means the link is alive but unreliable,
     * which points at the joint rather than at the module. */
    if (haltedButReporting && millis() - lastModuleRetry >= 15000) {
      lastModuleRetry = millis();

      startRfid(false);
      startFingerprint(false);

      Serial.printf("Modules: reader %s, sensor %s\n",
                    rfidReady   ? "OK" : "silent",
                    fingerReady ? "OK" : "silent");

      if (rfidReady || fingerReady) {
        Serial.println();
        Serial.println("Recovered: a module answered. Resuming normal service.");

        if (!rfidReady)   Serial.println("           The card reader is still silent.");
        if (!fingerReady) Serial.println("           The fingerprint sensor is still silent.");

        Serial.println();

        haltReason         = nullptr;
        haltedButReporting = false;
        lastHaltNag        = 0;

        sendHeartbeat();     /* tell the server straight away, not in 30 s */
      }
    }

    /* A halt that can clear itself gets retried, so a network that comes back
     * puts the terminal back to work without anyone walking to the classroom.
     * Every 30 seconds: often enough that a passing fault costs one lesson's
     * first minute rather than the lesson, slow enough that a server which is
     * genuinely down is not hammered by every terminal in the building. */
    if (haltIsTransient && millis() - lastHaltRetry >= 30000) {
      lastHaltRetry = millis();

      if (WiFi.status() != WL_CONNECTED) {
        Serial.println("        Wi-Fi is down; reconnecting before trying again.");
        WiFi.reconnect();
      } else if (syncClockFromServer()) {
        Serial.println("Recovered: the server answered and the clock is set.");
        Serial.println("           Back to normal — a teacher may open the session now.");

        haltReason      = nullptr;
        haltIsTransient = false;
        lastHaltNag     = 0;

        sendHeartbeat();
      }
    }

    delay(200);
    return;
  }

  /* One module down is not a halt — the other half of the terminal still
   * works — but it must not be silent either. Each poll below begins by
   * checking the module it needs and returning if it is missing, which is the
   * right thing to do and reads from outside as a terminal that is simply
   * ignoring the request. Once a minute, say which half is missing. */
  /* Retry the missing half, not merely complain about it.
   *
   * The re-probe added for a fully dead terminal only ran while it was
   * halted, and a terminal with ONE working module is not halted — it goes
   * on doing real work and never touches the other again. Which removes the
   * retry at exactly the moment it becomes most useful: somebody is standing
   * there with one module proven good, working on the other, and the board
   * will not look until it is reset.
   *
   * The interval is fast for the first five minutes after a reset and slow
   * afterwards, because those two periods have different people in them.
   *
   * A board that has just been reset almost always has somebody in front of
   * it holding a wire, and a cracked joint is found by pressing one
   * connection at a time and seeing whether the reading moves. At twenty
   * seconds that loop is unusable — press, wait, forget which one you pressed.
   * At three it is a conversation with the hardware.
   *
   * A terminal that has been up for hours has nobody near it, so the probe is
   * pure cost against a classroom it is also serving, and slow is right.
   *
   * Nothing is printed unless the reading changes, so the fast phase is not
   * noisy — it is silent until something you touch makes a difference. */
  uint32_t retryEvery = (millis() - bootMillis < 300000UL) ? 3000 : 20000;

  if ((!rfidReady || !fingerReady) && !busy && millis() - lastModuleRetry >= retryEvery) {
    lastModuleRetry = millis();

    bool hadRfid   = rfidReady;
    bool hadFinger = fingerReady;

    if (!rfidReady)   startRfid(false);
    if (!fingerReady) startFingerprint(false);

    if (rfidReady != hadRfid || fingerReady != hadFinger) {
      Serial.println();
      Serial.printf("Recovered: the %s is answering now.\n",
                    rfidReady != hadRfid ? "card reader" : "fingerprint sensor");

      if (rfidReady && fingerReady) {
        Serial.println("           Both modules are working — this terminal is fully ready.");
      }

      Serial.println();
      sendHeartbeat();        /* the pages should stop saying it is broken */
    } else if (millis() - lastModuleNag >= 60000) {
      lastModuleNag = millis();
      Serial.printf("Module: the %s is not responding, so anything needing it will not be\n",
                    rfidReady ? "fingerprint sensor" : "card reader");
      Serial.println("        picked up from the server. The rest of the terminal is working.");
    }
  }

  /* Every poll below is a blocking HTTP request, and a card held against the
   * reader while one is in flight is not seen — invisible from outside, and
   * it reads as a dead reader. Card reading therefore comes last, after the
   * polls have had their turn, rather than being starved behind them. */
  if (!busy && millis() - lastHeartbeat >= HEARTBEAT_MS) {
    lastHeartbeat = millis();
    sendHeartbeat();
  }

  /* Anything held during an outage goes as soon as there is a network again.
   * Twenty seconds is soon enough that a class's taps land while the lesson
   * is still running, and rare enough not to hammer a server that is down. */
  if (tapQueueCount > 0 && !busy && millis() - lastQueueFlush >= 20000) {
    lastQueueFlush = millis();
    flushTapQueue();
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
