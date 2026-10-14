-- Three plans (Trial Rs0 / Basic Rs599 / Platform Rs1199) + real subscription expiry.
ALTER TABLE plans
  ADD COLUMN IF NOT EXISTS duration_days INT NOT NULL DEFAULT 30 AFTER billing_cycle,
  ADD COLUMN IF NOT EXISTS is_public TINYINT(1) NOT NULL DEFAULT 1 AFTER duration_days;
ALTER TABLE subscriptions
  ADD COLUMN IF NOT EXISTS reminded_at DATETIME DEFAULT NULL AFTER contacts_used;
ALTER TABLE subscriptions
  ADD INDEX IF NOT EXISTS idx_sub_user_status (user_id, status, expires_at);

INSERT INTO plans (name,slug,price,duration_days,is_public,max_contacts,max_messages,max_team_members,chatbot_builder,automation_workflows,advanced_analytics,api_access,is_active) VALUES
 ('Trial','trial',0,7,0,-1,-1,-1,1,1,1,1,1),
 ('Basic','basic',599,30,1,5000,-1,2,1,0,0,0,1),
 ('Platform','platform',1199,30,1,-1,-1,-1,1,1,1,1,1)
ON DUPLICATE KEY UPDATE name=VALUES(name), price=VALUES(price), duration_days=VALUES(duration_days), is_public=VALUES(is_public),
  max_contacts=VALUES(max_contacts), max_messages=VALUES(max_messages), max_team_members=VALUES(max_team_members),
  chatbot_builder=VALUES(chatbot_builder), automation_workflows=VALUES(automation_workflows),
  advanced_analytics=VALUES(advanced_analytics), api_access=VALUES(api_access), is_active=1;
-- Old plans are retired (kept so history still resolves)
UPDATE plans SET is_active=0, is_public=0 WHERE slug IN ('free','pro','ultimate','growth','enterprise');

-- Existing customers must not be locked out on deploy: give everybody a 30-day window on the plan they are on today.
UPDATE subscriptions SET expires_at = NOW() + INTERVAL 30 DAY WHERE status='active' AND expires_at IS NULL;
INSERT INTO subscriptions (user_id, plan_id, status, started_at, expires_at)
SELECT u.id, u.plan_id, 'active', NOW(), NOW() + INTERVAL 30 DAY FROM users u
 WHERE u.role='user' AND NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id=u.id AND s.status='active' AND (s.expires_at IS NULL OR s.expires_at > NOW()));
