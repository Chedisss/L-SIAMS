-- ===========================================================================
-- L-SIAMS · 001 · Authentication, authorisation and session tables
-- ===========================================================================
-- Everything here is InnoDB with explicit foreign keys. Historical tables
-- (login_history, audit_logs, security_logs) use ON DELETE RESTRICT so a user
-- row can never be removed in a way that would orphan or erase its history.
-- ===========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------- roles ----
CREATE TABLE IF NOT EXISTS roles (
  role_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_name    VARCHAR(50)  NOT NULL,
  role_slug    VARCHAR(50)  NOT NULL,
  description  VARCHAR(255) NULL,
  is_system    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'System roles cannot be deleted',
  status       ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_role_slug (role_slug),
  UNIQUE KEY uq_role_name (role_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- permissions ----
CREATE TABLE IF NOT EXISTS permissions (
  permission_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  permission_key VARCHAR(80) NOT NULL COMMENT 'e.g. students.create',
  module        VARCHAR(50)  NOT NULL,
  description   VARCHAR(255) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_permission_key (permission_key),
  KEY idx_permission_module (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id       INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (role_id, permission_id),
  KEY idx_rp_permission (permission_id),
  CONSTRAINT fk_rp_role       FOREIGN KEY (role_id)       REFERENCES roles(role_id)             ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions(permission_id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- users ----
CREATE TABLE IF NOT EXISTS users (
  user_id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id             INT UNSIGNED NOT NULL,
  username            VARCHAR(32)  NOT NULL,
  email               VARCHAR(190) NOT NULL,
  password_hash       VARCHAR(255) NOT NULL COMMENT 'bcrypt/argon2id — never plaintext',
  full_name           VARCHAR(150) NOT NULL,
  status              ENUM('active','inactive','locked','archived') NOT NULL DEFAULT 'active',
  must_change_password TINYINT(1)  NOT NULL DEFAULT 1,
  password_changed_at DATETIME     NULL,
  failed_login_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until        DATETIME     NULL,
  last_login_at       DATETIME     NULL,
  last_login_ip       VARCHAR(45)  NULL,
  photo_path          VARCHAR(255) NULL,
  two_factor_enabled  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Reserved for 2FA (Part 6 future extensions)',
  two_factor_secret   VARBINARY(255) NULL,
  created_by          INT UNSIGNED NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at          TIMESTAMP NULL,
  -- Uniqueness includes archived rows on purpose: a recycled username would
  -- make historical audit entries ambiguous.
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role_id),
  KEY idx_users_status (status),
  KEY idx_users_deleted (deleted_at),
  CONSTRAINT fk_users_role    FOREIGN KEY (role_id)    REFERENCES roles(role_id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_users_creator FOREIGN KEY (created_by) REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password reuse prevention (security.password.history_depth).
CREATE TABLE IF NOT EXISTS password_history (
  history_id    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pwhist_user (user_id, created_at),
  CONSTRAINT fk_pwhist_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  reset_id    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  token_hash  CHAR(64)     NOT NULL COMMENT 'SHA-256 of the emitted token; the token itself is never stored',
  expires_at  DATETIME     NOT NULL,
  used_at     DATETIME     NULL,
  requested_ip VARCHAR(45) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_reset_token (token_hash),
  KEY idx_reset_user (user_id, expires_at),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------- user sessions ----
-- Part 18: the server, not the browser, decides when a session dies.
CREATE TABLE IF NOT EXISTS user_sessions (
  session_id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id             INT UNSIGNED NOT NULL,
  token_hash          CHAR(64)     NOT NULL COMMENT 'SHA-256 of a 256-bit CSPRNG token',
  ip_address          VARCHAR(45)  NOT NULL,
  ip_prefix           VARCHAR(45)  NULL COMMENT '/24 (v4) or /64 (v6) binding',
  user_agent          VARCHAR(500) NULL,
  user_agent_hash     CHAR(64)     NULL,
  device_label        VARCHAR(120) NULL,
  browser_label       VARCHAR(120) NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_activity_at    DATETIME     NOT NULL,
  idle_expires_at     DATETIME     NOT NULL,
  absolute_expires_at DATETIME     NOT NULL,
  terminated_at       DATETIME     NULL,
  termination_reason  ENUM('logout','idle_timeout','absolute_timeout','admin_terminated',
                           'password_changed','concurrent_limit','hijack_suspected','device_replaced') NULL,
  UNIQUE KEY uq_session_token (token_hash),
  KEY idx_session_user (user_id, terminated_at),
  KEY idx_session_idle (idle_expires_at),
  KEY idx_session_active (terminated_at, idle_expires_at),
  CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------- login history ----
-- Permanent record. No delete path exists anywhere in the application.
CREATE TABLE IF NOT EXISTS login_history (
  login_id    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL COMMENT 'NULL when the username did not resolve',
  username_try VARCHAR(190) NULL,
  ip_address  VARCHAR(45)  NOT NULL,
  user_agent  VARCHAR(500) NULL,
  browser     VARCHAR(120) NULL,
  platform    VARCHAR(120) NULL,
  status      ENUM('success','failed','locked','logout','idle_timeout','absolute_timeout',
                   'admin_terminated','blocked_ip','rate_limited') NOT NULL,
  detail      VARCHAR(255) NULL,
  session_duration_sec INT UNSIGNED NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_login_user (user_id, created_at),
  KEY idx_login_status (status, created_at),
  KEY idx_login_ip (ip_address, created_at),
  CONSTRAINT fk_login_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login throttling state, keyed by username+IP so one attacker cannot lock out
-- an entire school by hammering a single known username from many hosts.
CREATE TABLE IF NOT EXISTS login_attempts (
  attempt_id  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  identifier  VARCHAR(190) NOT NULL,
  ip_address  VARCHAR(45)  NOT NULL,
  attempted_at DATETIME    NOT NULL,
  KEY idx_attempt_lookup (identifier, ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
