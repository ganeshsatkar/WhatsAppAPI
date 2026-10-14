<?php
// ============================================================
// Campaign message bookkeeping shared by the dashboard sender and the cron sender:
// one place that marks a recipient sent / failed, charges the wallet, refunds exactly once,
// and re-queues failed recipients without charging twice or double-counting.
// ============================================================
require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/api_core.php';      // apiErrorInfo() = Meta error code -> reason + advice + retryable

function campaignLogSent(array $log, int $campId, int $userId, ?string $messageId, string $category): void {
    $tx = debitWalletTx($userId, 1, $category, $campId);          // null = balance too low (message already went out)
    db()->run("UPDATE campaign_logs SET status='sent', message_id=?, sent_at=NOW(), error_message=NULL, error_code=NULL, failed_at=NULL, wallet_tx_id=?, refunded=0 WHERE id=?",
        [$messageId, $tx ?: null, $log['id']]);
    db()->run('UPDATE campaigns SET sent_count = sent_count + 1 WHERE id = ?', [$campId]);
}

/** Marks a recipient failed (immediate API error, or WhatsApp later said "not delivered"). Refunds the charge once. */
function campaignLogFailed(int $logId, string $error, ?int $code = null): void {
    $was = db()->row('SELECT sent_at FROM campaign_logs WHERE id=?', [$logId]);
    $st = db()->getPDO()->prepare("UPDATE campaign_logs SET status='failed', error_message=?, error_code=?, failed_at=NOW() WHERE id=? AND status IN ('pending','sent')");
    $st->execute([mb_substr($error !== '' ? $error : 'Delivery failed', 0, 500), $code, $logId]);
    if ($st->rowCount() !== 1) return;                                        // already failed / delivered / read
    $log = db()->row('SELECT campaign_id FROM campaign_logs WHERE id=?', [$logId]);
    // a message that Meta first accepted (counted as sent) and later reported undelivered moves from sent to failed
    if ($log) db()->run('UPDATE campaigns SET failed_count = failed_count + 1, sent_count = GREATEST(sent_count - ?, 0) WHERE id=?', [($was && $was['sent_at']) ? 1 : 0, $log['campaign_id']]);
    campaignRefundLog($logId, 'delivery failed' . ($code ? " (code $code)" : ''));
}

/** Gives back the money charged for one recipient – exactly once. */
function campaignRefundLog(int $logId, string $why): bool {
    $st = db()->getPDO()->prepare('UPDATE campaign_logs SET refunded=1 WHERE id=? AND refunded=0 AND wallet_tx_id IS NOT NULL AND wallet_tx_id > 0');
    $st->execute([$logId]);
    if ($st->rowCount() !== 1) return false;
    $log = db()->row('SELECT * FROM campaign_logs WHERE id=?', [$logId]);
    $tx  = $log ? db()->row('SELECT * FROM wallet_transactions WHERE id=?', [$log['wallet_tx_id']]) : null;
    if (!$tx) return false;
    creditWallet((int)$tx['user_id'], (float)$tx['amount'], "Refund: campaign #{$log['campaign_id']} to {$log['phone']} – $why", 'campaign_refund', null, null,
        ['meta_cost' => $tx['meta_cost'], 'category' => $tx['category'], 'msg_count' => (int)($tx['msg_count'] ?: 1)]);
    return true;
}

/** Is retrying this failure sensible? Network errors / unknown-but-transient = yes; "not on WhatsApp", bad template... = no. */
function campaignFailureRetryable(?int $code): bool {
    if ($code === null) return true;
    return apiErrorInfo($code)['retryable'];
}

/**
 * Put failed recipients back in the queue. $all=false skips permanent failures (not on WhatsApp, template problems…).
 * Counters are corrected so the retry never double counts. Returns [requeued, skipped].
 */
function campaignRetryFailed(int $campId, int $userId, bool $all = false): array {
    $rows = db()->all("SELECT id, error_code, sent_at FROM campaign_logs WHERE campaign_id=? AND user_id=? AND status='failed'", [$campId, $userId]);
    $ids = []; $skipped = 0;
    foreach ($rows as $r) {
        $code = $r['error_code'] !== null ? (int)$r['error_code'] : null;
        if ($all || campaignFailureRetryable($code)) $ids[] = (int)$r['id'];
        else $skipped++;
    }
    if (!$ids) return [0, $skipped];
    $in = implode(',', $ids);
    db()->run("UPDATE campaign_logs SET status='pending', message_id=NULL, error_message=NULL, error_code=NULL, failed_at=NULL, attempts=attempts+1, sent_at=NULL WHERE id IN ($in) AND status='failed'");
    db()->run("UPDATE campaigns SET status='running', failed_count=GREATEST(failed_count-?,0), completed_at=NULL WHERE id=? AND user_id=?",
        [count($ids), $campId, $userId]);
    return [count($ids), $skipped];
}

/** Marketing messages must not go to people who replied STOP / are blocked. Returns a reason or null. */
function campaignSkipReason(array $log, string $category): ?string {
    if ($category !== 'MARKETING') return null;
    if (isset($log['c_blocked']) && (int)$log['c_blocked'] === 1) return 'Contact is blocked – skipped';
    if (isset($log['c_opt_in']) && $log['c_opt_in'] !== null && (int)$log['c_opt_in'] === 0) return 'Contact opted out (STOP) – skipped';
    return null;
}
