-- ===========================================================================
-- L-SIAMS · 006 · Audit, security, realtime, notifications, settings, backups
-- ===========================================================================
-- audit_logs, security_logs and login_history are append-only by policy. There
-- is no UPDATE or DELETE path to them anywhere in the application, and the
-- application database user is granted only INSERT and SELECT on them
-- (see docs/DEPLOYMENT.md, "Database privileges").
-- ===========================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
  audit_id     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NULL,
  actor_label  VARCHAR(120) NULL COMMENT 'Snapshot of who acted, valid even if the user row changes',
  role_name    VARCHAR(50)  NULL,
  action       VARCHAR(80)  NOT NULL COMMENT 'e.g. STUDENT_SECTION_TRANSFER',
  module       VARCHAR(50)  NOT NULL,
  record_type  VARCHAR(60)  NULL,
  record_id    VARCHAR(60)  NULL,
  old_value    JSON         NULL,
  new_value    JSON         NULL,
  description  VARCHAR(500) NULL,
  ip_address   VARCHAR(45)  NULL,
  user_agent   VARCHAR(500) NULL,
  device_label VARCHAR(120) NULL,
  session_id   BIGINT UNSIGNED NULL,
  result       ENUM('success','failure') NOT NULL DEFAULT 'success',
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_user (user_id, created_at),
  KEY idx_audit_action (action, created_at),
  KEY idx_audit_module (module, created_at),
  KEY idx_audit_record (record_type, record_id),
  KEY idx_audit_created (created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS security_logs (
  security_id  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event        VARCHAR(60)  NOT NULL COMMENT 'REPLAY_DETECTED, SIGNATURE_INVALID, …',
  severity     ENUM('low','medium','high','critical') NOT NULL DEFAULT 'low',
  description  VARCHAR(500) NOT NULL,
  context_json JSON         NULL,
  source_ip    VARCHAR(45)  NULL,
  user_id      INT UNSIGNED NULL,
  device_row_id INT UNSIGNED NULL,
  device_id_text VARCHAR(32) NULL,
  endpoint     VARCHAR(190) NULL,
  http_method  VARCHAR(10)  NULL,
  user_agent   VARCHAR(500) NULL,
  resolution_status ENUM('open','investigating','resolved','false_positive') NOT NULL DEFAULT 'open',
  resolved_by  INT UNSIGNED NULL,
  resolved_at  DATETIME     NULL,
  admin_notes  VARCHAR(1000) NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_seclog_event (event, created_at),
  KEY idx_seclog_severity (severity, created_at),
  KEY idx_seclog_ip (source_ip, created_at),
  KEY idx_seclog_status (resolution_status, created_at),
  KEY idx_seclog_created (created_at),
  CONSTRAINT fk_seclog_user   FOREIGN KEY (user_id)       REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_seclog_device FOREIGN KEY (device_row_id) REFERENCES devices(id)    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_seclog_admin  FOREIGN KEY (resolved_by)   REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blocked_ips (
  block_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_address  VARCHAR(45)  NOT NULL,
  reason      VARCHAR(255) NOT NULL,
  blocked_by  INT UNSIGNED NULL,
  auto_blocked TINYINT(1)  NOT NULL DEFAULT 0,
  expires_at  DATETIME     NULL COMMENT 'NULL = permanent',
  status      ENUM('active','lifted') NOT NULL DEFAULT 'active',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_blocked_ip (ip_address),
  KEY idx_blocked_status (status, expires_at),
  CONSTRAINT fk_blockip_user FOREIGN KEY (blocked_by) REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blocked_devices (
  block_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_row_id INT UNSIGNED NULL,
  device_id_text VARCHAR(32) NULL,
  mac_address   VARCHAR(17)  NULL,
  reason        VARCHAR(255) NOT NULL,
  blocked_by    INT UNSIGNED NULL,
  auto_blocked  TINYINT(1)   NOT NULL DEFAULT 0,
  status        ENUM('active','lifted') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_blockdev_device (device_row_id, status),
  KEY idx_blockdev_text (device_id_text, status),
  CONSTRAINT fk_blockdev_device FOREIGN KEY (device_row_id) REFERENCES devices(id)    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_blockdev_user   FOREIGN KEY (blocked_by)    REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------- realtime transport ----
-- Part 17.7: the replay buffer. `sequence` is a monotonically increasing
-- per-channel counter, so a client that reconnects can ask for everything after
-- the last sequence it saw and lose nothing.
CREATE TABLE IF NOT EXISTS realtime_events (
  event_id    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  channel     VARCHAR(80)  NOT NULL,
  sequence    BIGINT UNSIGNED NOT NULL,
  event_type  VARCHAR(50)  NOT NULL,
  payload     JSON         NOT NULL,
  created_at  DATETIME(3)  NOT NULL,
  UNIQUE KEY uq_channel_sequence (channel, sequence),
  KEY idx_rt_channel_created (channel, created_at),
  KEY idx_rt_created (created_at) COMMENT '48-hour retention sweep',
  KEY idx_rt_id (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS realtime_channel_cursors (
  channel       VARCHAR(80) NOT NULL PRIMARY KEY,
  last_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS realtime_tickets (
  ticket_id   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_hash CHAR(64)    NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  session_id  BIGINT UNSIGNED NOT NULL,
  channels    JSON        NOT NULL,
  expires_at  DATETIME    NOT NULL,
  consumed_at DATETIME    NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ticket_hash (ticket_hash),
  KEY idx_ticket_expiry (expires_at),
  CONSTRAINT fk_ticket_user    FOREIGN KEY (user_id)    REFERENCES users(user_id)              ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_ticket_session FOREIGN KEY (session_id) REFERENCES user_sessions(session_id)   ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------- notifications ----
CREATE TABLE IF NOT EXISTS notifications (
  notification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NULL COMMENT 'NULL = broadcast to every administrator',
  role_target  VARCHAR(50)  NULL,
  category     VARCHAR(50)  NOT NULL,
  title        VARCHAR(150) NOT NULL,
  message      VARCHAR(1000) NOT NULL,
  link         VARCHAR(255) NULL,
  priority     ENUM('low','normal','high','critical') NOT NULL DEFAULT 'normal',
  is_read      TINYINT(1)   NOT NULL DEFAULT 0,
  read_at      DATETIME     NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notif_user (user_id, is_read, created_at),
  KEY idx_notif_role (role_target, is_read, created_at),
  KEY idx_notif_created (created_at) COMMENT '180-day retention sweep',
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------- settings ----
CREATE TABLE IF NOT EXISTS settings (
  setting_key   VARCHAR(100) NOT NULL PRIMARY KEY,
  setting_value TEXT         NULL,
  value_type    ENUM('string','int','float','bool','json') NOT NULL DEFAULT 'string',
  group_name    VARCHAR(50)  NOT NULL DEFAULT 'general',
  label         VARCHAR(150) NOT NULL,
  description   VARCHAR(500) NULL,
  -- Sensitive settings require administrator re-authentication before they can
  -- be changed (Part 2 business rules).
  is_sensitive  TINYINT(1)   NOT NULL DEFAULT 0,
  min_value     VARCHAR(50)  NULL,
  max_value     VARCHAR(50)  NULL,
  updated_by    INT UNSIGNED NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_settings_group (group_name),
  CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------- reports and backups ----
CREATE TABLE IF NOT EXISTS generated_reports (
  report_id     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_name   VARCHAR(190) NOT NULL,
  report_type   VARCHAR(60)  NOT NULL,
  format        ENUM('pdf','xlsx','csv') NOT NULL,
  parameters    JSON         NULL,
  file_path     VARCHAR(255) NOT NULL COMMENT 'Under storage/reports — outside the web root',
  file_size     INT UNSIGNED NOT NULL DEFAULT 0,
  checksum      CHAR(64)     NULL,
  row_count     INT UNSIGNED NOT NULL DEFAULT 0,
  generated_by  INT UNSIGNED NOT NULL,
  expires_at    DATETIME     NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_report_user (generated_by, created_at),
  KEY idx_report_type (report_type, created_at),
  CONSTRAINT fk_report_user FOREIGN KEY (generated_by) REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backups (
  backup_id    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  backup_name  VARCHAR(190) NOT NULL,
  file_path    VARCHAR(255) NOT NULL,
  file_size    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  checksum     CHAR(64)     NULL COMMENT 'SHA-256 for integrity verification',
  is_encrypted TINYINT(1)   NOT NULL DEFAULT 1,
  is_compressed TINYINT(1)  NOT NULL DEFAULT 1,
  trigger_type ENUM('manual','scheduled') NOT NULL DEFAULT 'manual',
  table_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status       ENUM('running','completed','failed','verified','restored') NOT NULL DEFAULT 'running',
  error_message VARCHAR(500) NULL,
  duration_seconds INT UNSIGNED NULL,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verified_at  DATETIME     NULL,
  restored_at  DATETIME     NULL,
  restored_by  INT UNSIGNED NULL,
  KEY idx_backup_status (status, created_at),
  CONSTRAINT fk_backup_creator  FOREIGN KEY (created_by)  REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_backup_restorer FOREIGN KEY (restored_by) REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration bookkeeping.
CREATE TABLE IF NOT EXISTS schema_migrations (
  migration  VARCHAR(190) NOT NULL PRIMARY KEY,
  batch      INT UNSIGNED NOT NULL,
  checksum   CHAR(64)     NOT NULL,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
