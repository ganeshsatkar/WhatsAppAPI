<?php
// ============================================================
// Razorpay webhook — safety net for wallet recharges.
// If a customer pays and then closes the browser before the checkout callback runs,
// Razorpay still calls this URL and the wallet is credited anyway.
//
// Set this URL in Razorpay Dashboard → Settings → Webhooks:
//   https://YOUR-DOMAIN/webhook/razorpay.php
// Events to tick: payment.captured, order.paid
// Put the webhook secret you choose there into config.php as RAZORPAY_WEBHOOK_SECRET.
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wallet.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$raw       = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

if (!razorpayVerifyWebhookSignature($raw, $signature)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$event   = json_decode($raw, true) ?: [];
$type    = $event['event'] ?? '';
$payment = $event['payload']['payment']['entity'] ?? [];
$orderId = $payment['order_id'] ?? ($event['payload']['order']['entity']['id'] ?? '');
$payId   = $payment['id'] ?? '';

if (in_array($type, ['payment.captured', 'order.paid'], true) && $orderId !== '') {
    try {
        $res = completeRecharge($orderId, $payId);
        if ($res['ok'] && empty($res['already'])) {
            notify((int)$res['user_id'], 'Wallet recharged', inr($res['amount']) . ' was added to your wallet.', 'success', '/user/wallet.php');
        }
    } catch (\Throwable $e) {
        // Tell Razorpay to retry later
        http_response_code(500);
        echo json_encode(['error' => 'processing failed']);
        exit;
    }
}

// Acknowledge everything else so Razorpay stops retrying
echo json_encode(['status' => 'ok']);
