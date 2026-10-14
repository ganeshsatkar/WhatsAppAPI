-- Profit tracking: every wallet transaction now records category + message count,
-- and admin credits record how the money was received.
ALTER TABLE wallet_transactions
  ADD COLUMN IF NOT EXISTS category VARCHAR(30) DEFAULT NULL AFTER meta_cost,
  ADD COLUMN IF NOT EXISTS msg_count INT DEFAULT NULL AFTER category,
  ADD COLUMN IF NOT EXISTS pay_mode VARCHAR(20) DEFAULT NULL AFTER msg_count,
  ADD COLUMN IF NOT EXISTS pay_reference VARCHAR(100) DEFAULT NULL AFTER pay_mode,
  ADD COLUMN IF NOT EXISTS created_by INT DEFAULT NULL AFTER pay_reference;
ALTER TABLE wallet_transactions
  ADD INDEX IF NOT EXISTS idx_wt_created (created_at),
  ADD INDEX IF NOT EXISTS idx_wt_user_created (user_id, created_at);
-- Best-effort backfill of old message debits from their description ("12 marketing messages sent", "Campaign #5 · 3 utility msg")
UPDATE wallet_transactions
   SET msg_count = CAST(REGEXP_REPLACE(REGEXP_SUBSTR(description, '[0-9]+ (marketing|utility|authentication|service)'), '[^0-9]', '') AS UNSIGNED),
       category  = UPPER(REGEXP_SUBSTR(description, '(marketing|utility|authentication|service)'))
 WHERE type='debit' AND meta_cost IS NOT NULL AND msg_count IS NULL
   AND description REGEXP '[0-9]+ (marketing|utility|authentication|service)';
