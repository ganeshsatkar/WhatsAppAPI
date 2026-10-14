<?php
// ============================================================
// WP API — Wallet & Billing Helper Functions
// Include after functions.php
// ============================================================

// Razorpay settings. Real values go in config/config.php (defined BEFORE this file loads);
// these fallbacks just keep the app from crashing when they are missing.
if (!defined('RAZORPAY_KEY_ID'))         define('RAZORPAY_KEY_ID', '');
if (!defined('RAZORPAY_KEY_SECRET'))     define('RAZORPAY_KEY_SECRET', '');
if (!defined('RAZORPAY_WEBHOOK_SECRET')) define('RAZORPAY_WEBHOOK_SECRET', '');
if (!defined('RAZORPAY_API_BASE'))       define('RAZORPAY_API_BASE', 'https://api.razorpay.com/v1');
if (!defined('WALLET_MIN_RECHARGE'))     define('WALLET_MIN_RECHARGE', 100);
if (!defined('WALLET_MAX_RECHARGE'))     define('WALLET_MAX_RECHARGE', 100000);

// Get user's wallet balance
function walletBalance(int $userId): float {
    $w = db()->row('SELECT balance FROM wallets WHERE user_id=?', [$userId]);
    if (!$w) {
        db()->insert('INSERT IGNORE INTO wallets (user_id,balance) VALUES (?,0)', [$userId]);
        return 0.0;
    }
    return (float)$w['balance'];
}

// Get price per message for a user (custom > global)
function msgPrice(int $userId, string $category = 'MARKETING'): float {
    $cat = strtoupper($category);
    // Check custom user price first
    $custom = db()->row('SELECT price_per_msg FROM user_pricing WHERE user_id=? AND category=?', [$userId, $cat]);
    if ($custom) return (float)$custom['price_per_msg'];
    // Fall back to global pricing
    $global = db()->row('SELECT price_per_msg FROM message_pricing WHERE category=?', [$cat]);
    return $global ? (float)$global['price_per_msg'] : 0.75;
}

// What Meta bills you for one message of this category (0 if not configured).
function metaCost(string $category): float {
    $r = db()->row('SELECT meta_cost FROM message_pricing WHERE category=?', [strtoupper($category)]);
    return $r ? (float)$r['meta_cost'] : 0.0;
}

// Selling price = Meta cost + markup %, rounded to 4 decimals.
function sellingPrice(float $metaCost, float $markupPercent): float {
    return round($metaCost * (1 + $markupPercent / 100), 4);
}

// Check if user can afford N messages
function canAffordMessages(int $userId, int $count, string $category = 'MARKETING'): bool {
    $price   = msgPrice($userId, $category);
    $balance = walletBalance($userId);
    return $balance >= ($price * $count);
}

// How many messages can user afford
function affordableMessages(int $userId, string $category = 'MARKETING'): int {
    $price   = msgPrice($userId, $category);
    $balance = walletBalance($userId);
    if ($price <= 0) return 999999;
    return (int)floor($balance / $price);
}

// Debit wallet for messages sent.
// Atomic: the balance check and the deduction are ONE statement, so two sends running at the
// same moment can never push the balance below zero.
// Debit for messages. Returns the wallet_transactions id (>0), 0 when nothing needed charging, null when the balance is too low.
function debitWalletTx(int $userId, int $msgCount, string $category, int $campaignId = 0, string $refType = 'campaign', ?string $description = null, ?int $refId = null): ?int {
    if ($msgCount <= 0) return 0;
    $price      = msgPrice($userId, $category);
    $totalCost  = round($price * $msgCount, 4);
    if ($totalCost <= 0) return 0;

    walletBalance($userId); // make sure the wallet row exists

    $pdo = db()->getPDO();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('UPDATE wallets SET balance = balance - ?, total_debited = total_debited + ? WHERE user_id = ? AND balance >= ?');
        $st->execute([$totalCost, $totalCost, $userId, $totalCost]);
        if ($st->rowCount() !== 1) { $pdo->rollBack(); return null; }

        $newBalance = (float)db()->row('SELECT balance FROM wallets WHERE user_id=?', [$userId])['balance'];
        $desc = $description ?: ($campaignId
            ? "Campaign #$campaignId · $msgCount " . strtolower($category) . " msg"
            : "$msgCount " . strtolower($category) . " message" . ($msgCount > 1 ? 's' : '') . " sent");

        $txId = (int)db()->insert('INSERT INTO wallet_transactions (user_id,type,amount,balance_after,description,ref_type,ref_id,meta_cost,category,msg_count,status) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$userId, 'debit', $totalCost, $newBalance, $desc, $refType, $refId ?? ($campaignId ?: null), round(metaCost($category) * $msgCount, 4), strtoupper($category), $msgCount, 'success']);
        $pdo->commit();
        return $txId;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function debitWallet(int $userId, int $msgCount, string $category, int $campaignId = 0, string $refType = 'campaign', ?string $description = null, ?int $refId = null): bool {
    return debitWalletTx($userId, $msgCount, $category, $campaignId, $refType, $description, $refId) !== null;
}

// Fixed-amount charge (plan fee etc.) – not a message, so no Meta cost and not part of message margin.
function walletChargeFixed(int $userId, float $amount, string $description, string $refType, ?int $refId = null): bool {
    $amount = round($amount, 4);
    if ($amount <= 0) return true;
    walletBalance($userId);
    $pdo = db()->getPDO(); $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('UPDATE wallets SET balance = balance - ?, total_debited = total_debited + ? WHERE user_id = ? AND balance >= ?');
        $st->execute([$amount, $amount, $userId, $amount]);
        if ($st->rowCount() !== 1) { $pdo->rollBack(); return false; }
        $nb = (float)db()->row('SELECT balance FROM wallets WHERE user_id=?', [$userId])['balance'];
        db()->insert('INSERT INTO wallet_transactions (user_id,type,amount,balance_after,description,ref_type,ref_id,status) VALUES (?,?,?,?,?,?,?,?)',
            [$userId, 'debit', $amount, $nb, $description, $refType, $refId, 'success']);
        $pdo->commit();
        return true;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// Credit wallet (admin credit). Atomic add — never rewrites the balance from a stale read,
// and keeps full 4-decimal precision (utility messages cost fractions of a rupee).
// $extra may carry: meta_cost, category, msg_count (refunds of messages), pay_mode, pay_reference, created_by (admin entries).
function creditWallet(int $userId, float $amount, string $description, string $refType = 'admin_credit', ?string $rzpOrderId = null, ?string $rzpPaymentId = null, array $extra = []): bool {
    if ($amount <= 0) return false;
    walletBalance($userId);

    $pdo = db()->getPDO();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE wallets SET balance = balance + ?, total_credited = total_credited + ? WHERE user_id = ?')
            ->execute([$amount, $amount, $userId]);
        $newBalance = (float)db()->row('SELECT balance FROM wallets WHERE user_id=?', [$userId])['balance'];
        db()->insert('INSERT INTO wallet_transactions (user_id,type,amount,balance_after,description,ref_type,razorpay_order_id,razorpay_payment_id,meta_cost,category,msg_count,pay_mode,pay_reference,created_by,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$userId, 'credit', $amount, $newBalance, $description, $refType, $rzpOrderId, $rzpPaymentId,
             $extra['meta_cost'] ?? null, $extra['category'] ?? null, $extra['msg_count'] ?? null, $extra['pay_mode'] ?? null, $extra['pay_reference'] ?? null, $extra['created_by'] ?? null, 'success']);
        $pdo->commit();
        return true;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// Format INR
function inr(float $amount): string {
    return '₹' . number_format($amount, 2);
}

// ============================================================
// Razorpay recharge
// ============================================================

function razorpayConfigured(): bool {
    return RAZORPAY_KEY_ID !== '' && RAZORPAY_KEY_SECRET !== '';
}

// Low-level call to the Razorpay REST API. Returns ['ok'=>bool, 'data'=>array] or ['ok'=>false,'error'=>string].
function razorpayApi(string $method, string $path, ?array $payload = null): array {
    if (!razorpayConfigured()) {
        return ['ok' => false, 'error' => 'Online recharge is not set up yet. Please contact support.'];
    }
    $ch = curl_init(rtrim(RAZORPAY_API_BASE, '/') . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) return ['ok' => false, 'error' => 'Could not reach Razorpay: ' . $err];
    $data = json_decode($body, true) ?: [];
    if ($code >= 400) return ['ok' => false, 'error' => $data['error']['description'] ?? "Razorpay error (HTTP $code)"];
    return ['ok' => true, 'data' => $data];
}

// Create a Razorpay order for a wallet recharge and remember it as a PENDING credit.
// The amount is stored server-side; it is never taken from the browser again at verify time.
function razorpayCreateRecharge(int $userId, float $amountInr): array {
    $amountInr = round($amountInr, 2);
    if ($amountInr < WALLET_MIN_RECHARGE || $amountInr > WALLET_MAX_RECHARGE) {
        return ['ok' => false, 'error' => 'Recharge amount must be between ' . inr(WALLET_MIN_RECHARGE) . ' and ' . inr(WALLET_MAX_RECHARGE) . '.'];
    }
    $paise = (int)round($amountInr * 100);
    $r = razorpayApi('POST', '/orders', [
        'amount'   => $paise,
        'currency' => 'INR',
        'receipt'  => 'wal_' . $userId . '_' . time(),
        'notes'    => ['user_id' => (string)$userId, 'purpose' => 'wallet_recharge'],
    ]);
    if (!$r['ok']) return $r;

    $orderId = $r['data']['id'] ?? '';
    if ($orderId === '') return ['ok' => false, 'error' => 'Razorpay did not return an order id.'];

    walletBalance($userId);
    $balance = walletBalance($userId);
    db()->insert('INSERT INTO wallet_transactions (user_id,type,amount,balance_after,description,ref_type,razorpay_order_id,status) VALUES (?,?,?,?,?,?,?,?)',
        [$userId, 'credit', $amountInr, $balance, 'Wallet recharge (awaiting payment)', 'recharge', $orderId, 'pending']);

    return ['ok' => true, 'order_id' => $orderId, 'amount_paise' => $paise, 'key_id' => RAZORPAY_KEY_ID];
}

// Checkout signature = HMAC_SHA256(order_id|payment_id, key_secret)
function razorpayVerifyCheckoutSignature(string $orderId, string $paymentId, string $signature): bool {
    if (RAZORPAY_KEY_SECRET === '' || $orderId === '' || $paymentId === '' || $signature === '') return false;
    $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, RAZORPAY_KEY_SECRET);
    return hash_equals($expected, $signature);
}

// Webhook signature = HMAC_SHA256(raw_body, webhook_secret)
function razorpayVerifyWebhookSignature(string $rawBody, string $signature): bool {
    if (RAZORPAY_WEBHOOK_SECRET === '' || $signature === '') return false;
    return hash_equals(hash_hmac('sha256', $rawBody, RAZORPAY_WEBHOOK_SECRET), $signature);
}

// Turn a pending recharge into money in the wallet — exactly once.
// Safe to call from both the browser callback AND the webhook: whichever arrives second sees
// status='success' and does nothing. The row is locked, so simultaneous calls cannot double-credit.
function completeRecharge(string $orderId, string $paymentId): array {
    $pdo = db()->getPDO();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM wallet_transactions WHERE razorpay_order_id = ? AND type = 'credit' ORDER BY id LIMIT 1 FOR UPDATE");
        $st->execute([$orderId]);
        $txn = $st->fetch(\PDO::FETCH_ASSOC);

        if (!$txn) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Unknown order.']; }
        if ($txn['status'] === 'success') {
            $pdo->commit();
            return ['ok' => true, 'already' => true, 'amount' => (float)$txn['amount'], 'user_id' => (int)$txn['user_id']];
        }

        $userId = (int)$txn['user_id'];
        $amount = (float)$txn['amount'];
        $pdo->prepare('INSERT IGNORE INTO wallets (user_id,balance) VALUES (?,0)')->execute([$userId]);
        $pdo->prepare('UPDATE wallets SET balance = balance + ?, total_credited = total_credited + ? WHERE user_id = ?')
            ->execute([$amount, $amount, $userId]);
        $newBalance = (float)db()->row('SELECT balance FROM wallets WHERE user_id=?', [$userId])['balance'];

        $pdo->prepare("UPDATE wallet_transactions SET status='success', balance_after=?, razorpay_payment_id=?, description=? WHERE id=?")
            ->execute([$newBalance, $paymentId, 'Wallet recharge via Razorpay', $txn['id']]);
        $pdo->commit();
        return ['ok' => true, 'already' => false, 'amount' => $amount, 'user_id' => $userId, 'balance' => $newBalance];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
