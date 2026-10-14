<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wallet.php';

requireLogin();
$pageTitle = 'Wallet';
$uid = (int)currentUser()['id'];

// ── AJAX: recharge flow ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // Step 1 — browser asks for an order for a given amount
    if ($action === 'create_order') {
        $res = razorpayCreateRecharge($uid, (float)($_POST['amount'] ?? 0));
        if (!$res['ok']) { echo json_encode(['success' => false, 'error' => $res['error']]); exit; }
        echo json_encode([
            'success'      => true,
            'order_id'     => $res['order_id'],
            'amount_paise' => $res['amount_paise'],
            'key_id'       => $res['key_id'],
            'name'         => currentUser()['name'] ?? '',
            'email'        => currentUser()['email'] ?? '',
        ]);
        exit;
    }

    // Step 2 — Razorpay Checkout finished; verify signature, then credit the wallet
    if ($action === 'verify_payment') {
        $orderId   = trim($_POST['razorpay_order_id'] ?? '');
        $paymentId = trim($_POST['razorpay_payment_id'] ?? '');
        $signature = trim($_POST['razorpay_signature'] ?? '');

        $own = db()->row("SELECT id FROM wallet_transactions WHERE razorpay_order_id=? AND user_id=? AND type='credit'", [$orderId, $uid]);
        if (!$own) { echo json_encode(['success' => false, 'error' => 'Order not found for your account.']); exit; }
        if (!razorpayVerifyCheckoutSignature($orderId, $paymentId, $signature)) {
            echo json_encode(['success' => false, 'error' => 'Payment signature could not be verified. If money was deducted it will be credited automatically within a few minutes.']);
            exit;
        }
        $done = completeRecharge($orderId, $paymentId);
        if (!$done['ok']) { echo json_encode(['success' => false, 'error' => $done['error']]); exit; }
        logActivity('wallet_recharge', 'Recharged ' . inr($done['amount']) . ' (' . $paymentId . ')');
        echo json_encode(['success' => true, 'amount' => $done['amount'], 'balance' => walletBalance($uid)]);
        exit;
    }

    // Customer closed or failed the payment — keep history tidy
    if ($action === 'payment_failed') {
        $orderId = trim($_POST['razorpay_order_id'] ?? '');
        db()->run("UPDATE wallet_transactions SET status='failed', description='Wallet recharge (payment not completed)' WHERE razorpay_order_id=? AND user_id=? AND status='pending'", [$orderId, $uid]);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    exit;
}

// ── Page data ─────────────────────────────────────────────
$wallet   = db()->row('SELECT * FROM wallets WHERE user_id=?', [$uid]);
$balance  = walletBalance($uid);
$credited = (float)($wallet['total_credited'] ?? 0);
$debited  = (float)($wallet['total_debited'] ?? 0);

$prices = [
    'MARKETING'      => msgPrice($uid, 'MARKETING'),
    'UTILITY'        => msgPrice($uid, 'UTILITY'),
    'AUTHENTICATION' => msgPrice($uid, 'AUTHENTICATION'),
];
$lowBalance = $balance < ($prices['MARKETING'] * 20);

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$total   = db()->count('SELECT COUNT(*) FROM wallet_transactions WHERE user_id=?', [$uid]);
$pages   = max(1, (int)ceil($total / $perPage));
$txns    = db()->all('SELECT * FROM wallet_transactions WHERE user_id=? ORDER BY id DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)(($page - 1) * $perPage), [$uid]);

include __DIR__ . '/../includes/user_header.php';
?>

<?php if ($lowBalance): ?>
<div class="alert alert-danger">⚠ Low balance — you can send only about <?= number_format(affordableMessages($uid, 'MARKETING')) ?> marketing messages. Recharge to keep campaigns running.</div>
<?php endif; ?>

<div class="stats-grid mb2" style="grid-template-columns:repeat(3,1fr);">
  <div class="stat-card" style="--c:var(--accent);--c-dim:var(--accent-dim)"><div class="stat-icon">💰</div><div class="stat-value" id="balVal"><?= inr($balance) ?></div><div class="stat-label">Available Balance</div></div>
  <div class="stat-card" style="--c:var(--blue);--c-dim:var(--blue-dim)"><div class="stat-icon">⬆</div><div class="stat-value"><?= inr($credited) ?></div><div class="stat-label">Total Added</div></div>
  <div class="stat-card" style="--c:var(--orange);--c-dim:var(--orange-dim)"><div class="stat-icon">⬇</div><div class="stat-value"><?= inr($debited) ?></div><div class="stat-label">Total Spent</div></div>
</div>

<div class="g2 mb2">
  <div class="card">
    <div class="card-header"><div class="card-title">➕ Add Money</div></div>
    <div class="card-body">
      <?php if (!razorpayConfigured()): ?>
        <div class="alert alert-danger">Online recharge is not enabled yet. Please contact support to add balance.</div>
      <?php else: ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
        <?php foreach ([500, 1000, 2000, 5000, 10000] as $amt): ?>
        <button type="button" class="btn btn-secondary btn-sm" onclick="setAmt(<?= $amt ?>)">₹<?= number_format($amt) ?></button>
        <?php endforeach; ?>
      </div>
      <div class="form-group">
        <label class="form-label">Amount (₹) — min <?= number_format(WALLET_MIN_RECHARGE) ?>, max <?= number_format(WALLET_MAX_RECHARGE) ?></label>
        <input type="number" id="amt" class="form-control" min="<?= (int)WALLET_MIN_RECHARGE ?>" max="<?= (int)WALLET_MAX_RECHARGE ?>" step="1" placeholder="1000">
      </div>
      <button type="button" class="btn btn-primary" id="payBtn" style="width:100%;" onclick="startRecharge()">Pay securely with Razorpay</button>
      <div style="font-size:11.5px;color:var(--muted);margin-top:10px;">UPI, cards, netbanking and wallets accepted. Balance is added instantly after payment.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-title">🏷 Your Message Rates</div></div>
    <div class="card-body">
      <div class="table-wrap"><table>
        <thead><tr><th>Category</th><th>Per message</th><th>Messages for ₹1,000</th></tr></thead>
        <tbody>
        <?php foreach ($prices as $cat => $p): ?>
          <tr><td class="td-bold"><?= e(ucfirst(strtolower($cat))) ?></td><td><?= '₹' . rtrim(rtrim(number_format($p, 4), '0'), '.') ?></td><td><?= $p > 0 ? number_format(floor(1000 / $p)) : '—' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div style="font-size:11.5px;color:var(--muted);margin-top:10px;">Charged per message sent from a template (campaigns and template messages from Inbox). Normal replies inside the 24-hour window are not charged.</div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><div class="card-title">📋 Transaction History</div></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date</th><th>Description</th><th>Type</th><th>Amount</th><th>Balance after</th><th>Status</th></tr></thead>
      <tbody>
      <?php if (!$txns): ?>
        <tr><td colspan="6" style="text-align:center;padding:30px;color:var(--muted);">No transactions yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($txns as $t):
        $isCredit = $t['type'] === 'credit';
        $badge = ['success' => 'badge-green', 'pending' => 'badge-orange', 'failed' => 'badge-red'][$t['status']] ?? 'badge-gray'; ?>
        <tr>
          <td><?= e(date('d M Y, g:i A', strtotime($t['created_at']))) ?></td>
          <td><?= e($t['description'] ?? '') ?></td>
          <td><span class="badge <?= $isCredit ? 'badge-green' : 'badge-gray' ?>"><?= $isCredit ? 'Credit' : 'Debit' ?></span></td>
          <td class="td-bold" style="color:<?= $isCredit ? 'var(--accent)' : 'var(--text)' ?>;"><?= $isCredit ? '+' : '−' ?><?= inr((float)$t['amount']) ?></td>
          <td><?= $t['status'] === 'success' ? inr((float)$t['balance_after']) : '—' ?></td>
          <td><span class="badge <?= $badge ?>"><?= e(ucfirst($t['status'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
  <div style="display:flex;gap:8px;justify-content:center;padding:14px;">
    <?php if ($page > 1): ?><a class="btn btn-secondary btn-sm" href="?page=<?= $page - 1 ?>">← Newer</a><?php endif; ?>
    <span style="color:var(--muted);font-size:12.5px;align-self:center;">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn btn-secondary btn-sm" href="?page=<?= $page + 1 ?>">Older →</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php if (razorpayConfigured()): ?>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
function setAmt(v){ document.getElementById('amt').value = v; }

async function post(data){
  const body = new URLSearchParams(Object.assign({csrf_token: window.CSRF_TOKEN}, data));
  const r = await fetch(location.pathname, {method:'POST', body});
  return r.json();
}

async function startRecharge(){
  const amount = parseFloat(document.getElementById('amt').value);
  if (!amount || amount < <?= (int)WALLET_MIN_RECHARGE ?>) { toast('Minimum recharge is ₹<?= (int)WALLET_MIN_RECHARGE ?>', 'error'); return; }
  const btn = document.getElementById('payBtn'); btn.disabled = true; btn.textContent = 'Creating order…';
  try {
    const o = await post({action:'create_order', amount: amount});
    if (!o.success) { toast(o.error || 'Could not start payment', 'error'); return; }

    const rzp = new Razorpay({
      key: o.key_id, order_id: o.order_id, amount: o.amount_paise, currency: 'INR',
      name: '<?= e(APP_NAME) ?>', description: 'Wallet recharge',
      prefill: {name: o.name, email: o.email},
      theme: {color: '#25d366'},
      handler: async function(resp){
        const v = await post({action:'verify_payment',
          razorpay_order_id: resp.razorpay_order_id,
          razorpay_payment_id: resp.razorpay_payment_id,
          razorpay_signature: resp.razorpay_signature});
        if (v.success) { toast('✓ ₹' + v.amount + ' added to your wallet'); setTimeout(()=>location.reload(), 1200); }
        else { toast(v.error || 'Verification failed', 'error'); }
      },
      modal: { ondismiss: function(){ post({action:'payment_failed', razorpay_order_id: o.order_id}); } }
    });
    rzp.on('payment.failed', function(){ post({action:'payment_failed', razorpay_order_id: o.order_id}); });
    rzp.open();
  } finally { btn.disabled = false; btn.textContent = 'Pay securely with Razorpay'; }
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/user_footer.php'; ?>
