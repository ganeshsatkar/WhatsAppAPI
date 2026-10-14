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

    if ($action === 'edit_contact') {
        $id    = (int)($_POST['contact_id'] ?? 0);
        $cur   = db()->row('SELECT * FROM contacts WHERE id = ? AND user_id = ?', [$id, $uid]);
        $name  = mb_substr(sanitize($_POST['name'] ?? ''), 0, 150);
        $phone = normalizePhone($_POST['phone'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $notes = mb_substr(sanitize($_POST['notes'] ?? ''), 0, 2000);
        $optIn = isset($_POST['opt_in']) ? 1 : 0;

        // Custom fields: up to 20 "key → value" pairs (keys: letters, numbers, space, _)
        $custom = [];
        foreach ((array)($_POST['cf_key'] ?? []) as $i => $k) {
            $k = trim(preg_replace('/[^\p{L}\p{N} _-]/u', '', (string)$k));
            $v = mb_substr(trim(sanitize((string)($_POST['cf_val'][$i] ?? ''))), 0, 255);
            if ($k !== '' && $v !== '' && count($custom) < 20) $custom[mb_substr($k, 0, 40)] = $v;
        }
        $tags = array_values(array_unique(array_filter(array_map(fn($t) => mb_substr(strtolower(trim(sanitize($t))), 0, 100), explode(',', (string)($_POST['tags'] ?? ''))))));

        if (!$cur) { $err = 'Contact not found.'; }
        elseif (!$name || !isValidPhone($phone)) { $err = 'Name and a valid phone number are required.'; }
        elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $err = 'Invalid email address.'; }
        else {
            $pv = phoneVariants($phone);
            $dup = db()->row('SELECT id FROM contacts WHERE user_id = ? AND id <> ? AND phone IN (' . implode(',', array_fill(0, count($pv), '?')) . ')', array_merge([$uid, $id], $pv));
            if ($dup) { $err = 'Another contact already has this phone number.'; }
            else {
                db()->run('UPDATE contacts SET name=?, phone=?, email=?, notes=?, opt_in=?, custom_fields=? WHERE id=? AND user_id=?',
                    [$name, $phone, $email ?: null, $notes ?: null, $optIn, $custom ? json_encode($custom, JSON_UNESCAPED_UNICODE) : null, $id, $uid]);
                if ($phone !== $cur['phone']) db()->run('UPDATE conversations SET phone=? WHERE contact_id=? AND user_id=?', [$phone, $id, $uid]);
                // Tags: keep exactly the list given; new tags fire "contact tagged" automations
                $old = array_column(db()->all('SELECT tag FROM contact_tags WHERE contact_id=?', [$id]), 'tag');
                foreach (array_diff($old, $tags) as $t) db()->run('DELETE FROM contact_tags WHERE contact_id=? AND tag=?', [$id, $t]);
                foreach (array_diff($tags, $old) as $t) {
                    db()->run('INSERT IGNORE INTO contact_tags (user_id, contact_id, tag) VALUES (?,?,?)', [$uid, $id, $t]);
                    triggerAutomation($uid, 'contact_tagged', $id);
                }
                $msg = 'Contact updated.';
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
        $header   = fgetcsv($handle) ?: []; // header row; columns after Name, Phone, Email become custom fields
        $extraCols = [];
        foreach (array_slice($header, 3, 20, true) as $ci => $h) {
            $h = trim(preg_replace('/[^\p{L}\p{N} _-]/u', '', (string)$h));
            if ($h !== '') $extraCols[$ci] = mb_substr($h, 0, 40);
        }
        while (($row = fgetcsv($handle)) !== false) {
            if ($maxContacts !== -1 && ($current + $imported) >= $maxContacts) break;
            $phone = normalizePhone($row[1] ?? '');
            $name  = sanitize($row[0] ?? 'Unknown');
            if (!isValidPhone($phone)) { $skipped++; continue; }
            $pv = phoneVariants($phone);
            $exists = db()->row('SELECT id FROM contacts WHERE user_id = ? AND phone IN (' . implode(',', array_fill(0, count($pv), '?')) . ')', array_merge([$uid], $pv));
            if ($exists) { $skipped++; continue; }
            $custom = [];
            foreach ($extraCols as $ci => $h) { $v = mb_substr(trim(sanitize((string)($row[$ci] ?? ''))), 0, 255); if ($v !== '') $custom[$h] = $v; }
            $em = sanitize($row[2] ?? '');
            db()->run('INSERT INTO contacts (user_id, name, phone, email, source, custom_fields) VALUES (?,?,?,?,?,?)',
                [$uid, $name ?: 'Customer', $phone, filter_var($em, FILTER_VALIDATE_EMAIL) ? $em : null, 'csv', $custom ? json_encode($custom, JSON_UNESCAPED_UNICODE) : null]);
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
              <span style="color:var(--text); font-weight:500; cursor:pointer; text-decoration:underline dotted var(--muted);" title="Edit contact" onclick="this.closest('tr').querySelector('button[title=Edit]').click()"><?= e($c['name']) ?></span><?php if (isset($c['opt_in']) && (int)$c['opt_in'] === 0): ?> <span class="badge badge-red" title="Replied STOP – marketing messages are skipped">Opted out</span><?php endif; ?>
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
              <button type="button" class="btn btn-secondary btn-sm" title="Edit" onclick="editContact(this)"
                data-c="<?= e(json_encode(['id' => (int)$c['id'], 'name' => $c['name'], 'phone' => $c['phone'], 'email' => $c['email'], 'notes' => $c['notes'],
                    'opt_in' => (int)$c['opt_in'], 'tags' => $c['tags'] ?? '', 'custom' => json_decode($c['custom_fields'] ?? '', true) ?: new stdClass()], JSON_UNESCAPED_UNICODE)) ?>">✏️</button>
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
    <div class="modal-sub">CSV format: <code style="background:var(--surface2);padding:2px 6px;border-radius:4px;font-size:12px;">Name, Phone, Email</code> (first row = header). Extra columns (e.g. <code style="background:var(--surface2);padding:2px 6px;border-radius:4px;font-size:12px;">City, Birthday</code>) are saved as custom fields.</div>
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

<!-- Edit Contact Modal -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-title">Edit Contact</div>
    <div class="modal-sub">Update details, tags and custom fields</div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="edit_contact">
      <input type="hidden" name="contact_id" id="ecId">
      <div class="form-group"><label class="form-label">Name *</label><input type="text" name="name" id="ecName" class="form-control" required maxlength="150"></div>
      <div class="form-group"><label class="form-label">WhatsApp Number *</label><input type="tel" name="phone" id="ecPhone" class="form-control" required></div>
      <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" id="ecEmail" class="form-control"></div>
      <div class="form-group"><label class="form-label">Tags <span style="text-transform:none;letter-spacing:0;">(comma separated)</span></label><input type="text" name="tags" id="ecTags" class="form-control" placeholder="vip, diwali-2026"></div>
      <div class="form-group"><label class="form-label">Notes</label><textarea name="notes" id="ecNotes" class="form-control" rows="2"></textarea></div>
      <div class="form-group">
        <label class="form-label">Custom fields</label>
        <div id="ecCustom" style="display:flex;flex-direction:column;gap:8px;"></div>
        <button type="button" class="btn btn-secondary btn-sm" style="margin-top:8px;" onclick="addCf('','')">+ Add field</button>
      </div>
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text2);cursor:pointer;margin-bottom:6px;">
        <input type="checkbox" name="opt_in" id="ecOpt" style="accent-color:var(--accent);width:18px;height:18px;"> Opted in to marketing messages
      </label>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleAll(cb) {
  document.querySelectorAll('.row-check').forEach(c => c.checked = cb.checked);
}
function addCf(k, v) {
  const row = document.createElement('div');
  row.style.cssText = 'display:grid;grid-template-columns:1fr 1.4fr auto;gap:6px;';
  row.innerHTML = '<input type="text" name="cf_key[]" class="form-control" placeholder="Field (e.g. City)" maxlength="40">'
                + '<input type="text" name="cf_val[]" class="form-control" placeholder="Value" maxlength="255">'
                + '<button type="button" class="btn btn-secondary btn-sm" aria-label="Remove field">✕</button>';
  row.children[0].value = k; row.children[1].value = v;          // values set as properties — never parsed as HTML
  row.children[2].onclick = () => row.remove();
  document.getElementById('ecCustom').appendChild(row);
}
function editContact(btn) {
  const c = JSON.parse(btn.dataset.c);
  document.getElementById('ecId').value    = c.id;
  document.getElementById('ecName').value  = c.name || '';
  document.getElementById('ecPhone').value = c.phone || '';
  document.getElementById('ecEmail').value = c.email || '';
  document.getElementById('ecNotes').value = c.notes || '';
  document.getElementById('ecTags').value  = (c.tags || '').split(',').filter(Boolean).join(', ');
  document.getElementById('ecOpt').checked = c.opt_in == 1;
  const box = document.getElementById('ecCustom'); box.innerHTML = '';
  Object.entries(c.custom || {}).forEach(([k, v]) => addCf(k, String(v)));
  if (!box.children.length) addCf('', '');
  openModal('editModal');
}
</script>

<?php include __DIR__ . '/../includes/user_footer.php'; ?>
