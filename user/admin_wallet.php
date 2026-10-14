<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wallet.php';
requireLogin();
if (currentUser()['role'] !== 'superadmin') { header('Location: /user/dashboard.php'); exit; }
$pageTitle = 'Wallet Management';
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Credit / Debit user wallet
    if ($action === 'adjust_wallet') {
        $userId = (int)$_POST['user_id'];
        $amount = round((float)$_POST['amount'], 2);
        $note   = sanitize($_POST['note'] ?? '') ?: 'Admin adjustment';
        $type   = $_POST['type'] ?? 'credit';          // credit | debit
        $kind   = $_POST['kind'] ?? 'paid';            // paid = money really received · bonus = free promotional credit
        $mode   = in_array($_POST['pay_mode'] ?? '', ['upi','bank','cash','cheque','other'], true) ? $_POST['pay_mode'] : 'other';
        $ref    = mb_substr(trim(sanitize($_POST['pay_reference'] ?? '')), 0, 100);
        $adminId= (int)currentUser()['id'];
        $target = $userId ? db()->row("SELECT id,name FROM users WHERE id=? AND role='user'", [$userId]) : null;

        if (!$target || $amount <= 0) { $err = 'Invalid user or amount.'; }
        elseif ($amount > 1000000) { $err = 'Amount too large (max ₹10,00,000 per entry).'; }
        else {
            walletBalance($userId); // ensure exists
            if ($type === 'credit') {
                if ($kind === 'bonus') {
                    creditWallet($userId, $amount, 'Bonus credit · ' . $note, 'admin_bonus', null, null, ['created_by' => $adminId]);
                } else {
                    $d = 'Wallet recharge by admin (' . strtoupper($mode) . ($ref ? ' ' . $ref : '') . ') · ' . $note;
                    creditWallet($userId, $amount, $d, 'admin_credit', null, null, ['pay_mode' => $mode, 'pay_reference' => $ref ?: null, 'created_by' => $adminId]);
                }
                logActivity('admin_wallet_credit', "{$kind} ₹$amount to user #$userId ({$target['name']}) $mode $ref");
                notify($userId, $kind === 'bonus' ? 'Bonus credit added' : 'Wallet recharged', inr($amount) . ' added to your wallet.', 'success', '/user/wallet.php');
                $msg = "✓ Credited ₹$amount (" . ($kind === 'bonus' ? 'free bonus' : 'paid ' . strtoupper($mode)) . "). New balance: ₹" . walletBalance($userId);
            } else {
                $pdo = db()->getPDO();
                $st = $pdo->prepare('UPDATE wallets SET balance = balance - ?, total_debited = total_debited + ? WHERE user_id=? AND balance >= ?');
                $st->execute([$amount, $amount, $userId, $amount]);
                if ($st->rowCount() !== 1) { $err = 'Insufficient balance — user has ₹' . walletBalance($userId); }
                else {
                    $newBal = walletBalance($userId);
                    db()->insert('INSERT INTO wallet_transactions (user_id,type,amount,balance_after,description,ref_type,created_by,status) VALUES (?,?,?,?,?,?,?,?)',
                        [$userId,'debit',$amount,$newBal,'Admin debit · ' . $note,'admin_debit',$adminId,'success']);
                    logActivity('admin_wallet_debit', "₹$amount from user #$userId ({$target['name']}) $note");
                    $msg = "✓ Debited ₹$amount. New balance: ₹$newBal";
                }
            }
        }
    }

    // Set custom pricing for user
    if ($action === 'set_pricing') {
        $userId = (int)$_POST['price_user_id'];
        $cats   = ['MARKETING','UTILITY','AUTHENTICATION','SERVICE'];
        foreach ($cats as $cat) {
            $price = (float)($_POST['price_'.strtolower($cat)] ?? 0);
            if ($price > 0) {
                db()->run('INSERT INTO user_pricing (user_id,category,price_per_msg,updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE price_per_msg=VALUES(price_per_msg),updated_by=VALUES(updated_by)',
                    [$userId, $cat, $price, currentUser()['id']]);
            }
        }
        $msg = 'Custom pricing saved for user.';
    }

    // Update global pricing
    if ($action === 'global_pricing') {
        // Selling price is derived: Meta cost + markup %. Same price for every user (override per user below if needed).
        foreach (['MARKETING','UTILITY','AUTHENTICATION','SERVICE'] as $cat) {
            $k = strtolower($cat);
            if (!isset($_POST['mc_'.$k], $_POST['mk_'.$k])) continue;
            $mc = max(0, (float)$_POST['mc_'.$k]);
            $mk = min(500, max(0, (float)$_POST['mk_'.$k]));
            db()->run('UPDATE message_pricing SET meta_cost=?, markup_percent=?, price_per_msg=? WHERE category=?',
                [$mc, $mk, sellingPrice($mc, $mk), $cat]);
        }
        $msg = 'Global pricing updated — every user is now charged Meta cost + markup.';
    }
}

$users   = db()->all("SELECT u.id,u.name,u.email,up.company_name,COALESCE(w.balance,0) as balance,COALESCE(w.total_credited,0) as credited,COALESCE(w.total_debited,0) as debited FROM users u LEFT JOIN user_profiles up ON up.user_id=u.id LEFT JOIN wallets w ON w.user_id=u.id WHERE u.role='user' AND u.is_active=1 ORDER BY w.balance DESC");
$global  = db()->all('SELECT * FROM message_pricing ORDER BY price_per_msg DESC');
$totalBal= db()->row('SELECT COALESCE(SUM(balance),0) as t FROM wallets')['t'] ?? 0;
$totalRev= db()->row("SELECT COALESCE(SUM(amount),0) as t FROM wallet_transactions WHERE type='credit' AND ref_type IN ('recharge','admin_credit') AND status='success'")['t'] ?? 0;
$txnCount= db()->count('SELECT COUNT(*) FROM wallet_transactions');
// Refunds of failed API messages (ref_type api_refund) are subtracted so margin stays honest.
$m = db()->row("SELECT COALESCE(SUM(CASE WHEN type='debit' THEN amount ELSE -amount END),0) AS sold, COALESCE(SUM(CASE WHEN type='debit' THEN meta_cost ELSE -meta_cost END),0) AS metabill FROM wallet_transactions WHERE status='success' AND meta_cost IS NOT NULL AND (type='debit' OR ref_type IN ('api_refund','campaign_refund'))");
$msgSold = (float)$m['sold']; $metaOwed = (float)$m['metabill']; $margin = $msgSold - $metaOwed;

include __DIR__ . '/../includes/user_header.php';
?>

<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠ <?= e($err) ?></div><?php endif; ?>

<div class="stats-grid mb2" style="grid-template-columns:repeat(3,1fr);">
  <div class="stat-card" style="--c:var(--accent);--c-dim:var(--accent-dim)"><div class="stat-icon">💰</div><div class="stat-value">₹<?= number_format($totalBal, 0) ?></div><div class="stat-label">Total Wallet Balance</div></div>
  <div class="stat-card" style="--c:var(--gold);--c-dim:var(--gold-dim)"><div class="stat-icon">₹</div><div class="stat-value">₹<?= number_format($totalRev, 0) ?></div><div class="stat-label">Total Revenue</div></div>
  <div class="stat-card" style="--c:var(--blue);--c-dim:var(--blue-dim)"><div class="stat-icon">📋</div><div class="stat-value"><?= $txnCount ?></div><div class="stat-label">Total Transactions</div></div>
</div>

<div style="text-align:right;margin-bottom:8px;"><a href="/user/admin_profit.php" class="btn btn-secondary btn-sm">📈 Full profit report (per user / category) →</a></div>
<div class="stats-grid mb2" style="grid-template-columns:repeat(3,1fr);">
  <div class="stat-card" style="--c:var(--accent);--c-dim:var(--accent-dim)"><div class="stat-icon">📤</div><div class="stat-value">₹<?= number_format($msgSold, 2) ?></div><div class="stat-label">Charged to users (messages)</div></div>
  <div class="stat-card" style="--c:var(--danger);--c-dim:var(--danger-dim)"><div class="stat-icon">🧾</div><div class="stat-value">₹<?= number_format($metaOwed, 2) ?></div><div class="stat-label">Meta bill to pay (estimate)</div></div>
  <div class="stat-card" style="--c:var(--gold);--c-dim:var(--gold-dim)"><div class="stat-icon">📈</div><div class="stat-value">₹<?= number_format($margin, 2) ?></div><div class="stat-label">Your margin on messages</div></div>
</div>

<div class="g2 mb2">
  <!-- Adjust wallet -->
  <div class="card">
    <div class="card-header"><div class="card-title">💳 Credit / Debit User Wallet</div></div>
    <div class="card-body">
      <form method="POST">
        <?= csrfField() ?><input type="hidden" name="action" value="adjust_wallet">
        <div class="form-group">
          <label class="form-label">Select User</label>
          <select name="user_id" class="form-control" required>
            <option value="">— Select user —</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= $u['id'] ?>"><?= e($u['name']) ?> (<?= e($u['email']) ?>) — ₹<?= number_format($u['balance'],2) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="g2">
          <div class="form-group">
            <label class="form-label">Type</label>
            <select name="type" class="form-control" id="adjType" onchange="adjToggle()">
              <option value="credit">✅ Credit</option>
              <option value="debit">❌ Debit</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Amount (₹)</label>
            <input type="number" name="amount" class="form-control" placeholder="500" min="1" step="0.01" required>
          </div>
        </div>
        <div id="adjPaid">
          <div class="form-group">
            <label class="form-label">Credit kind</label>
            <select name="kind" class="form-control" id="adjKind" onchange="adjToggle()">
              <option value="paid">💵 Paid — customer paid you (counts as revenue)</option>
              <option value="bonus">🎁 Free bonus / promo (not revenue)</option>
            </select>
          </div>
          <div class="g2" id="adjPayRow">
            <div class="form-group"><label class="form-label">Received via</label>
              <select name="pay_mode" class="form-control"><option value="upi">UPI</option><option value="bank">Bank transfer</option><option value="cash">Cash</option><option value="cheque">Cheque</option><option value="other">Other</option></select></div>
            <div class="form-group"><label class="form-label">UTR / receipt no.</label><input type="text" name="pay_reference" class="form-control" maxlength="100" placeholder="optional"></div>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Note</label>
          <input type="text" name="note" class="form-control" placeholder="Reason / remark" maxlength="150">
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;">Apply to Wallet</button>
      </form>
    </div>
  </div>

  <!-- Global + custom pricing -->
  <div class="card">
    <div class="card-header"><div class="card-title">⚙️ Global Message Pricing</div></div>
    <div class="card-body">
      <form method="POST">
        <?= csrfField() ?><input type="hidden" name="action" value="global_pricing">
        <p style="font-size:12px;color:var(--text2);margin-bottom:12px;">Enter what <strong>Meta charges you</strong> and your <strong>markup %</strong>. Users are charged the selling price, the same for everyone.</p>
        <?php
        $icons = ['MARKETING'=>'📢','UTILITY'=>'⚙️','AUTHENTICATION'=>'🔐','SERVICE'=>'💬'];
        foreach ($global as $g): $k = strtolower($g['category']);
        ?>
        <div class="form-group">
          <label class="form-label"><?= $icons[$g['category']]??' ' ?> <?= ucfirst($k) ?> — selling price now <strong>₹<?= rtrim(rtrim(number_format((float)$g['price_per_msg'],4,'.',''),'0'),'.') ?: '0' ?></strong></label>
          <div class="g2">
            <input type="number" name="mc_<?= $k ?>" class="form-control" value="<?= $g['meta_cost'] ?>" step="0.0001" min="0" placeholder="Meta cost ₹">
            <input type="number" name="mk_<?= $k ?>" class="form-control" value="<?= $g['markup_percent'] ?>" step="0.01" min="0" max="500" placeholder="Markup %">
          </div>
        </div>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-primary" style="width:100%;">Update Global Pricing</button>
      </form>
    </div>
  </div>
</div>

<!-- Custom pricing per user -->
<div class="card mb2">
  <div class="card-header"><div class="card-title">🎯 Set Custom Pricing Per User</div><span class="badge badge-purple">Admin override</span></div>
  <div class="card-body">
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="set_pricing">
      <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr 120px;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <div class="form-group" style="margin:0;">
          <label class="form-label">User</label>
          <select name="price_user_id" class="form-control" required>
            <option value="">— Select —</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= $u['id'] ?>"><?= e($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php foreach ($global as $g): ?>
        <div class="form-group" style="margin:0;">
          <label class="form-label"><?= $icons[$g['category']]??' ' ?> <?= substr(ucfirst(strtolower($g['category'])),0,5) ?></label>
          <input type="number" name="price_<?= strtolower($g['category']) ?>" class="form-control" placeholder="<?= $g['price_per_msg'] ?>" step="0.01" min="0.01">
        </div>
        <?php endforeach; ?>
        <div style="margin:0;"><button type="submit" class="btn btn-primary" style="width:100%;padding:10px;">Save</button></div>
      </div>
    </form>
  </div>
</div>

<!-- All user wallets -->
<div class="card">
  <div class="card-header"><div class="card-title">👥 All User Wallets</div><span class="badge badge-green"><?= count($users) ?></span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>User</th><th>Balance</th><th>Credited</th><th>Spent</th><th>Custom Rate?</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach ($users as $u):
          $hasCustom = db()->count('SELECT COUNT(*) FROM user_pricing WHERE user_id=?', [$u['id']]) > 0;
        ?>
        <tr>
          <td><div class="td-bold"><?= e($u['name']) ?></div><div style="font-size:11px;color:var(--muted);"><?= e($u['email']) ?></div></td>
          <td style="font-weight:700;color:<?= $u['balance']>0?'var(--accent)':'var(--muted)' ?>;">₹<?= number_format($u['balance'],2) ?></td>
          <td style="color:var(--accent);">₹<?= number_format($u['credited'],2) ?></td>
          <td style="color:var(--danger);">₹<?= number_format($u['debited'],2) ?></td>
          <td><?= $hasCustom ? '<span class="badge badge-purple">Yes</span>' : '<span class="badge badge-gray">No</span>' ?></td>
          <td>
            <button class="btn btn-primary btn-sm" onclick="quickCredit(<?= $u['id'] ?>,'<?= e(addslashes($u['name'])) ?>')">+ Credit</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Quick credit modal -->
<div class="modal-overlay" id="qcModal">
  <div class="modal" style="max-width:360px;">
    <div class="modal-title">Quick Credit</div>
    <div class="modal-sub" id="qcName"></div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="adjust_wallet"><input type="hidden" name="type" value="credit"><input type="hidden" name="user_id" id="qcUid">
      <div class="form-group"><label class="form-label">Amount (₹)</label><input type="number" name="amount" class="form-control" placeholder="500" min="1" required></div>
      <div class="form-group"><label class="form-label">Kind</label><select name="kind" class="form-control"><option value="paid">💵 Paid (revenue)</option><option value="bonus">🎁 Free bonus</option></select></div>
      <div class="g2"><div class="form-group"><label class="form-label">Via</label><select name="pay_mode" class="form-control"><option value="upi">UPI</option><option value="bank">Bank</option><option value="cash">Cash</option><option value="cheque">Cheque</option><option value="other">Other</option></select></div>
      <div class="form-group"><label class="form-label">UTR / receipt</label><input type="text" name="pay_reference" class="form-control" maxlength="100"></div></div>
      <div class="form-group"><label class="form-label">Note</label><input type="text" name="note" class="form-control" placeholder="optional" maxlength="150"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('qcModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">✅ Credit Now</button>
      </div>
    </form>
  </div>
</div>
<script>
function adjToggle(){
  var d=document.getElementById('adjType').value==='debit';
  document.getElementById('adjPaid').style.display=d?'none':'block';
  document.getElementById('adjPayRow').style.display=(document.getElementById('adjKind').value==='paid')?'grid':'none';
}
function quickCredit(uid, name) {
  document.getElementById('qcUid').value = uid;
  document.getElementById('qcName').textContent = 'Adding credit to: ' + name;
  openModal('qcModal');
}
</script>

<?php include __DIR__ . '/../includes/user_footer.php'; ?>
