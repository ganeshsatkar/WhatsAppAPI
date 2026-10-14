<?php
// ============================================================
// Plans & subscriptions: who has an active plan, activating / extending / cancelling,
// feature gates and expiry. Super admin is never locked.
// ============================================================

// Pages an account WITHOUT an active plan may still open (to see status, recharge wallet, buy a plan).
const PLAN_LOCK_WHITELIST = ['dashboard.php', 'billing.php', 'wallet.php', 'settings.php', 'notifications.php', 'logout.php'];

function planFeatureKeys(): array {
    return ['chatbot_builder' => 'Chatbot builder', 'automation_workflows' => 'Automation', 'advanced_analytics' => 'Advanced analytics', 'api_access' => 'Developer API'];
}

function fmtIst(?string $utc, string $fmt = 'd M Y, h:i A'): string {
    if (!$utc) return '—';
    try { return (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format($fmt); }
    catch (\Throwable $e) { return $utc; }
}

/**
 * Current subscription state of a user.
 * Returns: active(bool), superadmin(bool), plan(array|null – the plan in force, or the last plan when inactive),
 *          sub(array|null), expires_at, days_left (int|null = no expiry), is_trial, last(array|null – newest subscription row)
 */
function subscriptionState(int $uid, bool $fresh = false): array {
    static $cache = [];
    if ($fresh) unset($cache[$uid]);
    if (isset($cache[$uid])) return $cache[$uid];

    $u = db()->row('SELECT role, plan_id FROM users WHERE id=?', [$uid]);
    $st = ['active' => false, 'superadmin' => false, 'plan' => null, 'sub' => null, 'expires_at' => null, 'days_left' => null, 'is_trial' => false, 'last' => null];
    if (!$u) return $cache[$uid] = $st;

    if ($u['role'] === 'superadmin') {
        $st['active'] = true; $st['superadmin'] = true;
        $st['plan'] = db()->row("SELECT * FROM plans WHERE slug='platform'") ?: db()->row('SELECT * FROM plans ORDER BY price DESC LIMIT 1');
        return $cache[$uid] = $st;
    }

    $sub = db()->row("SELECT s.*, TIMESTAMPDIFF(SECOND, NOW(), s.expires_at) AS secs_left FROM subscriptions s
                      WHERE s.user_id=? AND s.status='active' AND (s.expires_at IS NULL OR s.expires_at > NOW())
                      ORDER BY (s.expires_at IS NULL) DESC, s.expires_at DESC, s.id DESC LIMIT 1", [$uid]);
    $st['last'] = db()->row('SELECT * FROM subscriptions WHERE user_id=? ORDER BY id DESC LIMIT 1', [$uid]);
    if ($sub) {
        $st['active']     = true;
        $st['sub']        = $sub;
        $st['plan']       = db()->row('SELECT * FROM plans WHERE id=?', [$sub['plan_id']]);
        $st['expires_at'] = $sub['expires_at'];
        $st['days_left']  = $sub['expires_at'] === null ? null : max(0, (int)ceil(((int)$sub['secs_left']) / 86400));
        $st['is_trial']   = $st['plan'] && (float)$st['plan']['price'] <= 0;
    } else {
        $pid = $st['last']['plan_id'] ?? $u['plan_id'];
        $st['plan'] = db()->row('SELECT * FROM plans WHERE id=?', [$pid]);
        $st['expires_at'] = $st['last']['expires_at'] ?? null;
    }
    return $cache[$uid] = $st;
}

function subscriptionActive(int $uid): bool { return subscriptionState($uid)['active']; }

/** Does this user's ACTIVE plan include the feature (chatbot_builder, automation_workflows, advanced_analytics, api_access)? */
function planAllows(int $uid, string $feature): bool {
    $s = subscriptionState($uid);
    if (!$s['active']) return false;
    if ($s['superadmin']) return true;
    return !empty($s['plan'][$feature]);
}

/** SQL fragment: user in column $col has an active plan (or is super admin). For cron queries. */
function subscriptionActiveSql(string $col): string {
    return "(EXISTS (SELECT 1 FROM subscriptions _s WHERE _s.user_id = $col AND _s.status='active' AND (_s.expires_at IS NULL OR _s.expires_at > NOW()))
             OR EXISTS (SELECT 1 FROM users _su WHERE _su.id = $col AND _su.role='superadmin'))";
}

function wantsJson(): bool {
    return (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '') || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        || (isset($_GET['action']) && in_array($_GET['action'], ['poll', 'load_older'], true));
}

/** Called from requireLogin(): accounts without an active plan can only reach the whitelisted pages. */
function enforceSubscriptionGate(): void {
    $u = currentUser();
    if (!$u || $u['role'] !== 'user') return;
    if (subscriptionActive((int)$u['id'])) return;
    if (in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), PLAN_LOCK_WHITELIST, true)) return;
    if (wantsJson() || ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'json') !== false) {
        http_response_code(402);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'plan_inactive', 'message' => 'Your plan is not active. Open Billing to activate or renew.']);
        exit;
    }
    header('Location: /user/billing.php?locked=1');
    exit;
}

/** Page-level feature gate: shows an upgrade card instead of the page. */
function requireFeature(string $feature, string $label): void {
    $u = currentUser();
    if (!$u || $u['role'] === 'superadmin' || planAllows((int)$u['id'], $feature)) return;
    $s = subscriptionState((int)$u['id']);
    $pageTitle = $label;
    include __DIR__ . '/user_header.php';
    echo '<div class="card" style="max-width:560px;margin:40px auto;text-align:center;"><div class="card-body" style="padding:36px;">'
       . '<div style="font-size:42px;margin-bottom:10px;">🔒</div>'
       . '<div class="card-title" style="font-size:18px;margin-bottom:8px;">' . e($label) . ' is not in your plan</div>'
       . '<p style="color:var(--text2);font-size:13.5px;margin-bottom:18px;">Your current plan: <strong>' . e($s['plan']['name'] ?? '—') . '</strong>. Upgrade to use ' . e($label) . '.</p>'
       . '<a class="btn btn-primary" href="/user/billing.php">View plans &amp; upgrade →</a></div></div>';
    include __DIR__ . '/user_footer.php';
    exit;
}

/**
 * Activate / extend a plan for a user. Same plan still running → extend from its end date;
 * different plan → the new plan starts now and the old one ends now.
 * $o: source (admin_free|admin_paid|wallet), amount, reference, note, by (admin id), allow_repeat_trial
 * Returns [bool ok, string message].
 */
function activatePlan(int $uid, int $planId, int $days, array $o = []): array {
    $plan = db()->row('SELECT * FROM plans WHERE id=?', [$planId]);
    $user = db()->row("SELECT id,name,role FROM users WHERE id=?", [$uid]);
    if (!$plan || !$user || $user['role'] !== 'user') return [false, 'User or plan not found.'];
    if (!$plan['is_active']) return [false, 'That plan is disabled.'];
    if ($days < 1 || $days > 3660) return [false, 'Days must be between 1 and 3660.'];
    $isTrial = (float)$plan['price'] <= 0;
    if ($isTrial && empty($o['allow_repeat_trial'])) {
        $had = db()->count('SELECT COUNT(*) FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.user_id=? AND p.price<=0', [$uid]);
        if ($had) return [false, 'This user already used a trial. Tick "allow repeat trial" to give another.'];
    }
    $amount = round((float)($o['amount'] ?? 0), 2);
    $source = $o['source'] ?? 'admin_free';

    $pdo = db()->getPDO(); $pdo->beginTransaction();
    try {
        $cur = db()->row("SELECT * FROM subscriptions WHERE user_id=? AND status='active' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY id DESC LIMIT 1 FOR UPDATE", [$uid]);
        if ($cur && (int)$cur['plan_id'] === $planId) {
            if ($cur['expires_at'] !== null) {
                db()->run('UPDATE subscriptions SET expires_at = DATE_ADD(expires_at, INTERVAL ? DAY), reminded_at = NULL WHERE id=?', [$days, $cur['id']]);
            }
            $subId = (int)$cur['id'];
        } else {
            db()->run("UPDATE subscriptions SET status='expired', expires_at = NOW() WHERE user_id=? AND status='active'", [$uid]);
            $subId = (int)db()->insert("INSERT INTO subscriptions (user_id, plan_id, status, started_at, expires_at) VALUES (?,?,'active',NOW(),DATE_ADD(NOW(), INTERVAL ? DAY))", [$uid, $planId, $days]);
        }
        db()->run('UPDATE users SET plan_id=? WHERE id=?', [$planId, $uid]);
        if ($amount > 0) {
            db()->insert("INSERT INTO payments (user_id, plan_id, amount, currency, gateway, transaction_id, status, payment_data) VALUES (?,?,?,?,?,?,'success',?)",
                [$uid, $planId, $amount, 'INR', $source === 'wallet' ? 'wallet' : 'offline', mb_substr((string)($o['reference'] ?? ''), 0, 190) ?: null,
                 json_encode(['source' => $source, 'days' => $days, 'note' => $o['note'] ?? '', 'by' => $o['by'] ?? null, 'subscription_id' => $subId])]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('activatePlan: ' . $e->getMessage());
        return [false, 'Could not activate the plan (database error).'];
    }
    $st = subscriptionState($uid, true);
    logActivity('plan_activated', "user #$uid {$plan['name']} +{$days}d via $source" . ($amount > 0 ? " ₹$amount" : ''));
    notify($uid, 'Plan activated: ' . $plan['name'], 'Valid until ' . ($st['expires_at'] ? fmtIst($st['expires_at'], 'd M Y') : 'no expiry') . '.', 'success', '/user/billing.php');
    return [true, $plan['name'] . ' active until ' . ($st['expires_at'] ? fmtIst($st['expires_at'], 'd M Y, h:i A') : 'no expiry') . ' (IST).'];
}

function cancelSubscription(int $uid): int {
    $n = db()->run("UPDATE subscriptions SET status='cancelled', expires_at = NOW() WHERE user_id=? AND status='active'", [$uid])->rowCount();
    subscriptionState($uid, true);
    if ($n) { logActivity('plan_cancelled', "user #$uid"); notify($uid, 'Plan ended', 'Your plan was ended by the administrator. Open Billing to activate again.', 'warning', '/user/billing.php'); }
    return $n;
}

/** User buys/renews a public paid plan from wallet balance. Returns [ok, message]. */
function buyPlanFromWallet(int $uid, int $planId): array {
    require_once __DIR__ . '/wallet.php';
    $plan = db()->row('SELECT * FROM plans WHERE id=? AND is_active=1 AND is_public=1 AND price>0', [$planId]);
    if (!$plan) return [false, 'That plan is not available for purchase.'];
    $s = subscriptionState($uid, true);
    if ($s['active'] && $s['sub'] && (int)$s['sub']['plan_id'] !== $planId && $s['plan'] && (float)$plan['price'] < (float)$s['plan']['price']) {
        return [false, 'You are on a higher plan (' . $s['plan']['name'] . ') that is still running. You can switch down after it ends.'];
    }
    $price = round((float)$plan['price'], 2);
    if (!walletChargeFixed($uid, $price, 'Plan: ' . $plan['name'] . ' (' . (int)$plan['duration_days'] . ' days)', 'plan_fee', $planId)) {
        return [false, 'Not enough wallet balance. You need ' . inr($price) . ' — recharge your wallet first.'];
    }
    [$ok, $m] = activatePlan($uid, $planId, (int)$plan['duration_days'], ['source' => 'wallet', 'amount' => $price, 'reference' => 'wallet']);
    if (!$ok) { creditWallet($uid, $price, 'Refund: plan could not be activated', 'plan_refund'); return [false, $m]; }
    return [true, $m];
}

/** Cron: end expired subscriptions + remind users 3 days before. Returns [expired, reminded]. */
function runSubscriptionMaintenance(): array {
    $exp = db()->all("SELECT s.id, s.user_id, p.name FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.status='active' AND s.expires_at IS NOT NULL AND s.expires_at <= NOW()");
    foreach ($exp as $r) {
        if (db()->run("UPDATE subscriptions SET status='expired' WHERE id=? AND status='active'", [$r['id']])->rowCount()) {
            notify((int)$r['user_id'], 'Your ' . $r['name'] . ' plan has expired', 'Campaigns, chatbots and API are paused. Renew from Billing to continue.', 'warning', '/user/billing.php');
        }
    }
    $rem = db()->all("SELECT s.id, s.user_id, s.expires_at, p.name FROM subscriptions s JOIN plans p ON p.id=s.plan_id
                      WHERE s.status='active' AND s.reminded_at IS NULL AND s.expires_at > NOW() AND s.expires_at <= DATE_ADD(NOW(), INTERVAL 3 DAY)");
    foreach ($rem as $r) {
        if (db()->run('UPDATE subscriptions SET reminded_at=NOW() WHERE id=? AND reminded_at IS NULL', [$r['id']])->rowCount()) {
            notify((int)$r['user_id'], 'Plan ending soon', 'Your ' . $r['name'] . ' plan ends on ' . fmtIst($r['expires_at'], 'd M Y') . '. Renew from Billing to avoid interruption.', 'warning', '/user/billing.php');
        }
    }
    return [count($exp), count($rem)];
}
