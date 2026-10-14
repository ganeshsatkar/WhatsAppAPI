-- Auto opt-out, business hours / away / welcome, DB quick replies, campaign failure tracking & safe retry.
ALTER TABLE campaign_logs
  ADD COLUMN IF NOT EXISTS error_code INT DEFAULT NULL AFTER error_message,
  ADD COLUMN IF NOT EXISTS wallet_tx_id INT DEFAULT NULL AFTER error_code,
  ADD COLUMN IF NOT EXISTS refunded TINYINT(1) NOT NULL DEFAULT 0 AFTER wallet_tx_id,
  ADD COLUMN IF NOT EXISTS attempts INT NOT NULL DEFAULT 1 AFTER refunded,
  ADD COLUMN IF NOT EXISTS failed_at DATETIME DEFAULT NULL AFTER attempts;
ALTER TABLE campaign_logs
  ADD INDEX IF NOT EXISTS idx_cl_msgid (message_id),
  ADD INDEX IF NOT EXISTS idx_cl_camp_status (campaign_id, status);
ALTER TABLE conversations
  ADD COLUMN IF NOT EXISTS last_away_at DATETIME DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS welcome_sent_at DATETIME DEFAULT NULL;

CREATE TABLE IF NOT EXISTS auto_reply_settings (
  user_id INT PRIMARY KEY,
  timezone VARCHAR(60) NOT NULL DEFAULT 'Asia/Kolkata',
  hours_enabled TINYINT(1) NOT NULL DEFAULT 0,
  hours_json TEXT DEFAULT NULL,
  away_enabled TINYINT(1) NOT NULL DEFAULT 0,
  away_message TEXT DEFAULT NULL,
  away_cooldown_hours INT NOT NULL DEFAULT 12,
  welcome_enabled TINYINT(1) NOT NULL DEFAULT 0,
  welcome_message TEXT DEFAULT NULL,
  optout_enabled TINYINT(1) NOT NULL DEFAULT 1,
  optout_keywords VARCHAR(500) NOT NULL DEFAULT 'stop,unsubscribe,opt out,optout',
  optout_message TEXT DEFAULT NULL,
  optin_message TEXT DEFAULT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS quick_replies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(60) NOT NULL,
  body TEXT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_qr_user (user_id, sort_order),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
