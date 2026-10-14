<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
$pageTitle = 'QR Code Generator';
$uid = currentUser()['id'];

$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_qr') {
        $name    = mb_substr(sanitize($_POST['name'] ?? ''), 0, 200);
        $waNum   = normalizePhone($_POST['wa_number'] ?? '');
        $preMsg  = mb_substr(sanitize($_POST['pre_filled_message'] ?? 'Hi'), 0, 500);
        $spId    = (int)($_POST['salesperson_id'] ?? 0);
        if ($spId && !db()->row('SELECT id FROM salespersons WHERE id=? AND user_id=?', [$spId, $uid])) $spId = 0;

        if (!$name || !isValidPhone($waNum)) { $err = 'Name and a valid WhatsApp number are required.'; }
        else {
            $token = generateToken(16);
            $shortUrl = QR_REDIRECT_URL . $token;
            db()->insert('INSERT INTO qr_codes (user_id, salesperson_id, name, wa_number, pre_filled_message, qr_token, short_url) VALUES (?,?,?,?,?,?,?)',
                [$uid, $spId ?: null, $name, $waNum, $preMsg, $token, $shortUrl]);
            $msg = 'QR Code created successfully.';
        }
    }

    if ($action === 'delete_qr') {
        $id = (int)$_POST['qr_id'];
        db()->run('DELETE FROM qr_codes WHERE id = ? AND user_id = ?', [$id, $uid]);
        $msg = 'QR Code deleted.';
    }

    if ($action === 'add_salesperson') {
        $spName  = sanitize($_POST['sp_name'] ?? '');
        $spEmail = sanitize($_POST['sp_email'] ?? '');
        $spPhone = sanitize($_POST['sp_phone'] ?? '');
        if (!$spName) { $err = 'Name is required.'; }
        else {
            do {
                $code = strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $spName), 0, 4) ?: 'SP') . random_int(10000, 99999);
            } while (db()->row('SELECT id FROM salespersons WHERE referral_code=?', [$code]));
            db()->insert('INSERT INTO salespersons (user_id, name, email, phone, referral_code) VALUES (?,?,?,?,?)',
                [$uid, $spName, $spEmail, $spPhone, $code]);
            $msg = 'Salesperson added.';
        }
    }
}

$qrCodes      = db()->all('SELECT qr.*, sp.name as sp_name FROM qr_codes qr LEFT JOIN salespersons sp ON sp.id = qr.salesperson_id WHERE qr.user_id = ? ORDER BY qr.created_at DESC', [$uid]);
$salespersons = db()->all('SELECT sp.*, COUNT(sl.id) as leads, COUNT(qr.id) as qr_count FROM salespersons sp LEFT JOIN sales_leads sl ON sl.salesperson_id = sp.id LEFT JOIN qr_codes qr ON qr.salesperson_id = sp.id WHERE sp.user_id = ? GROUP BY sp.id ORDER BY sp.created_at DESC', [$uid]);

include __DIR__ . '/../includes/user_header.php';
?>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠ <?= e($err) ?></div><?php endif; ?>

<div style="display:flex; gap:12px; margin-bottom:24px; justify-content:flex-end;">
  <button class="btn btn-secondary" onclick="openModal('addSpModal')">+ Add Salesperson</button>
  <button class="btn btn-primary" onclick="openModal('createQrModal')">+ Create QR Code</button>
</div>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:24px;">

  <!-- QR Codes -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">📱 QR Codes</div>
      <span class="badge badge-blue"><?= count($qrCodes) ?></span>
    </div>
    <?php if (empty($qrCodes)): ?>
      <div class="empty-state" style="padding:30px 0;">
        <div class="empty-icon">📱</div>
        <div class="empty-title">No QR codes yet</div>
        <div class="empty-sub">Create QR codes to track your lead sources</div>
      </div>
    <?php else: ?>
      <div style="display:flex; flex-direction:column; gap:12px;">
        <?php foreach ($qrCodes as $qr):
          $waLink = 'https://wa.me/' . preg_replace('/[^0-9]/', '', $qr['wa_number']) . '?text=' . urlencode($qr['pre_filled_message']);
        ?>
        <div style="background:var(--surface2); border:1px solid var(--border); border-radius:10px; padding:16px;">
          <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px;">
            <div style="flex:1;">
              <div style="font-weight:600; font-size:14px; margin-bottom:4px;"><?= e($qr['name']) ?></div>
              <div style="font-size:12px; color:var(--muted); margin-bottom:6px;"><?= e($qr['wa_number']) ?></div>
              <?php if ($qr['sp_name']): ?>
                <div style="font-size:11px; color:var(--blue);">👤 <?= e($qr['sp_name']) ?></div>
              <?php endif; ?>
              <div style="display:flex; gap:12px; margin-top:10px; font-size:12px; color:var(--text2);">
                <span>📊 <?= $qr['scan_count'] ?> scans</span>
                <span>👤 <?= $qr['lead_count'] ?> leads</span>
              </div>
            </div>
            <!-- QR Code display using API -->
            <div style="background:#fff; padding:6px; border-radius:8px;">
              <img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=<?= urlencode($waLink) ?>" width="80" height="80" alt="QR" style="display:block;">
            </div>
          </div>
          <div style="display:flex; gap:8px; margin-top:12px; flex-wrap:wrap;">
            <a href="<?= e($waLink) ?>" target="_blank" class="btn btn-secondary btn-sm">Test Link</a>
            <a href="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=<?= urlencode($waLink) ?>" download="qr-<?= $qr['qr_token'] ?>.png" class="btn btn-secondary btn-sm">⬇ Download</a>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this QR?')">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="delete_qr">
              <input type="hidden" name="qr_id" value="<?= $qr['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">🗑</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Salespersons -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">👥 Salesperson Tracking</div>
    </div>
    <?php if (empty($salespersons)): ?>
      <div class="empty-state" style="padding:30px 0;">
        <div class="empty-icon">👥</div>
        <div class="empty-title">No salespersons yet</div>
        <div class="empty-sub">Add salespersons to assign QR codes and track leads</div>
      </div>
    <?php else: ?>
      <div style="display:flex; flex-direction:column; gap:8px;">
        <?php foreach ($salespersons as $sp): ?>
        <div style="display:flex; align-items:center; gap:12px; padding:12px; background:var(--surface2); border-radius:8px; border:1px solid var(--border);">
          <div style="width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,var(--blue),var(--purple)); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:12px; color:#fff;">
            <?= strtoupper(substr($sp['name'], 0, 2)) ?>
          </div>
          <div style="flex:1;">
            <div style="font-weight:600; font-size:13.5px;"><?= e($sp['name']) ?></div>
            <div style="font-size:11px; color:var(--muted);">Code: <code style="background:var(--surface3);padding:1px 5px;border-radius:3px;"><?= e($sp['referral_code']) ?></code></div>
          </div>
          <div style="text-align:right; font-size:12px; color:var(--text2);">
            <div><?= $sp['leads'] ?> leads</div>
            <div style="color:var(--muted)"><?= $sp['qr_count'] ?> QRs</div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<!-- Create QR Modal -->
<div class="modal-overlay" id="createQrModal">
  <div class="modal">
    <div class="modal-title">Create QR Code</div>
    <div class="modal-sub">Generate a WhatsApp deep link QR code</div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create_qr">
      <div class="form-group">
        <label class="form-label">QR Code Name *</label>
        <input type="text" name="name" class="form-control" placeholder="e.g. Store Front QR" required>
      </div>
      <div class="form-group">
        <label class="form-label">WhatsApp Number *</label>
        <input type="text" name="wa_number" class="form-control" placeholder="+91 98765 43210" required>
      </div>
      <div class="form-group">
        <label class="form-label">Pre-filled Message</label>
        <input type="text" name="pre_filled_message" class="form-control" placeholder="Hi, I'm interested!" value="Hi, I'm interested!">
      </div>
      <div class="form-group">
        <label class="form-label">Assign to Salesperson</label>
        <select name="salesperson_id" class="form-control">
          <option value="">None</option>
          <?php foreach ($salespersons as $sp): ?>
          <option value="<?= $sp['id'] ?>"><?= e($sp['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('createQrModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create QR Code</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Salesperson Modal -->
<div class="modal-overlay" id="addSpModal">
  <div class="modal">
    <div class="modal-title">Add Salesperson</div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_salesperson">
      <div class="form-group">
        <label class="form-label">Name *</label>
        <input type="text" name="sp_name" class="form-control" placeholder="Sales Agent Name" required>
      </div>
      <div class="form-group">
        <label class="form-label">Email</label>
        <input type="email" name="sp_email" class="form-control" placeholder="agent@company.com">
      </div>
      <div class="form-group">
        <label class="form-label">Phone</label>
        <input type="text" name="sp_phone" class="form-control" placeholder="+91 99999 88888">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addSpModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Add Salesperson</button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/user_footer.php'; ?>
