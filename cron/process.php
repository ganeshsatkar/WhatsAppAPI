<?php
// ============================================================
// WhatsFlow Pro - Cron: Process Campaigns & Automation
// Run every minute: * * * * * php /path/to/cron/process.php
// ============================================================

require_once __DIR__ . '/../config/config.php';

if (PHP_SAPI !== 'cli' && (CRON_SECRET === '' || !hash_equals(CRON_SECRET, (string)($_GET['secret'] ?? '')))) {
    http_response_code(403);
    die('Unauthorized');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/WhatsAppService.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/bot_runtime.php';
require_once __DIR__ . '/../includes/api_core.php';
require_once __DIR__ . '/../includes/campaign_core.php';

$startTime = microtime(true);
$processed = 0;

// Only one cron run at a time (a slow run must not overlap the next minute's run and double-send)
if ((int)(db()->row("SELECT GET_LOCK('wpapi_cron', 0) AS l")['l'] ?? 0) !== 1) {
    echo "[" . date('Y-m-d H:i:s') . "] Previous run still active, skipping.\n";
    exit;
}
register_shutdown_function(fn() => db()->row("SELECT RELEASE_LOCK('wpapi_cron') AS r"));

// ---- 0. Plans: end expired subscriptions, remind 3 days before ----
try { runSubscriptionMaintenance(); } catch (\Throwable $e) { error_log('subscriptions: ' . $e->getMessage()); }

// ---- 1. Process scheduled campaigns ----

$campaigns = db()->all("
    SELECT c.*, u.id as owner_id
    FROM campaigns c
    JOIN users u ON u.id = c.user_id
    WHERE c.status = 'scheduled'
      AND c.scheduled_at <= NOW()
      AND " . subscriptionActiveSql('c.user_id') . "
    LIMIT 5
");

foreach ($campaigns as $campaign) {
    processCampaign($campaign);
    $processed++;
}

// ---- 2. Process running campaigns (send batches) ----

$running = db()->all("
    SELECT * FROM campaigns
    WHERE status = 'running'
      AND " . subscriptionActiveSql('campaigns.user_id') . "
    LIMIT 3
");

foreach ($running as $campaign) {
    sendCampaignBatch($campaign, 50); // 50 messages per cron run
    $processed++;
}

// ---- 2b. Resume chatbot flows waiting on a Delay node ----
try { resumeDelayedBotSessions(); } catch (\Throwable $e) { error_log('bot resume: ' . $e->getMessage()); }

// ---- 2c. Public API: push (and retry) delivery callbacks to customers' systems ----
try { apiRunCallbacks(100); } catch (\Throwable $e) { error_log('api callbacks: ' . $e->getMessage()); }

// ---- 3. Process pending automation logs ----

$automations = db()->all("
    SELECT al.*, ar.actions, ar.run_once, ar.id as rule_id_real
    FROM automation_logs al
    JOIN automation_rules ar ON ar.id = al.rule_id
    WHERE al.status = 'pending'
      AND al.created_at <= NOW()
      AND " . subscriptionActiveSql('ar.user_id') . "
    LIMIT 20
");

foreach ($automations as $auto) {
    executeAutomation($auto);
    $processed++;
}

echo "[" . date('Y-m-d H:i:s') . "] Cron done. Processed: $processed items in " . round(microtime(true) - $startTime, 2) . "s\n";

// ============================================================

function processCampaign(array $campaign): void {
    $userId     = $campaign['user_id'];
    $campaignId = $campaign['id'];
    $listId     = $campaign['contact_list_id'];

    // Get contacts
    if ($listId) {
        $contacts = db()->all('SELECT c.* FROM contacts c JOIN contact_list_members clm ON clm.contact_id = c.id WHERE clm.list_id = ? AND c.user_id = ? AND c.opt_in = 1 AND c.is_blocked = 0', [$listId, $userId]);
    } else {
        $contacts = db()->all('SELECT * FROM contacts WHERE user_id = ? AND opt_in = 1 AND is_blocked = 0', [$userId]);
    }

    $total = count($contacts);
    db()->run('UPDATE campaigns SET status = ?, started_at = NOW(), total_contacts = ? WHERE id = ?', ['running', $total, $campaignId]);

    // Insert pending logs
    foreach ($contacts as $contact) {
        db()->run('INSERT IGNORE INTO campaign_logs (campaign_id, user_id, contact_id, phone, status) VALUES (?,?,?,?,?)',
            [$campaignId, $userId, $contact['id'], $contact['phone'], 'pending']);
    }
}

function sendCampaignBatch(array $campaign, int $batchSize): void {
    $wa = WhatsAppService::forUser($campaign['user_id']);
    if (!$wa) {
        db()->run("UPDATE campaigns SET status = 'failed' WHERE id = ?", [$campaign['id']]);
        return;
    }

    $template = db()->row('SELECT * FROM message_templates WHERE id = ?', [$campaign['template_id']]);
    if (!$template) return;
    $category = strtoupper($template['category'] ?? 'MARKETING');

    $logs = db()->all('SELECT cl.*, c.name as cname, c.opt_in as c_opt_in, c.is_blocked as c_blocked FROM campaign_logs cl LEFT JOIN contacts c ON c.id = cl.contact_id WHERE cl.campaign_id = ? AND cl.status = ? LIMIT ?',
        [$campaign['id'], 'pending', $batchSize]);

    if (empty($logs)) {
        // All sent
        db()->run("UPDATE campaigns SET status = 'completed', completed_at = NOW() WHERE id = ?", [$campaign['id']]);
        return;
    }

    if (!canAffordMessages($campaign['user_id'], count($logs), $category)) {
        $affordable = affordableMessages($campaign['user_id'], $category);
        if ($affordable <= 0) {
            db()->run("UPDATE campaigns SET status = 'failed' WHERE id = ?", [$campaign['id']]);
            return;
        }
        $logs = array_slice($logs, 0, $affordable);
    }

    foreach ($logs as $log) {
        if ($skip = campaignSkipReason($log, $category)) { campaignLogFailed((int)$log['id'], $skip, -1); continue; }
        $result = $wa->sendTemplate(
            $log['phone'],
            $template['template_name'],
            $template['language'],
            buildTemplateComponents($template, $log['cname'] ?? 'Customer', $log['phone'])
        );

        if ($result['success']) {
            campaignLogSent($log, (int)$campaign['id'], (int)$campaign['user_id'], $result['message_id'], $category);
        } else {
            campaignLogFailed((int)$log['id'], (string)($result['error'] ?? 'Unknown'), isset($result['error_code']) ? (int)$result['error_code'] : null);
        }

        // Rate limit: ~70 messages/sec max for WhatsApp
        usleep(50000); // 50ms between messages
    }
}

function executeAutomation(array $auto): void {
    $actions = json_decode($auto['actions'], true) ?? [];
    $userId  = $auto['user_id'];
    $contactId = $auto['contact_id'];

    $contact = db()->row('SELECT * FROM contacts WHERE id = ?', [$contactId]);
    if (!$contact) {
        db()->run('UPDATE automation_logs SET status = ? WHERE id = ?', ['skipped', $auto['id']]);
        return;
    }

    $wa = WhatsAppService::forUser($userId);

    foreach ($actions as $action) {
        switch ($action['type'] ?? '') {
            case 'send_message':
                if ($wa) {
                    $msg = str_replace(['{{name}}', '{{phone}}'], [$contact['name'], $contact['phone']], $action['message'] ?? '');
                    $wa->sendText($contact['phone'], $msg);
                }
                break;
            case 'send_template':
                // Business-initiated message: charged from the wallet like any template, refunded if Meta refuses it
                $tpl = db()->row('SELECT * FROM message_templates WHERE user_id=? AND (id=? OR template_name=?) LIMIT 1',
                    [$userId, (int)($action['template_id'] ?? 0), (string)($action['template_name'] ?? '')]);
                if ($wa && $tpl) {
                    $cat = strtoupper($tpl['category'] ?? 'MARKETING');
                    if ($cat === 'MARKETING' && !(int)$contact['opt_in']) break;   // opted out
                    if (!debitWallet((int)$userId, 1, $cat, 0, 'automation', 'Automation template ' . $tpl['template_name'])) break;
                    $r = $wa->sendTemplate(normalizePhone($contact['phone']), $tpl['template_name'], $tpl['language'] ?: 'en',
                        buildTemplateComponents($tpl, (string)$contact['name'], (string)$contact['phone']));
                    if (empty($r['success'])) {
                        creditWallet((int)$userId, msgPrice((int)$userId, $cat), 'Refund · automation template not sent', 'refund', null, null,
                            ['meta_cost' => metaCost($cat), 'category' => $cat, 'msg_count' => 1]);
                    }
                }
                break;
            case 'add_tag':
                db()->run('INSERT IGNORE INTO contact_tags (user_id, contact_id, tag) VALUES (?,?,?)', [$userId, $contactId, $action['tag'] ?? '']);
                break;
            case 'update_status':
                db()->run('UPDATE contacts SET notes = ? WHERE id = ?', [$action['note'] ?? '', $contactId]);
                break;
        }
    }

    db()->run('UPDATE automation_logs SET status = ?, executed_at = NOW() WHERE id = ?', ['completed', $auto['id']]);
    db()->run('UPDATE automation_rules SET run_count = run_count + 1 WHERE id = ?', [$auto['rule_id_real']]);
}
