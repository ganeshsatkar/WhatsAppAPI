<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wallet.php';
requireLogin();
$user = currentUser(); $uid = (int)$user['id'];
$pageTitle = 'Billing & Plan';
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user['role'] === 'user') {
    verifyCsrf();
    if (($_POST['action'] ?? '') === 'buy_plan') {
        [$ok, $m] = buyPlanFromWallet($uid, (int)($_POST['plan_id'] ?? 0));
        if ($ok) $msg = '✓ ' . $m; else $err = $m;
    }
}

$state   = subscriptionState($uid, true);
$balance = walletBalance($uid);
$plans   = db()->all('SELECT * FROM plans WHERE is_active=1 AND is_public=1 AND price>0 ORDER BY price ASC');
$history = db()->all("SELECT s.*, p.name AS plan_name, p.price FROM subscriptions s JOIN plans p ON p.id=s.plan_id WHERE s.user_id=? ORDER BY s.id DESC LIMIT 8", [$uid]);
$pays    = db()->all("SELECT py.*, p.name AS plan_name FROM payments py JOIN plans p ON p.id=py.plan_id WHERE py.user_id=? AND py.status='success' ORDER BY py.id DESC LIMIT 8", [$uid]);
$cur = $state['plan'];
$feat = function (array $p): array {
    $f = [];
    $f[] = ($p['max_contacts'] == -1 ? 'Unlimited' : number_format((int)$p['max_contacts'])) . ' contacts';
    $f[] = ($p['max_team_members'] == -1 ? 'Unlimited' : (int)$p['max_team_members']) . ' team member(s)';
    foreach (['chatbot_builder' => 'Chatbot builder', 'automation_workflows' => 'Automation workflows', 'advanced_analytics' => 'Advanced analytics', 'api_access' => 'Developer REST API + delivery callbacks'] as $k => $l)
        $f[] = ($p[$k] ? '✅ ' : '➖ ') . $l;
    return $f;
};
include __DIR__ . '/../includes/user_header.php';
?>
<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠ <?= e($err) ?></div><?php endif; ?>
<?php if (isset($_GET['locked']) && !$state['active']): ?><div class="alert alert-warning">That page needs an active plan. Activate one below.</div><?php endif; ?>

<?php if ($user['role'] === 'superadmin'): ?>
  <div class="alert alert-info">🛡️ Super admin accounts have every feature and never expire. Manage customer plans in <a href="/user/admin_users.php">All Users</a>, <a href="/user/admin_plans.php">Plans</a> and <a href="/user/admin_subscriptions.php">Subscriptions</a>.</div>
<?php else: ?>
<div class="card mb2">
  <div class="card-header"><div class="card-title">Your plan</div>
    <?php if ($state['active']): ?><span class="badge badge-green">● Active</span><?php else: ?><span class="badge badge-red">● Not active</span><?php endif; ?></div>
  <div class="card-body">
    <?php if ($state['active']): ?>
      <div style="font-size:22px;font-weight:700;"><?= e($cur['name']) ?><?= $state['is_trial'] ? ' <span class="badge badge-gold">Trial</span>' : '' ?></div>
      <div style="color:var(--text2);margin:6px 0 10px;">
        <?php if ($state['days_left'] === null): ?>No expiry<?php else: ?>Valid until <strong><?= e(fmtIst($state['expires_at'])) ?></strong> (IST) · <strong><?= (int)$state['days_left'] ?></strong> day(s) left<?php endif; ?>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:6px 18px;font-size:13px;color:var(--text2);"><?php foreach ($feat($cur) as $f): ?><span><?= e($f) ?></span><?php endforeach; ?></div>
    <?php else: ?>
      <div style="font-size:15px;"><?= $state['last'] ? 'Your plan ended on <strong>' . e(fmtIst($state['expires_at'], 'd M Y')) . '</strong>.' : 'Your account is created but no plan is active yet.' ?></div>
      <div style="color:var(--text2);margin-top:6px;font-size:13px;">Your contacts, templates, chats and wallet balance are safe. Incoming WhatsApp messages are still saved. Activate a plan to continue sending.</div>
    <?php endif; ?>
    <div style="margin-top:14px;font-size:13px;color:var(--text2);">💰 Wallet balance: <strong>₹<?= number_format($balance, 2) ?></strong> · <a href="/user/wallet.php">Recharge</a></div>
  </div>
</div>

<div class="g2 mb2">
<?php foreach ($plans as $p):
    $isCur = $state['active'] && $state['sub'] && (int)$state['sub']['plan_id'] === (int)$p['id'];
    $lower = $state['active'] && $cur && (float)$p['price'] < (float)$cur['price'] && !$isCur;
    $label = $isCur ? 'Renew (+' . (int)$p['duration_days'] . ' days)' : ($state['active'] && !$lower ? 'Upgrade now' : 'Activate');
?>
  <div class="card" style="<?= $isCur ? 'border:2px solid var(--accent);' : '' ?>">
    <div class="card-header"><div class="card-title"><?= e($p['name']) ?></div><?= $isCur ? '<span class="badge badge-green">Current</span>' : '' ?></div>
    <div class="card-body">
      <div style="font-size:30px;font-weight:800;">₹<?= number_format((float)$p['price']) ?><small style="font-size:13px;color:var(--text2);font-weight:400;"> / <?= (int)$p['duration_days'] ?> days</small></div>
      <ul style="list-style:none;padding:0;margin:14px 0;font-size:13px;line-height:1.9;color:var(--text2);"><?php foreach ($feat($p) as $f): ?><li><?= e($f) ?></li><?php endforeach; ?></ul>
      <p style="font-size:11.5px;color:var(--muted);margin-bottom:10px;">WhatsApp message charges are separate and are deducted from your wallet at the per-message rate.</p>
      <?php if ($lower): ?>
        <button class="btn btn-secondary" style="width:100%;" disabled>Available after your current plan ends</button>
      <?php else: ?>
      <form method="POST" onsubmit="return confirm('Pay ₹<?= number_format((float)$p['price']) ?> from your wallet?');">
        <?= csrfField() ?><input type="hidden" name="action" value="buy_plan"><input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
        <button class="btn btn-primary" style="width:100%;"><?= e($label) ?> — pay ₹<?= number_format((float)$p['price']) ?> from wallet</button>
      </form>
      <?php if ($balance < (float)$p['price']): ?><div style="font-size:12px;color:var(--danger);margin-top:6px;">Wallet has ₹<?= number_format($balance, 2) ?> — <a href="/user/wallet.php">recharge first</a>.</div><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
<p style="font-size:12.5px;color:var(--muted);margin-bottom:18px;">Want to test everything first? Ask us for a free trial — we activate it from the admin panel.</p>

<div class="g2">
  <div class="card"><div class="card-header"><div class="card-title">Plan history</div></div><div class="table-wrap"><table>
    <thead><tr><th>Plan</th><th>From</th><th>Until</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($history as $h): $live = $h['status'] === 'active' && ($h['expires_at'] === null || strtotime($h['expires_at'] . ' UTC') > time()); ?>
      <tr><td class="td-bold"><?= e($h['plan_name']) ?></td><td><?= e(fmtIst($h['started_at'], 'd M Y')) ?></td><td><?= $h['expires_at'] ? e(fmtIst($h['expires_at'], 'd M Y')) : 'No expiry' ?></td><td><?= $live ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-gray">' . e(ucfirst($h['status'] === 'active' ? 'expired' : $h['status'])) . '</span>' ?></td></tr>
    <?php endforeach; if (!$history): ?><tr><td colspan="4" style="text-align:center;color:var(--muted);padding:18px;">No plan yet</td></tr><?php endif; ?></tbody></table></div></div>
  <div class="card"><div class="card-header"><div class="card-title">Plan payments</div></div><div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Plan</th><th>Amount</th><th>Via</th></tr></thead><tbody>
    <?php foreach ($pays as $y): ?>
      <tr><td><?= e(fmtIst($y['created_at'], 'd M Y')) ?></td><td><?= e($y['plan_name']) ?></td><td>₹<?= number_format((float)$y['amount'], 2) ?></td><td><?= e($y['gateway']) ?></td></tr>
    <?php endforeach; if (!$pays): ?><tr><td colspan="4" style="text-align:center;color:var(--muted);padding:18px;">No payments yet</td></tr><?php endif; ?></tbody></table></div></div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/user_footer.php'; ?>
