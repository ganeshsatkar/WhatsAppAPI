<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
if (currentUser()['role'] !== 'superadmin') { header('Location: /user/dashboard.php'); exit; }
$pageTitle = 'Payments';

$totalRevenue  = db()->row("SELECT COALESCE(SUM(amount),0) as t FROM payments WHERE status='success'")['t'] ?? 0;
$totalPayments = db()->count("SELECT COUNT(*) FROM payments");
$thisMonth     = db()->row("SELECT COALESCE(SUM(amount),0) as t FROM payments WHERE status='success' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")['t'] ?? 0;

$payments = db()->all("SELECT py.*,u.name as user_name,u.email,p.name as plan_name
    FROM payments py
    JOIN users u ON u.id=py.user_id
    JOIN plans p ON p.id=py.plan_id
    ORDER BY py.created_at DESC LIMIT 100");

include __DIR__ . '/../includes/user_header.php';
?>
<div class="stats-grid mb2">
  <div class="stat-card" style="--c:var(--accent);--c-dim:var(--accent-dim)"><div class="stat-icon">💰</div><div class="stat-value">₹<?=number_format($totalRevenue)?></div><div class="stat-label">Total Revenue</div></div>
  <div class="stat-card" style="--c:var(--blue);--c-dim:var(--blue-dim)"><div class="stat-icon">📋</div><div class="stat-value"><?=$totalPayments?></div><div class="stat-label">Total Payments</div></div>
  <div class="stat-card" style="--c:var(--gold);--c-dim:var(--gold-dim)"><div class="stat-icon">📅</div><div class="stat-value">₹<?=number_format($thisMonth)?></div><div class="stat-label">This Month</div></div>
</div>

<div class="card">
  <div class="card-header"><div class="card-title">Payment History</div></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>User</th><th>Plan</th><th>Amount</th><th>Gateway</th><th>Transaction ID</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
        <?php if(empty($payments)):?>
        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--muted);">No payments recorded yet.</td></tr>
        <?php endif;?>
        <?php foreach($payments as $py):
          $sc=match($py['status']??'pending'){'success'=>'badge-green','failed'=>'badge-red','refunded'=>'badge-orange',default=>'badge-gray'};
        ?>
        <tr>
          <td><div class="td-bold"><?=e($py['user_name'])?></div><div style="font-size:11px;color:var(--muted);"><?=e($py['email'])?></div></td>
          <td><?=e($py['plan_name']??'—')?></td>
          <td class="td-bold" style="color:var(--accent);">₹<?=number_format($py['amount'])?></td>
          <td><?=e($py['gateway']??'—')?></td>
          <td style="font-size:11px;font-family:monospace;"><?=e(substr($py['transaction_id']??'—',0,20))?></td>
          <td><span class="badge <?=$sc?>"><?=ucfirst($py['status']??'pending')?></span></td>
          <td style="font-size:12px;color:var(--muted);"><?=date('M j, Y',strtotime($py['created_at']))?></td>
        </tr>
        <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../includes/user_footer.php';?>
