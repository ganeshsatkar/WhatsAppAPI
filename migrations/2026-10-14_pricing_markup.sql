-- Run ONCE on an existing database (phpMyAdmin -> SQL).
-- Adds Meta-cost + markup pricing and the Rs 1999 platform plan. Prices charged to users do NOT change
-- until you edit them in Admin > Wallet Mgmt (meta_cost starts equal to your current price, markup 0).
ALTER TABLE `message_pricing`
  ADD COLUMN `meta_cost` DECIMAL(8,4) NOT NULL DEFAULT 0 AFTER `price_per_msg`,
  ADD COLUMN `markup_percent` DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER `meta_cost`;
UPDATE `message_pricing` SET `meta_cost` = `price_per_msg` WHERE `meta_cost` = 0;
ALTER TABLE `wallet_transactions` ADD COLUMN `meta_cost` DECIMAL(12,4) DEFAULT NULL AFTER `ref_id`;
INSERT IGNORE INTO `plans` (`name`,`slug`,`price`,`max_contacts`,`max_messages`,`max_team_members`,`chatbot_builder`,`automation_workflows`,`advanced_analytics`)
VALUES ('Platform','platform',1999.00,-1,-1,-1,1,1,1);
