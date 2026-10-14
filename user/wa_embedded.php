<?php
// ============================================================
// WhatsFlow Pro - Embedded Signup completion
// The browser (Facebook JS SDK) hands us a one-time code + the WABA / phone number the customer
// just created or picked. We exchange the code for an access token and connect the account.
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/WhatsAppService.php';
requireLogin();
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'error' => 'POST only']); exit; }
verifyCsrf();

function es_fail(string $m): void { echo json_encode(['success' => false, 'error' => $m]); exit; }

if (!defined('META_APP_ID') || !defined('META_APP_SECRET') || META_APP_ID === '' || META_APP_SECRET === '') {
    es_fail('Embedded Signup is not configured on this platform (META_APP_ID / META_APP_SECRET missing).');
}
$uid    = (int)currentUser()['id'];
$code   = trim($_POST['code'] ?? '');
$waba   = preg_replace('/\D/', '', $_POST['waba_id'] ?? '');
$pid    = preg_replace('/\D/', '', $_POST['phone_number_id'] ?? '');
if ($code === '' || $waba === '' || $pid === '') es_fail('Signup did not return all details. Please try again.');

// 1. code -> access token
$tok = WhatsAppService::graph('GET', 'oauth/access_token', ['client_id' => META_APP_ID, 'client_secret' => META_APP_SECRET, 'code' => $code]);
if (empty($tok['access_token'])) es_fail('Facebook rejected the login code: ' . ($tok['error']['message'] ?? 'unknown error'));
$token = $tok['access_token'];

// 2. phone number must really belong to that WABA (stops a user submitting someone else's IDs)
$info = WhatsAppService::graph('GET', $pid, ['fields' => 'display_phone_number,verified_name'], null, $token);
if (isset($info['error'])) es_fail('Could not read the phone number: ' . ($info['error']['message'] ?? ''));
$list = WhatsAppService::graph('GET', "$waba/phone_numbers", ['fields' => 'id'], null, $token);
$ids  = array_column($list['data'] ?? [], 'id');
if (!in_array($pid, $ids, true)) es_fail('That phone number does not belong to the selected WhatsApp Business Account.');

// A WABA can only be connected to one workspace on this platform
if (db()->count('SELECT COUNT(*) FROM user_whatsapp_settings WHERE business_account_id=? AND user_id<>? AND is_connected=1', [$waba, $uid])) {
    es_fail('This WhatsApp Business Account is already connected to another account.');
}

// 3. route this WABA's webhooks to our app
$sub = WhatsAppService::graph('POST', "$waba/subscribed_apps", [], [], $token);
if (empty($sub['success'])) es_fail('Connected, but could not subscribe to webhooks: ' . ($sub['error']['message'] ?? 'unknown'));

// 4. optional: register number with a platform-wide 2-step PIN
if (defined('META_ES_REGISTER_PIN') && META_ES_REGISTER_PIN !== '') {
    WhatsAppService::graph('POST', "$pid/register", [], ['messaging_product' => 'whatsapp', 'pin' => META_ES_REGISTER_PIN], $token);
}

$verify = (defined('META_WEBHOOK_VERIFY_TOKEN') && META_WEBHOOK_VERIFY_TOKEN !== '') ? META_WEBHOOK_VERIFY_TOKEN : bin2hex(random_bytes(12));
db()->run('INSERT INTO user_whatsapp_settings (user_id,phone_number_id,business_account_id,access_token,verify_token,phone_number,display_name,is_connected,last_verified)
           VALUES (?,?,?,?,?,?,?,1,NOW())
           ON DUPLICATE KEY UPDATE phone_number_id=VALUES(phone_number_id),business_account_id=VALUES(business_account_id),access_token=VALUES(access_token),
           verify_token=VALUES(verify_token),phone_number=VALUES(phone_number),display_name=VALUES(display_name),is_connected=1,last_verified=NOW()',
    [$uid, $pid, $waba, encryptSecret($token), $verify, $info['display_phone_number'] ?? '', $info['verified_name'] ?? '']);
logActivity('wa_connected', 'WhatsApp connected via Embedded Signup');
echo json_encode(['success' => true, 'phone' => $info['display_phone_number'] ?? '', 'name' => $info['verified_name'] ?? '']);
