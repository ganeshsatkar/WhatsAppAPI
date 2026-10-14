-- Run ONCE on an existing database (phpMyAdmin -> SQL). Adds the public REST API tables.
UPDATE `plans` SET `api_access`=1 WHERE `slug`='platform';

-- ============================================================
-- PUBLIC REST API (third-party software sends messages through us)
-- ============================================================

CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `key_prefix` VARCHAR(20) NOT NULL COMMENT 'First characters, safe to display',
  `key_hash` CHAR(64) NOT NULL UNIQUE COMMENT 'sha256 of the key; the key itself is shown once and never stored',
  `is_active` TINYINT(1) DEFAULT 1,
  `last_used_at` DATETIME DEFAULT NULL,
  `last_used_ip` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `revoked_at` DATETIME DEFAULT NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `api_settings` (
  `user_id` INT PRIMARY KEY,
  `callback_url` VARCHAR(500) DEFAULT NULL COMMENT 'We POST delivery updates here',
  `callback_secret` VARCHAR(64) DEFAULT NULL COMMENT 'Signs callbacks (HMAC-SHA256)',
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `api_messages` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `api_key_id` INT DEFAULT NULL,
  `client_ref` VARCHAR(100) DEFAULT NULL COMMENT 'Caller own id, e.g. bday-123-2026',
  `idempotency_key` VARCHAR(100) DEFAULT NULL,
  `to_phone` VARCHAR(30) NOT NULL,
  `message_type` ENUM('template','text') NOT NULL DEFAULT 'template',
  `template_name` VARCHAR(150) DEFAULT NULL,
  `language` VARCHAR(12) DEFAULT NULL,
  `category` VARCHAR(30) DEFAULT NULL,
  `request_json` TEXT DEFAULT NULL,
  `status` ENUM('queued','sent','delivered','read','failed','rejected') NOT NULL DEFAULT 'queued'
    COMMENT 'rejected = refused by us before WhatsApp (nothing sent, not charged); failed = WhatsApp could not deliver',
  `wamid` VARCHAR(100) DEFAULT NULL,
  `error_code` INT DEFAULT NULL,
  `error_category` VARCHAR(40) DEFAULT NULL,
  `error_message` VARCHAR(500) DEFAULT NULL,
  `cost` DECIMAL(10,4) NOT NULL DEFAULT 0,
  `refunded` TINYINT(1) NOT NULL DEFAULT 0,
  `retry_of` BIGINT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` DATETIME DEFAULT NULL,
  `delivered_at` DATETIME DEFAULT NULL,
  `read_at` DATETIME DEFAULT NULL,
  `failed_at` DATETIME DEFAULT NULL,
  `callback_state` ENUM('none','pending','done','failed') NOT NULL DEFAULT 'none',
  `callback_attempts` TINYINT NOT NULL DEFAULT 0,
  `next_callback_at` DATETIME DEFAULT NULL,
  `last_callback_error` VARCHAR(255) DEFAULT NULL,
  UNIQUE KEY `uq_idem` (`user_id`,`idempotency_key`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_user_status` (`user_id`,`status`),
  KEY `idx_user_phone` (`user_id`,`to_phone`),
  KEY `idx_user_ref` (`user_id`,`client_ref`),
  KEY `idx_wamid` (`wamid`),
  KEY `idx_cb` (`callback_state`,`next_callback_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Status webhooks can arrive a few ms BEFORE our send call returns the message id; park them briefly.
CREATE TABLE IF NOT EXISTS `api_status_buffer` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `wamid` VARCHAR(100) NOT NULL,
  `status` VARCHAR(20) NOT NULL,
  `ts` DATETIME NOT NULL,
  `errors_json` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_wamid` (`wamid`)
) ENGINE=InnoDB;
