-- Run ONCE on an existing database (phpMyAdmin → SQL). Safe to re-run.
-- Adds the SERVICE rate row that Admin → Wallet Mgmt → Global Pricing expects.
INSERT IGNORE INTO `message_pricing` (`category`,`price_per_msg`) VALUES ('SERVICE', 0.1450);
