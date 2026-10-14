<?php
// /qr/{token} — track scan then redirect to WhatsApp
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$token = basename($_GET['t'] ?? $_SERVER['REQUEST_URI'] ?? '');
$token = preg_replace('/[^a-zA-Z0-9_-]/', '', $token);

$qr = $token ? db()->row('SELECT * FROM qr_codes WHERE qr_token = ? AND is_active = 1', [$token]) : null;

if (!$qr) {
    http_response_code(404);
    die('QR code not found or expired.');
}

// Detect device
$ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';
$device = 'desktop';
if (preg_match('/Mobile|Android|iPhone|iPad/i', $ua)) $device = 'mobile';
elseif (preg_match('/Tablet|iPad/i', $ua))              $device = 'tablet';

// Record scan
db()->run('INSERT INTO qr_scans (qr_id, user_id, ip_address, user_agent, device_type) VALUES (?,?,?,?,?)',
    [$qr['id'], $qr['user_id'], $_SERVER['REMOTE_ADDR'] ?? '', $ua, $device]);

// Increment count
db()->run('UPDATE qr_codes SET scan_count = scan_count + 1 WHERE id = ?', [$qr['id']]);

// Build WhatsApp link
$number  = preg_replace('/[^0-9]/', '', $qr['wa_number']);
$message = urlencode($qr['pre_filled_message'] ?? 'Hi');
$link    = "https://wa.me/$number?text=$message";

// Redirect
header("Location: $link", true, 302);
exit;
