-- ===========================================================================
-- L-SIAMS · 004 · Classrooms, IoT devices, API keys, heartbeats, device logs
-- ===========================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS classrooms (
  classroom_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_number  VARCHAR(30)  NOT NULL,
  building     VARCHAR(60)  NULL,
  floor        VARCHAR(20)  NULL,
  capacity     SMALLINT UNSIGNED NOT NULL DEFAULT 50,
  location_note VARCHAR(255) NULL,
  status       ENUM('active','inactive','maintenance') NOT NULL DEFAULT 'active',
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at   TIMESTAMP NULL,
  UNIQUE KEY uq_classroom_room (room_number),
  KEY idx_classroom_status (status, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sections
  ADD CONSTRAINT fk_section_classroom FOREIGN KEY (default_classroom_id) REFERENCES classrooms(classroom_id)
    ON UPDATE CASCADE ON DELETE SET NULL;

-- ------------------------------------------------------------- devices ----
CREATE TABLE IF NOT EXISTS devices (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id      VARCHAR(32)  NOT NULL COMMENT 'Public identifier: DEV-{YYYY}-{NNNN}',
  device_name    VARCHAR(120) NOT NULL,
  mac_address    VARCHAR(17)  NOT NULL COMMENT 'Normalised uppercase colon-separated',
  serial_number  VARCHAR(60)  NULL,
  classroom_id   INT UNSIGNED NULL,
  -- Part 16.8: one terminal per room is the default deployment; entry/exit
  -- pairs are supported without any schema change.
  device_role    ENUM('entry','exit','both') NOT NULL DEFAULT 'both',
  firmware_version VARCHAR(20) NOT NULL DEFAULT '0.0.0',
  ip_address     VARCHAR(45)  NULL,
  ip_allowlist   VARCHAR(255) NULL COMMENT 'Optional comma-separated CIDR restriction',
  wifi_ssid      VARCHAR(64)  NULL,
  wifi_signal    SMALLINT     NULL,
  timezone       VARCHAR(64)  NOT NULL DEFAULT 'Asia/Manila',
  heartbeat_interval_sec SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  sync_interval_sec      SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  offline_queue_limit    SMALLINT UNSIGNED NOT NULL DEFAULT 500,
  queue_depth    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  uptime_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  battery_percent TINYINT UNSIGNED NULL,
  status         ENUM('pending','active','offline','disabled','suspended','decommissioned') NOT NULL DEFAULT 'pending',
  claim_status   ENUM('unclaimed','claimed','expired') NOT NULL DEFAULT 'unclaimed',
  claimed_at     DATETIME     NULL,
  last_heartbeat_at DATETIME  NULL,
  last_seen_ip   VARCHAR(45)  NULL,
  locked_until   DATETIME     NULL COMMENT 'Fingerprint failure lockout',
  registered_by  INT UNSIGNED NULL,
  location_note  VARCHAR(255) NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at     TIMESTAMP NULL,
  UNIQUE KEY uq_device_public_id (device_id),
  UNIQUE KEY uq_device_mac (mac_address),
  KEY idx_device_classroom (classroom_id),
  KEY idx_device_status (status, last_heartbeat_at),
  CONSTRAINT fk_device_classroom  FOREIGN KEY (classroom_id)  REFERENCES classrooms(classroom_id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_device_registrant FOREIGN KEY (registered_by) REFERENCES users(user_id)           ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One live device per classroom per role. `live_slot` is NULL for anything not
-- currently serving (disabled, decommissioned, deleted), and NULLs do not
-- collide — so retired hardware never blocks a replacement, while two active
-- terminals in the same role in one room is impossible.
ALTER TABLE devices
  ADD COLUMN live_slot VARCHAR(40)
    GENERATED ALWAYS AS (
      CASE WHEN status IN ('pending','active','offline','suspended') AND deleted_at IS NULL
           THEN CONCAT(CAST(classroom_id AS CHAR), ':', device_role)
           ELSE NULL END
    ) STORED,
  ADD UNIQUE KEY uq_one_device_per_classroom_role (live_slot);

-- ------------------------------------------------------------ API keys ----
-- Part 19.4. key_id is a public lookup handle stored in the clear; the secret
-- is never stored at all, only its peppered SHA-256. The HMAC secret must be
-- recoverable to verify signatures, so it is encrypted (AES-256-GCM), not hashed.
CREATE TABLE IF NOT EXISTS api_keys (
  api_key_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  key_id            VARCHAR(40)  NOT NULL COMMENT 'Public: lsk_XXXXXXXXXXX',
  device_row_id     INT UNSIGNED NULL,
  user_id           INT UNSIGNED NULL COMMENT 'User-scoped tokens (Part 19.2, default disabled)',
  secret_hash       CHAR(64)     NOT NULL COMMENT 'SHA-256(secret || server pepper)',
  secret_last_four  CHAR(4)      NOT NULL COMMENT 'Display only',
  hmac_secret_encrypted VARBINARY(512) NOT NULL COMMENT 'AES-256-GCM, key held in APP_KEY',
  scope             VARCHAR(120) NOT NULL DEFAULT 'device',
  status            ENUM('active','rotating','revoked','expired','suspended') NOT NULL DEFAULT 'active',
  rotated_from      INT UNSIGNED NULL,
  grace_expires_at  DATETIME     NULL COMMENT 'Old key stays valid until this instant during rotation',
  expires_at        DATETIME     NULL,
  revoked_at        DATETIME     NULL,
  revoked_by        INT UNSIGNED NULL,
  revoke_reason     VARCHAR(255) NULL,
  last_used_at      DATETIME     NULL,
  last_used_ip      VARCHAR(45)  NULL,
  use_count         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  consecutive_signature_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_by        INT UNSIGNED NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_api_key_id (key_id),
  KEY idx_apikey_device (device_row_id, status),
  KEY idx_apikey_user (user_id, status),
  KEY idx_apikey_status (status, expires_at),
  CONSTRAINT fk_apikey_device  FOREIGN KEY (device_row_id) REFERENCES devices(id)         ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_apikey_user    FOREIGN KEY (user_id)       REFERENCES users(user_id)      ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_apikey_rotated FOREIGN KEY (rotated_from)  REFERENCES api_keys(api_key_id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_apikey_creator FOREIGN KEY (created_by)    REFERENCES users(user_id)      ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_apikey_revoker FOREIGN KEY (revoked_by)    REFERENCES users(user_id)      ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Keys are never deleted from history — only their status changes (Part 19.6).
CREATE TABLE IF NOT EXISTS api_key_history (
  history_id   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  api_key_id   INT UNSIGNED NOT NULL,
  device_row_id INT UNSIGNED NULL,
  event        ENUM('generated','rotated','revoked','expired','suspended','resumed','ip_anomaly','auto_revoked') NOT NULL,
  reason       VARCHAR(255) NULL,
  performed_by INT UNSIGNED NULL,
  ip_address   VARCHAR(45)  NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_akh_key (api_key_id, created_at),
  KEY idx_akh_device (device_row_id, created_at),
  CONSTRAINT fk_akh_key    FOREIGN KEY (api_key_id)    REFERENCES api_keys(api_key_id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_akh_device FOREIGN KEY (device_row_id) REFERENCES devices(id)          ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_akh_user   FOREIGN KEY (performed_by)  REFERENCES users(user_id)       ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single-use first-boot credential activation (Part 19.5).
CREATE TABLE IF NOT EXISTS device_claims (
  claim_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_row_id INT UNSIGNED NOT NULL,
  claim_token_hash CHAR(64)  NOT NULL,
  expires_at    DATETIME     NOT NULL,
  claimed_at    DATETIME     NULL,
  claimed_ip    VARCHAR(45)  NULL,
  claimed_mac   VARCHAR(17)  NULL,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_claim_token (claim_token_hash),
  KEY idx_claim_device (device_row_id, expires_at),
  CONSTRAINT fk_claim_device FOREIGN KEY (device_row_id) REFERENCES devices(id)   ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_claim_user   FOREIGN KEY (created_by)    REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- heartbeats ----
CREATE TABLE IF NOT EXISTS device_heartbeats (
  heartbeat_id  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_row_id INT UNSIGNED NOT NULL,
  firmware_version VARCHAR(20) NULL,
  wifi_signal   SMALLINT     NULL,
  queue_depth   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  uptime_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  battery_percent TINYINT UNSIGNED NULL,
  free_heap_bytes INT UNSIGNED NULL,
  active_session_id BIGINT UNSIGNED NULL,
  ip_address    VARCHAR(45)  NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_heartbeat_device (device_row_id, created_at),
  KEY idx_heartbeat_created (created_at) COMMENT '90-day retention sweep',
  CONSTRAINT fk_heartbeat_device FOREIGN KEY (device_row_id) REFERENCES devices(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_logs (
  log_id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_row_id INT UNSIGNED NULL,
  device_id_text VARCHAR(32) NULL COMMENT 'Preserved even when the device row is unknown',
  event         ENUM('boot','shutdown','restart','wifi_connected','wifi_lost','sync_started',
                     'sync_completed','sync_failed','auth_success','auth_failed','claim',
                     'queue_warning','queue_full','error','firmware_update','offline','online',
                     'time_sync','locked','unlocked') NOT NULL,
  severity      ENUM('info','warning','error','critical') NOT NULL DEFAULT 'info',
  message       VARCHAR(500) NULL,
  context_json  JSON         NULL,
  ip_address    VARCHAR(45)  NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_devlog_device (device_row_id, created_at),
  KEY idx_devlog_event (event, created_at),
  CONSTRAINT fk_devlog_device FOREIGN KEY (device_row_id) REFERENCES devices(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------ replay protection & idempotency ----
-- Part 6: a nonce may be used exactly once per device. The unique key is the
-- enforcement; the application never has to "check then insert" and race.
CREATE TABLE IF NOT EXISTS device_nonces (
  nonce_id      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_row_id INT UNSIGNED NOT NULL,
  nonce         VARCHAR(64)  NOT NULL,
  used_at       DATETIME     NOT NULL,
  UNIQUE KEY uq_device_nonce (device_row_id, nonce),
  KEY idx_nonce_used (used_at) COMMENT '24-hour retention sweep',
  CONSTRAINT fk_nonce_device FOREIGN KEY (device_row_id) REFERENCES devices(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Part 17.3: a repeated request_id returns the stored response verbatim, which
-- is what makes offline-queue retries after a network timeout safe.
CREATE TABLE IF NOT EXISTS processed_requests (
  processed_id  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id    CHAR(36)     NOT NULL,
  device_row_id INT UNSIGNED NULL,
  endpoint      VARCHAR(120) NOT NULL,
  response_code INT UNSIGNED NOT NULL,
  response_body JSON         NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_request_id (request_id),
  KEY idx_processed_created (created_at),
  CONSTRAINT fk_processed_device FOREIGN KEY (device_row_id) REFERENCES devices(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic token-bucket state for rate limiting (devices, logins, API users).
CREATE TABLE IF NOT EXISTS rate_limits (
  bucket_key   VARCHAR(190) NOT NULL PRIMARY KEY,
  hits         INT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME     NOT NULL,
  blocked_until DATETIME    NULL,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ratelimit_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
