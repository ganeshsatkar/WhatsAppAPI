<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
$pageTitle = 'Contacts';
$uid  = currentUser()['id'];
$plan = currentPlan();

$msg = $err = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_contact') {
        $name  = sanitize($_POST['name'] ?? '');
        $phone = normalizePhone($_POST['phone'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $notes = sanitize($_POST['notes'] ?? '');
        if (!$name || !isValidPhone($phone)) { $err = 'Name and a valid phone number are required (add country code for non-Indian numbers).'; }
        elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $err = 'Invalid email address.'; }
        else {
            $pv = phoneVariants($phone);
            $exists = db()->row('SELECT id FROM contacts WHERE user_id = ? AND phone IN (' . implode(',', array_fill(0, count($pv), '?')) . ')', array_merge([$uid], $pv));
            if ($exists) { $err = 'Contact with this phone already exists.'; }
            elseif (($plan['max_contacts'] ?? -1) != -1 && db()->count('SELECT COUNT(*) FROM contacts WHERE user_id = ?', [$uid]) >= (int)$plan['max_contacts']) {
                $err = 'Contact limit of your plan (' . (int)$plan['max_contacts'] . ') reached. Upgrade your plan to add more.';
            }
            else {
                $newId = db()->insert('INSERT INTO contacts (user_id, name, phone, email, notes, source) VALUES (?,?,?,?,?,?)', [$uid, $name, $phone, $email, $notes, 'manual']);
                triggerAutomation($uid, 'new_contact', (int)$newId);
                $msg = 'Contact added successfully.';
            }
        }
    }

    if ($action === 'delete_contact') {
        $id = (int)$_POST['contact_id'];
        db()->run('DELETE FROM contacts WHERE id = ? AND user_id = ?', [$id, $uid]);
        $msg = 'Contact deleted.';
    }

    if ($action === 'csv_import' && !empty($_FILES['csv_file']['tmp_name'])) {
        $maxContacts = $plan['max_contacts'];
        $current     = db()->count('SELECT COUNT(*) FROM contacts WHERE user_id = ?', [$uid]);
        $imported = 0; $skipped = 0;
        $handle   = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $header   = fgetcsv($handle); // skip header row
        while (($row = fgetcsv($handle)) !== false) {
            if ($maxContacts !== -1 && ($current + $imported) >= $maxContacts) break;
            $phone = normalizePhone($row[1] ?? '');
            $name  = sanitize($row[0] ?? 'Unknown');
            if (!isValidPhone($phone)) { $skipped++; continue; }
            $pv = phoneVariants($phone);
            $exists = db()->row('SELECT id FROM contacts WHERE user_id = ? AND phone IN (' . implode(',', array_fill(0, count($pv), '?')) . ')', array_merge([$uid], $pv));
            if ($exists) { $skipped++; continue; }
            db()->run('INSERT INTO contacts (user_id, name, phone, email, source) VALUES (?,?,?,?,?)',
                [$uid, $name, $phone, sanitize($row[2] ?? ''), 'csv']);
            triggerAutomation($uid, 'new_contact', (int)db()->getPDO()->lastInsertId());
            $imported++;
        }
        fclose($handle);
        $msg = "CSV imported: $imported new contacts, $skipped skipped.";
    }
}

// Fetch contacts with search/filter
$search = sanitize($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = PER_PAGE;
$offset = ($page - 1) * $limit;

$where  = 'WHERE c.user_id = ?';
$params = [$uid];
if ($search) {
    $where  .= ' AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)';
    $s = "%$search%";
    $params = array_merge($params, [$s, $s, $s]);
}

$total    = db()->count("SELECT COUNT(*) FROM contacts c $where", $params);
$contacts = db()->all("SELECT c.*, GROUP_CONCAT(ct.tag SEPARATOR ',') as tags FROM contacts c LEFT JOIN contact_tags ct ON ct.contact_id = c.id $where GROUP BY c.id ORDER BY c.created_at DESC LIMIT $limit OFFSET $offset", $params);
$totalPages = max(1, ceil($total / $limit));

include __DIR__ . '/../includes/user_header.php';
?>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠ <?= e($err) ?></div><?php endif; ?>

<!-- Toolbar -->
<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; gap:12px; flex-wrap:wrap;">
  <form method="GET" style="display:flex; gap:10px; flex:1; max-width:400px;">
    <input type="search" name="q" class="form-control" placeholder="Search contacts…" value="<?= e($search) ?>" style="max-width:300px;">
    <button type="submit" class="btn btn-secondary">Search</button>
  </form>
  <div style="display:flex; gap:10px;">
    <button class="btn btn-secondary btn-sm" onclick="openModal('importModal')">📤 CSV Import</button>
    <button class="btn btn-primary" onclick="openModal('addModal')">+ Add Contact</button>
  </div>
</div>

<!-- Stats pills -->
<div style="display:flex; gap:10px; margin-bottom:20px; flex-wrap:wrap;">
  <div style="background:var(--surface); border:1px solid var(--border); border-radius:8px; padding:10px 16px; font-size:13px; color:var(--text2)">
    <strong style="color:var(--text)"><?= formatNumber($total) ?></strong> contacts
    <?php if ($plan['max_contacts'] !== -1): ?>
      · <span style="color:var(--muted)"><?= formatNumber($plan['max_contacts']) ?> limit</span>
    <?php endif; ?>
  </div>
</div>

<!-- Contacts Table -->
<div class="card">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th><input type="checkbox" id="selectAll" onclick="toggleAll(this)"></th>
          <th>Name</th>
          <th>Phone</th>
          <th>Email</th>
          <th>Tags</th>
          <th>Source</th>
          <th>Last Active</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($contacts)): ?>
        <tr><td colspan="8" style="text-align:center; padding:50px 20px; color:var(--muted);">No contacts found.</td></tr>
        <?php endif; ?>
        <?php foreach ($contacts as $c): ?>
        <tr>
          <td><input type="checkbox" class="row-check" value="<?= $c['id'] ?>"></td>
          <td>
            <div style="display:flex; align-items:center; gap:10px;">
              <div style="width:30px; height:30px; border-radius:50%; background:linear-gradient(135deg,var(--accent2),var(--accent)); display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; color:#fff;">
                <?= strtoupper(substr($c['name'], 0, 2)) ?>
              </div>
              <span style="color:var(--text); font-weight:500;"><?= e($c['name']) ?></span><?php if (isset($c['opt_in']) && (int)$c['opt_in'] === 0): ?> <span class="badge badge-red" title="Replied STOP – marketing messages are skipped">Opted out</span><?php endif; ?>
            </div>
          </td>
          <td><?= e($c['phone']) ?></td>
          <td><?= e($c['email'] ?? '—') ?></td>
          <td>
            <?php foreach (array_filter(explode(',', $c['tags'] ?? '')) as $tag): ?>
              <span class="badge badge-blue"><?= e($tag) ?></span>
            <?php endforeach; ?>
          </td>
          <td><span class="badge badge-gray"><?= ucfirst($c['source'] ?? 'manual') ?></span></td>
          <td style="font-size:12px; color:var(--muted);"><?= $c['last_message_at'] ? timeAgo($c['last_message_at']) : 'Never' ?></td>
          <td>
            <div style="display:flex; gap:6px;">
              <a href="inbox.php?phone=<?= urlencode($c['phone']) ?>" class="btn btn-secondary btn-sm" title="Chat">💬</a>
              <form method="POST" onsubmit="return confirm('Delete this contact?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_contact">
                <input type="hidden" name="contact_id" value="<?= $c['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">🗑</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <div style="display:flex; align-items:center; justify-content:space-between; padding-top:16px; border-top:1px solid var(--border); margin-top:16px;">
    <div style="font-size:13px; color:var(--muted)">Page <?= $page ?> of <?= $totalPages ?> · <?= $total ?> contacts</div>
    <div style="display:flex; gap:8px;">
      <?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>&q=<?= urlencode($search) ?>" class="btn btn-secondary btn-sm">← Prev</a><?php endif; ?>
      <?php if ($page < $totalPages): ?><a href="?page=<?= $page + 1 ?>&q=<?= urlencode($search) ?>" class="btn btn-secondary btn-sm">Next →</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Add Contact Modal -->
<div class="modal-overlay" id="addModal">
  <div class="modal">
    <div class="modal-title">Add New Contact</div>
    <div class="modal-sub">Manually add a contact to your CRM</div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_contact">
      <div class="form-group">
        <label class="form-label">Name *</label>
        <input type="text" name="name" class="form-control" placeholder="John Doe" required>
      </div>
      <div class="form-group">
        <label class="form-label">WhatsApp Number *</label>
        <input type="text" name="phone" class="form-control" placeholder="+91 98765 43210">
      </div>
      <div class="form-group">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" placeholder="john@example.com">
      </div>
      <div class="form-group">
        <label class="form-label">Notes</label>
        <textarea name="notes" class="form-control" rows="3" placeholder="Any notes…"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Add Contact</button>
      </div>
    </form>
  </div>
</div>

<!-- CSV Import Modal -->
<div class="modal-overlay" id="importModal">
  <div class="modal">
    <div class="modal-title">Import Contacts from CSV</div>
    <div class="modal-sub">CSV format: <code style="background:var(--surface2);padding:2px 6px;border-radius:4px;font-size:12px;">Name, Phone, Email</code> (first row = header)</div>
    <form method="POST" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="csv_import">
      <div class="form-group">
        <label class="form-label">CSV File</label>
        <input type="file" name="csv_file" class="form-control" accept=".csv" required>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('importModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Import</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleAll(cb) {
  document.querySelectorAll('.row-check').forEach(c => c.checked = cb.checked);
}
</script>

<?php include __DIR__ . '/../includes/user_footer.php'; ?>
