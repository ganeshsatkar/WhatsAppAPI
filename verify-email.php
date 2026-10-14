<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['token'] ?? ''));
$user  = strlen($token) >= 32 ? db()->row('SELECT id FROM users WHERE verify_token = ?', [$token]) : null;

if ($user) {
    db()->run('UPDATE users SET is_verified = 1, verify_token = NULL WHERE id = ?', [$user['id']]);
    logActivity('email_verified', '', (int)$user['id']);
    redirect('/login.php?verified=1');
}
http_response_code(400);
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Verify Email — <?= e(APP_NAME) ?></title></head>
<body style="font-family:Arial,sans-serif;background:#080b12;color:#f0f4ff;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">
<div style="max-width:420px;text-align:center;padding:24px">
<h2>Link invalid or already used</h2>
<p style="color:#6b7a99">If you already verified your email, just sign in.</p>
<p><a href="/login.php" style="color:#25d366">Go to login →</a></p>
</div></body></html>
