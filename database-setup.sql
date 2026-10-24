-- Khushrang complete MySQL installer. Import manually in phpMyAdmin into a NEW EMPTY database.
-- No migration runner or CLI is required. Reruns never reset existing user passwords.
-- The initial Super Admin password is supplied separately in INITIAL-LOGIN.txt.
CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 email VARCHAR(190) NOT NULL UNIQUE,
 name VARCHAR(190) NOT NULL,
 password_hash VARCHAR(255) NOT NULL,
 role VARCHAR(24) NOT NULL DEFAULT 'viewer',
 active BOOLEAN NOT NULL DEFAULT TRUE,
 must_change_password BOOLEAN NOT NULL DEFAULT TRUE,
 session_version INT UNSIGNED NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS records (
 id VARCHAR(80) PRIMARY KEY,
 kind VARCHAR(24) NOT NULL,
 payload JSON NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX kind_created(kind,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS login_attempts (
 ip_hash CHAR(64) PRIMARY KEY,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 last_attempt DATETIME NOT NULL,
 INDEX attempt_time(last_attempt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Integration tables (included for fresh installations).
-- Integration tables are part of this complete installer.
CREATE TABLE IF NOT EXISTS integration_inbox (
 source VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 event_id VARCHAR(128) COLLATE utf8mb4_bin NOT NULL,
 body_hash CHAR(64) NOT NULL,
 event_type VARCHAR(40) NOT NULL,
 client_id VARCHAR(80) NOT NULL,
 received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(source,event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS outreach_updates (
 id CHAR(32) PRIMARY KEY,
 source VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 client_id VARCHAR(80) NOT NULL,
 recipient_email VARCHAR(254) NOT NULL,
 message_id VARCHAR(190) NOT NULL,
 status VARCHAR(32) NOT NULL,
 occurred_at DATETIME NOT NULL,
 received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX client_time(client_id,occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS client_suppressions (
 email VARCHAR(254) PRIMARY KEY,
 reason VARCHAR(32) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS integration_outbox (
 id CHAR(32) PRIMARY KEY,
 client_id VARCHAR(80) NOT NULL,
 idempotency_key VARCHAR(128) COLLATE utf8mb4_bin NOT NULL UNIQUE,
 destination VARCHAR(32) NOT NULL DEFAULT 'email',
 purpose VARCHAR(20) NOT NULL,
 payload JSON NOT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'pending',
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 last_http_code INT NULL,
 last_error VARCHAR(190) NULL,
 next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 locked_at DATETIME NULL,
 lock_token CHAR(32) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX ready(status,next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS integration_rate_limits (
 source VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
 minute_bucket BIGINT NOT NULL,
 requests INT UNSIGNED NOT NULL DEFAULT 1,
 PRIMARY KEY(source,minute_bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Initial owner: never overwrite an existing account or reset a changed password.
INSERT INTO users(email,name,password_hash,role,active,must_change_password)
SELECT 'admin@khushrangevents.com','Khushrang Super Admin','$2y$12$Q5qLHLe7R3qDL0CJkBQU4exyq4a2hjcMoXouo0kKpy2e1AWtr/5US','super_admin',1,1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email='admin@khushrangevents.com')
  AND NOT EXISTS (SELECT 1 FROM users WHERE role='super_admin' AND active=1);
