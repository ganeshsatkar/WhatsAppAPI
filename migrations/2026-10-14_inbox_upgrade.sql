-- Run ONCE on an existing database (phpMyAdmin -> SQL).
-- Inbox upgrade: calls, reactions, quoted replies, 24h window tracking, delivery errors.
ALTER TABLE `messages`
  MODIFY COLUMN `message_type` ENUM('text','image','video','audio','document','template','interactive','location','sticker','reaction','contacts','call','system','unsupported') DEFAULT 'text',
  ADD COLUMN `media_id` VARCHAR(255) DEFAULT NULL AFTER `media_mime_type`,
  ADD COLUMN `reply_to_wamid` VARCHAR(100) DEFAULT NULL AFTER `media_id`,
  ADD COLUMN `reaction` VARCHAR(20) DEFAULT NULL AFTER `reply_to_wamid`,
  ADD COLUMN `error_message` VARCHAR(500) DEFAULT NULL AFTER `reaction`,
  ADD KEY `idx_wamid` (`whatsapp_message_id`),
  ADD KEY `idx_conv_id` (`conversation_id`,`id`);
ALTER TABLE `conversations` ADD COLUMN `last_inbound_at` DATETIME DEFAULT NULL AFTER `last_message_preview`;
UPDATE `conversations` cv SET last_inbound_at = (SELECT MAX(m.created_at) FROM messages m WHERE m.conversation_id=cv.id AND m.direction='inbound');
