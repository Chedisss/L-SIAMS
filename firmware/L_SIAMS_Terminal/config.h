/* ===========================================================================
 * L-SIAMS Attendance Terminal — configuration
 * ---------------------------------------------------------------------------
 * Hardware: ESP32 DevKit V1 + MFRC522 (SPI) + R307 (UART) + SSD1306 OLED (I2C)
 *
 * Nothing secret lives in this file. Credentials arrive through the
 * provisioning JSON produced by the server and are written to NVS on first
 * boot — so this source can sit in a repository, and a stolen board reveals
 * nothing about any other terminal.
 * =========================================================================== */

#ifndef LSIAMS_CONFIG_H
#define LSIAMS_CONFIG_H

#define FIRMWARE_VERSION      "1.0.0"
#define FIRMWARE_BUILD_DATE   __DATE__ " " __TIME__

/* ---------------------------------------------------------------- pins --- */

/* MFRC522 — VSPI. */
#define PIN_RFID_SS           5
#define PIN_RFID_RST          27
#define PIN_SPI_SCK           18
#define PIN_SPI_MISO          19
#define PIN_SPI_MOSI          23

/* R307 fingerprint sensor — UART2. The sensor idles at 57600 baud. */
#define PIN_FINGERPRINT_RX    16
#define PIN_FINGERPRINT_TX    17
#define FINGERPRINT_BAUD      57600

/* SSD1306 OLED — I2C. */
#define PIN_OLED_SDA          21
#define PIN_OLED_SCL          22
#define OLED_ADDRESS          0x3C
#define OLED_WIDTH            128
#define OLED_HEIGHT           64

/* Feedback. */
#define PIN_BUZZER            25
#define PIN_LED_GREEN         26
#define PIN_LED_RED           33
#define PIN_LED_BLUE          32
#define PIN_LED_AMBER         14

/* Optional: a momentary switch that lets a teacher close attendance without
 * the web UI. Held for 3 seconds to avoid accidental presses. */
#define PIN_BUTTON            4
#define BUTTON_HOLD_MS        3000

/* --------------------------------------------------------------- timing --- */

#define WIFI_CONNECT_TIMEOUT_MS     20000
#define WIFI_RETRY_INTERVAL_MS      5000
#define HTTP_TIMEOUT_MS             8000

#define DEFAULT_HEARTBEAT_INTERVAL_S 30
#define DEFAULT_SYNC_INTERVAL_S      60
#define TIME_SYNC_INTERVAL_S         21600   /* six hours */

/* Jitter so fifty terminals do not all report on the same tick (Part 17.8). */
#define HEARTBEAT_JITTER_S           5

#define CARD_DEBOUNCE_MS             2500    /* ignore the same card this long */
#define DISPLAY_HOLD_MS              2500
#define DISPLAY_HOLD_LONG_MS         3000

/* --------------------------------------------------------------- queue ---- */

/* Each queued record is ~120 bytes of JSON. 500 records fits comfortably in
 * the NVS partition and covers a full day of one classroom going offline. */
#define DEFAULT_QUEUE_LIMIT          500
#define QUEUE_SYNC_BATCH             25
#define QUEUE_WARNING_THRESHOLD      100

/* ------------------------------------------------------------- security --- */

#define API_HEADER_DEVICE_ID   "X-LSIAMS-Device-Id"
#define API_HEADER_API_KEY     "X-LSIAMS-Api-Key"
#define API_HEADER_TIMESTAMP   "X-LSIAMS-Timestamp"
#define API_HEADER_NONCE       "X-LSIAMS-Nonce"
#define API_HEADER_SIGNATURE   "X-LSIAMS-Signature"
#define API_HEADER_REQUEST_ID  "X-LSIAMS-Request-Id"

#define NONCE_BYTES            16
#define MAX_CLOCK_SKEW_S       30

/* Fingerprint lockout mirrors the server-side policy so the terminal stops
 * accepting scans locally rather than hammering a locked endpoint. */
#define FP_MAX_FAILURES        5
#define FP_LOCK_DURATION_MS    300000  /* 5 minutes */

/* ------------------------------------------------------------ endpoints --- */

#define EP_DEVICE_CLAIM        "/api/device/claim"
#define EP_DEVICE_AUTH         "/api/device/authenticate"
#define EP_DEVICE_HEARTBEAT    "/api/device/heartbeat"
#define EP_DEVICE_SYNC         "/api/device/sync"
#define EP_DEVICE_LOG          "/api/device/log"
#define EP_DEVICE_TIME         "/api/device/time"
/* Enrolment. The poll is what makes "Start scan" in the browser feel
 * immediate; an idle terminal has nothing else to do, and the reply is a few
 * hundred bytes. The step timeout is per finger placement — long enough for
 * someone to be fetched from a classroom, short enough that a terminal is not
 * held open by a person who walked away. */
#define ENROLL_POLL_INTERVAL_MS  2000
#define ENROLL_STEP_TIMEOUT_MS   20000

#define EP_ENROLL_PENDING      "/api/fingerprint/enrollment"
#define EP_ENROLL_PROGRESS     "/api/fingerprint/enrollment/progress"
#define EP_ENROLL_COMPLETE     "/api/fingerprint/enrollment/complete"
#define EP_ENROLL_FAILED       "/api/fingerprint/enrollment/failed"
#define EP_ENROLL_DISCARDED    "/api/fingerprint/enrollment/discarded"
#define EP_ATTENDANCE_START    "/api/attendance/start"
#define EP_ATTENDANCE_TAP      "/api/attendance/tap"
#define EP_ATTENDANCE_END      "/api/attendance/end"

/* ------------------------------------------------------------- storage ---- */

#define NVS_NAMESPACE          "lsiams"
#define NVS_KEY_PROVISIONED    "prov"
#define NVS_KEY_DEVICE_ID      "devid"
#define NVS_KEY_API_KEY        "apikey"
#define NVS_KEY_HMAC_SECRET    "hmac"
#define NVS_KEY_SERVER_URL     "server"
#define NVS_KEY_WIFI_SSID      "ssid"
#define NVS_KEY_WIFI_PASS      "wpass"
#define NVS_KEY_CLAIM_TOKEN    "claim"
#define NVS_KEY_CLAIMED        "claimed"
#define NVS_KEY_FINGERPRINT    "fpcert"
#define NVS_KEY_QUEUE_COUNT    "qcount"
#define NVS_KEY_QUEUE_PREFIX   "q"

/* --------------------------------------------------------------- debug ---- */

#define SERIAL_BAUD            115200

/* Set to 0 for deployment. Serial output is useful during installation but
 * becomes an information leak once the enclosure is sealed and the USB port
 * is meant to be inaccessible (Part 6, physical security). */
#define DEBUG_LOGGING          1

#if DEBUG_LOGGING
  #define LOG(...)    Serial.printf(__VA_ARGS__)
  #define LOGLN(x)    Serial.println(x)
#else
  #define LOG(...)    do {} while (0)
  #define LOGLN(x)    do {} while (0)
#endif

#endif /* LSIAMS_CONFIG_H */
