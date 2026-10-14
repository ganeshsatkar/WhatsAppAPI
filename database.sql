-- ============================================================
-- WP API (WhatsFlow Pro) - Complete Database Schema — FRESH INSTALL
-- Version: 2.0.0
-- Existing installation? Do NOT import this file — upload the code and
-- run migrate.php instead (it brings any older database to this schema).
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET NAMES utf8mb4;

-- ============================================================
-- PLANS & SUBSCRIPTIONS
-- ============================================================

CREATE TABLE `plans` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(50) NOT NULL UNIQUE,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `billing_cycle` ENUM('monthly','yearly','lifetime') DEFAULT 'monthly',
  `duration_days` INT NOT NULL DEFAULT 30 COMMENT 'how long one purchase/activation lasts',
  `is_public` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = users can buy it themselves from wallet; 0 = only super-admin can activate (trial)',
  `max_contacts` INT DEFAULT 500 COMMENT '-1 = unlimited',
  `max_messages` INT DEFAULT 1000 COMMENT '-1 = unlimited',
  `max_team_members` INT DEFAULT 1,
  `chatbot_builder` TINYINT(1) DEFAULT 0,
  `automation_workflows` TINYINT(1) DEFAULT 0,
  `advanced_analytics` TINYINT(1) DEFAULT 0,
  `api_access` TINYINT(1) DEFAULT 0,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Three plans: Trial (Rs 0, super-admin activates for N days, everything included), Basic Rs 599, Platform Rs 1199 (everything).
INSERT INTO `plans` (`name`,`slug`,`price`,`duration_days`,`is_public`,`max_contacts`,`max_messages`,`max_team_members`,`chatbot_builder`,`automation_workflows`,`advanced_analytics`,`api_access`) VALUES
('Trial','trial',0.00,7,0,-1,-1,-1,1,1,1,1),
('Basic','basic',599.00,30,1,5000,-1,2,1,0,0,0),
('Platform','platform',1199.00,30,1,-1,-1,-1,1,1,1,1);

-- ============================================================
-- USERS
-- ============================================================

CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(191) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('superadmin','user','team_member') DEFAULT 'user',
  `plan_id` INT DEFAULT 1,
  `is_active` TINYINT(1) DEFAULT 1,
  `is_verified` TINYINT(1) DEFAULT 0,
  `verify_token` VARCHAR(100) DEFAULT NULL,
  `reset_token` VARCHAR(100) DEFAULT NULL,
  `reset_expires` DATETIME DEFAULT NULL,
  `api_token` VARCHAR(64) DEFAULT NULL UNIQUE,
  `last_login` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`plan_id`) REFERENCES `plans`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `user_profiles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL UNIQUE,
  `company_name` VARCHAR(200) DEFAULT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `country` VARCHAR(100) DEFAULT NULL,
  `timezone` VARCHAR(100) DEFAULT 'UTC',
  `logo_url` VARCHAR(500) DEFAULT NULL,
  `website` VARCHAR(300) DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- SUBSCRIPTIONS & PAYMENTS
-- ============================================================

CREATE TABLE `subscriptions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `plan_id` INT NOT NULL,
  `status` ENUM('active','expired','cancelled','trial') DEFAULT 'active',
  `started_at` DATETIME NOT NULL,
  `expires_at` DATETIME DEFAULT NULL,
  `messages_used` INT DEFAULT 0,
  `contacts_used` INT DEFAULT 0,
  `reminded_at` DATETIME DEFAULT NULL COMMENT 'expiry reminder already sent',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_sub_user_status` (`user_id`,`status`,`expires_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`plan_id`) REFERENCES `plans`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `plan_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `currency` VARCHAR(10) DEFAULT 'USD',
  `gateway` VARCHAR(50) DEFAULT NULL,
  `transaction_id` VARCHAR(200) DEFAULT NULL,
  `status` ENUM('pending','success','failed','refunded') DEFAULT 'pending',
  `payment_data` JSON DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`plan_id`) REFERENCES `plans`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `invoices` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `payment_id` INT DEFAULT NULL,
  `invoice_number` VARCHAR(50) NOT NULL UNIQUE,
  `amount` DECIMAL(10,2) NOT NULL,
  `tax` DECIMAL(10,2) DEFAULT 0.00,
  `status` ENUM('draft','sent','paid','void') DEFAULT 'draft',
  `due_date` DATE DEFAULT NULL,
  `pdf_url` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- WHATSAPP SETTINGS
-- ============================================================

CREATE TABLE `user_whatsapp_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL UNIQUE,
  `phone_number_id` VARCHAR(100) DEFAULT NULL,
  `business_account_id` VARCHAR(100) DEFAULT NULL,
  `access_token` TEXT DEFAULT NULL COMMENT 'AES-256-GCM encrypted (enc:v1:)',
  `verify_token` VARCHAR(100) DEFAULT NULL,
  `phone_number` VARCHAR(30) DEFAULT NULL,
  `display_name` VARCHAR(200) DEFAULT NULL,
  `is_connected` TINYINT(1) DEFAULT 0,
  `webhook_url` VARCHAR(500) DEFAULT NULL,
  `last_verified` DATETIME DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_phone_number_id` (`phone_number_id`),
  KEY `idx_waba` (`business_account_id`),
  KEY `idx_verify_token` (`verify_token`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- TEAM MEMBERS
-- ============================================================

CREATE TABLE `team_members` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL COMMENT 'Owner account',
  `member_user_id` INT NOT NULL COMMENT 'The actual user',
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `role` ENUM('admin','agent','viewer') DEFAULT 'agent',
  `is_active` TINYINT(1) DEFAULT 1,
  `invite_token` VARCHAR(100) DEFAULT NULL,
  `invited_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `joined_at` DATETIME DEFAULT NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`member_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- CONTACTS CRM
-- ============================================================

CREATE TABLE `contacts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `phone` VARCHAR(30) NOT NULL,
  `email` VARCHAR(191) DEFAULT NULL,
  `country_code` VARCHAR(10) DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `source` ENUM('manual','csv','qr','api','webhook','whatsapp') DEFAULT 'manual',
  `opt_in` TINYINT(1) DEFAULT 1,
  `is_blocked` TINYINT(1) DEFAULT 0,
  `last_message_at` DATETIME DEFAULT NULL,
  `custom_fields` JSON DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_user_phone` (`user_id`,`phone`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `contact_tags` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `contact_id` INT NOT NULL,
  `tag` VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_contact_tag` (`contact_id`,`tag`),
  KEY `idx_user_tag` (`user_id`,`tag`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `contact_lists` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `name` VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `color` VARCHAR(20) DEFAULT '#25d366',
  `contact_count` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `contact_list_members` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `list_id` INT NOT NULL,
  `contact_id` INT NOT NULL,
  `added_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_list_contact` (`list_id`,`contact_id`),
  FOREIGN KEY (`list_id`) REFERENCES `contact_lists`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- MESSAGE TEMPLATES
-- ============================================================

CREATE TABLE `message_templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `template_name` VARCHAR(200) NOT NULL,
  `language` VARCHAR(10) DEFAULT 'en',
  `category` ENUM('MARKETING','UTILITY','AUTHENTICATION','SERVICE') DEFAULT 'MARKETING',
  `header_type` ENUM('none','text','image','video','document') DEFAULT 'none',
  `header_content` TEXT DEFAULT NULL,
  `body` TEXT NOT NULL,
  `footer` VARCHAR(255) DEFAULT NULL,
  `buttons` JSON DEFAULT NULL,
  `variables` JSON DEFAULT NULL,
  `status` ENUM('pending','approved','rejected','paused') DEFAULT 'pending',
  `meta_template_id` VARCHAR(100) DEFAULT NULL,
  `rejection_reason` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- CAMPAIGNS
-- ============================================================

CREATE TABLE `campaigns` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `name` VARCHAR(200) NOT NULL,
  `template_id` INT DEFAULT NULL,
  `contact_list_id` INT DEFAULT NULL,
  `status` ENUM('draft','scheduled','running','completed','paused','failed') DEFAULT 'draft',
  `scheduled_at` DATETIME DEFAULT NULL,
  `started_at` DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `total_contacts` INT DEFAULT 0,
  `sent_count` INT DEFAULT 0,
  `delivered_count` INT DEFAULT 0,
  `read_count` INT DEFAULT 0,
  `failed_count` INT DEFAULT 0,
  `reply_count` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`template_id`) REFERENCES `message_templates`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `campaign_logs` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `campaign_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `contact_id` INT NOT NULL,
  `phone` VARCHAR(30) NOT NULL,
  `status` ENUM('pending','sent','delivered','read','failed','replied') DEFAULT 'pending',
  `message_id` VARCHAR(100) DEFAULT NULL COMMENT 'WhatsApp message ID',
  `error_message` TEXT DEFAULT NULL,
  `error_code` INT DEFAULT NULL COMMENT 'Meta error code (131026, 131049 ...)',
  `wallet_tx_id` INT DEFAULT NULL COMMENT 'wallet debit for this message – lets us refund it exactly once',
  `refunded` TINYINT(1) NOT NULL DEFAULT 0,
  `attempts` INT NOT NULL DEFAULT 1,
  `failed_at` DATETIME DEFAULT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  `delivered_at` DATETIME DEFAULT NULL,
  `read_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_campaign_contact` (`campaign_id`,`contact_id`),
  INDEX `idx_cl_msgid` (`message_id`),
  INDEX `idx_cl_camp_status` (`campaign_id`,`status`),
  INDEX `idx_user_sent` (`user_id`,`sent_at`),
  FOREIGN KEY (`campaign_id`) REFERENCES `campaigns`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- INBOX / CONVERSATIONS
-- ============================================================

CREATE TABLE `conversations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `contact_id` INT NOT NULL,
  `phone` VARCHAR(30) NOT NULL,
  `status` ENUM('open','resolved','pending','snoozed') DEFAULT 'open',
  `assigned_to` INT DEFAULT NULL,
  `priority` ENUM('low','normal','high','urgent') DEFAULT 'normal',
  `last_message_at` DATETIME DEFAULT NULL,
  `last_message_preview` VARCHAR(500) DEFAULT NULL,
  `last_inbound_at` DATETIME DEFAULT NULL COMMENT 'Customer last wrote; free-text replies allowed for 24h after',
  `unread_count` INT DEFAULT 0,
  `is_bot_active` TINYINT(1) DEFAULT 1,
  `last_away_at` DATETIME DEFAULT NULL COMMENT 'when the away message was last auto-sent here',
  `welcome_sent_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_user_contact` (`user_id`,`contact_id`),
  KEY `idx_user_last` (`user_id`,`last_message_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`contact_id`) REFERENCES `contacts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `messages` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `conversation_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `direction` ENUM('inbound','outbound') NOT NULL,
  `message_type` ENUM('text','image','video','audio','document','template','interactive','location','sticker','reaction','contacts','call','system','unsupported') DEFAULT 'text',
  `content` TEXT DEFAULT NULL,
  `media_url` VARCHAR(500) DEFAULT NULL,
  `media_mime_type` VARCHAR(100) DEFAULT NULL,
  `media_id` VARCHAR(255) DEFAULT NULL COMMENT 'WhatsApp media id; media.php resolves a fresh download URL (Meta URLs expire in minutes)',
  `reply_to_wamid` VARCHAR(100) DEFAULT NULL COMMENT 'WhatsApp id of the message this one quotes',
  `reaction` VARCHAR(20) DEFAULT NULL COMMENT 'Emoji the customer reacted with',
  `error_message` VARCHAR(500) DEFAULT NULL COMMENT 'Why WhatsApp failed to deliver',
  `template_id` INT DEFAULT NULL,
  `whatsapp_message_id` VARCHAR(100) DEFAULT NULL,
  `status` ENUM('sent','delivered','read','failed') DEFAULT 'sent',
  `is_note` TINYINT(1) DEFAULT 0 COMMENT 'Internal team note',
  `sent_by` INT DEFAULT NULL COMMENT 'user_id of sender',
  `delivered_at` DATETIME DEFAULT NULL,
  `read_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_wamid` (`whatsapp_message_id`),
  KEY `idx_conv_id` (`conversation_id`,`id`),
  FOREIGN KEY (`conversation_id`) REFERENCES `conversations`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `conversation_tags` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `conversation_id` INT NOT NULL,
  `tag` VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_conv_tag` (`conversation_id`,`tag`),
  FOREIGN KEY (`conversation_id`) REFERENCES `conversations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `chat_assignments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `conversation_id` INT NOT NULL,
  `assigned_to` INT NOT NULL,
  `assigned_by` INT NOT NULL,
  `note` TEXT DEFAULT NULL,
  `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`conversation_id`) REFERENCES `conversations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- CHATBOT FLOWS
-- ============================================================

CREATE TABLE `bot_flows` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `name` VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `trigger_keyword` VARCHAR(200) DEFAULT NULL,
  `trigger_type` ENUM('keyword','any','welcome','button') DEFAULT 'keyword',
  `is_active` TINYINT(1) DEFAULT 0,
  `flow_data` LONGTEXT DEFAULT NULL COMMENT 'Full JSON flow for visual builder',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `bot_nodes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `flow_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `node_id` VARCHAR(50) NOT NULL COMMENT 'Frontend UUID',
  `node_type` ENUM('trigger','message','condition','delay','action','input','end') NOT NULL,
  `label` VARCHAR(200) DEFAULT NULL,
  `config` JSON DEFAULT NULL COMMENT 'Node-specific configuration',
  `position_x` INT DEFAULT 0,
  `position_y` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`flow_id`) REFERENCES `bot_flows`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `bot_connections` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `flow_id` INT NOT NULL,
  `source_node_id` VARCHAR(50) NOT NULL,
  `target_node_id` VARCHAR(50) NOT NULL,
  `condition_value` VARCHAR(200) DEFAULT NULL COMMENT 'Button value or condition',
  FOREIGN KEY (`flow_id`) REFERENCES `bot_flows`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `bot_sessions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `contact_id` INT NOT NULL,
  `flow_id` INT NOT NULL,
  `current_node_id` VARCHAR(50) DEFAULT NULL,
  `session_data` JSON DEFAULT NULL,
  `status` ENUM('active','completed','expired') DEFAULT 'active',
  `started_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`flow_id`) REFERENCES `bot_flows`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- AUTOMATION RULES
-- ============================================================

CREATE TABLE `automation_rules` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `name` VARCHAR(200) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `trigger_event` ENUM('new_contact','contact_tagged','message_received','campaign_sent','manual','scheduled') DEFAULT 'new_contact',
  `trigger_conditions` JSON DEFAULT NULL,
  `actions` JSON NOT NULL COMMENT 'Array of action objects',
  `delay_minutes` INT DEFAULT 0,
  `is_active` TINYINT(1) DEFAULT 1,
  `run_once` TINYINT(1) DEFAULT 0,
  `run_count` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `automation_logs` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `rule_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `contact_id` INT DEFAULT NULL,
  `status` ENUM('pending','running','completed','failed','skipped') DEFAULT 'pending',
  `trigger_data` JSON DEFAULT NULL,
  `result` TEXT DEFAULT NULL,
  `executed_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`rule_id`) REFERENCES `automation_rules`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- QR CODES & SALES
-- ============================================================

CREATE TABLE `salespersons` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(191) DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `referral_code` VARCHAR(20) NOT NULL UNIQUE,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `qr_codes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `salesperson_id` INT DEFAULT NULL,
  `name` VARCHAR(200) NOT NULL,
  `wa_number` VARCHAR(30) NOT NULL,
  `pre_filled_message` VARCHAR(500) DEFAULT 'Hi',
  `qr_token` VARCHAR(50) NOT NULL UNIQUE,
  `short_url` VARCHAR(300) DEFAULT NULL,
  `scan_count` INT DEFAULT 0,
  `lead_count` INT DEFAULT 0,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`salesperson_id`) REFERENCES `salespersons`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `qr_scans` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `qr_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `ip_address` VARCHAR(50) DEFAULT NULL,
  `user_agent` VARCHAR(500) DEFAULT NULL,
  `device_type` ENUM('mobile','tablet','desktop','unknown') DEFAULT 'unknown',
  `country` VARCHAR(100) DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `converted` TINYINT(1) DEFAULT 0,
  `scanned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`qr_id`) REFERENCES `qr_codes`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `sales_leads` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `salesperson_id` INT DEFAULT NULL,
  `qr_id` INT DEFAULT NULL,
  `contact_id` INT DEFAULT NULL,
  `name` VARCHAR(150) DEFAULT NULL,
  `phone` VARCHAR(30) NOT NULL,
  `email` VARCHAR(191) DEFAULT NULL,
  `status` ENUM('new','contacted','qualified','converted','lost') DEFAULT 'new',
  `value` DECIMAL(10,2) DEFAULT 0.00,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`salesperson_id`) REFERENCES `salespersons`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`qr_id`) REFERENCES `qr_codes`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- ACTIVITY LOG / AUDIT
-- ============================================================

CREATE TABLE `activity_logs` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(50) DEFAULT NULL,
  `user_agent` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- NOTIFICATIONS
-- ============================================================

CREATE TABLE `notifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `body` TEXT DEFAULT NULL,
  `type` ENUM('info','success','warning','error') DEFAULT 'info',
  `link` VARCHAR(500) DEFAULT NULL,
  `is_read` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- SUPER ADMIN: INSERT DEFAULT ADMIN
-- ============================================================

INSERT INTO `users` (`name`,`email`,`password`,`role`,`plan_id`,`is_active`,`is_verified`) VALUES
('Super Admin','admin@wpapi.grdpinfotech.com','$2y$12$placeholder_replace_on_first_login','superadmin',3,1,1);

-- ============================================================
-- WALLET & MESSAGE PRICING
-- Required by includes/wallet.php, user/admin_wallet.php, user/campaigns.php
-- ============================================================

CREATE TABLE `wallets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL UNIQUE,
  `balance` DECIMAL(12,4) NOT NULL DEFAULT 0,
  `total_credited` DECIMAL(12,4) NOT NULL DEFAULT 0,
  `total_debited` DECIMAL(12,4) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `wallet_transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `type` ENUM('credit','debit') NOT NULL,
  `amount` DECIMAL(12,4) NOT NULL,
  `balance_after` DECIMAL(12,4) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `ref_type` VARCHAR(50) DEFAULT NULL,
  `ref_id` INT DEFAULT NULL,
  `meta_cost` DECIMAL(12,4) DEFAULT NULL COMMENT 'Meta bill for this debit (for margin reporting)',
  `category` VARCHAR(30) DEFAULT NULL,
  `msg_count` INT DEFAULT NULL,
  `pay_mode` VARCHAR(20) DEFAULT NULL COMMENT 'upi/bank/cash/cheque/other for admin credits',
  `pay_reference` VARCHAR(100) DEFAULT NULL COMMENT 'UTR / receipt no.',
  `created_by` INT DEFAULT NULL COMMENT 'admin who made the entry',
  `razorpay_order_id` VARCHAR(100) DEFAULT NULL,
  `razorpay_payment_id` VARCHAR(100) DEFAULT NULL,
  `status` ENUM('pending','success','failed') DEFAULT 'success',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_wt_created` (`created_at`),
  INDEX `idx_wt_user_created` (`user_id`,`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `message_pricing` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `category` VARCHAR(30) NOT NULL UNIQUE,
  `price_per_msg` DECIMAL(8,4) NOT NULL DEFAULT 0.75 COMMENT 'Selling price charged to users = meta_cost * (1 + markup_percent/100)',
  `meta_cost` DECIMAL(8,4) NOT NULL DEFAULT 0 COMMENT 'What Meta actually bills you per message',
  `markup_percent` DECIMAL(6,2) NOT NULL DEFAULT 0 COMMENT 'Your margin on top of Meta cost',
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- PLACEHOLDER Meta rates (India). Verify against Meta's current rate card and set in Admin > Wallet Mgmt.
INSERT INTO `message_pricing` (`category`,`meta_cost`,`markup_percent`,`price_per_msg`) VALUES
('MARKETING',      1.0900, 10, 1.1990),
('UTILITY',        0.1450, 10, 0.1595),
('AUTHENTICATION', 0.1450, 10, 0.1595),
('SERVICE',        0.0000, 10, 0.0000);

CREATE TABLE `user_pricing` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `category` VARCHAR(30) NOT NULL,
  `price_per_msg` DECIMAL(8,4) NOT NULL,
  `updated_by` INT DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `user_category` (`user_id`,`category`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- PUBLIC REST API (third-party software sends messages through us)
-- ============================================================

CREATE TABLE `api_keys` (
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

CREATE TABLE `api_settings` (
  `user_id` INT PRIMARY KEY,
  `callback_url` VARCHAR(500) DEFAULT NULL COMMENT 'We POST delivery updates here',
  `callback_secret` VARCHAR(64) DEFAULT NULL COMMENT 'Signs callbacks (HMAC-SHA256)',
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `api_messages` (
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
CREATE TABLE `api_status_buffer` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `wamid` VARCHAR(100) NOT NULL,
  `status` VARCHAR(20) NOT NULL,
  `ts` DATETIME NOT NULL,
  `errors_json` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_wamid` (`wamid`)
) ENGINE=InnoDB;

-- ============================================================
-- AUTO-REPLIES, OPT-OUT, QUICK REPLIES
-- ============================================================
CREATE TABLE IF NOT EXISTS `auto_reply_settings` (
  `user_id` INT PRIMARY KEY,
  `timezone` VARCHAR(60) NOT NULL DEFAULT 'Asia/Kolkata',
  `hours_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `hours_json` TEXT DEFAULT NULL COMMENT '{"mon":{"on":1,"from":"09:00","to":"18:00"},...}',
  `away_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `away_message` TEXT DEFAULT NULL,
  `away_cooldown_hours` INT NOT NULL DEFAULT 12,
  `welcome_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `welcome_message` TEXT DEFAULT NULL,
  `optout_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `optout_keywords` VARCHAR(500) NOT NULL DEFAULT 'stop,unsubscribe,opt out,optout',
  `optout_message` TEXT DEFAULT NULL,
  `optin_message` TEXT DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `quick_replies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `title` VARCHAR(60) NOT NULL,
  `body` TEXT NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_qr_user` (`user_id`,`sort_order`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- SECURITY: login / sign-up / password-reset throttling
-- ============================================================

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(191) NOT NULL,
  `ip_address` VARCHAR(50) DEFAULT NULL,
  `attempted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_email_time` (`email`,`attempted_at`),
  KEY `idx_ip_time` (`ip_address`,`attempted_at`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version` VARCHAR(50) PRIMARY KEY,
  `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('2.0.0');
