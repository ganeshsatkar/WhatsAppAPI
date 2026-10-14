<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$pageTitle = 'Contact Segments';
$uid = currentUser()['id'];
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_list') {
        $name  = sanitize($_POST['name'] ?? '');
        $desc  = sanitize($_POST['description'] ?? '');
        $color = sanitize($_POST['color'] ?? '#25d366');
        // Sanitize color — only allow valid hex
        if (!preg_match('/^#[0-9a-fA-F]{3,7}$/', $color)) $color = '#25d366';
        if (!$name) { $err = 'List name required.'; }
        else {
            try {
                db()->insert('INSERT INTO contact_lists (user_id,name,description,color) VALUES (?,?,?,?)', [$uid,$name,$desc,$color]);
                $msg = "List '$name' created.";
            } catch (\Throwable $e) {
                error_log('create_list: ' . $e->getMessage());
                $err = 'Could not create the list. Please try again.';
            }
        }
    }

    if ($action === 'delete_list') {
        $id = (int)$_POST['list_id'];
        db()->run('DELETE FROM contact_lists WHERE id=? AND user_id=?', [$id, $uid]);
        $msg = 'List deleted.';
    }

    // Every list/contact id posted must belong to this account
    $ownList    = fn(int $id) => (bool)db()->row('SELECT id FROM contact_lists WHERE id=? AND user_id=?', [$id, $uid]);
    $ownContact = fn(int $id) => (bool)db()->row('SELECT id FROM contacts WHERE id=? AND user_id=?', [$id, $uid]);
    $tagAdded   = function (int $cid, string $tag) use ($uid) {
        if (db()->run('INSERT IGNORE INTO contact_tags (user_id,contact_id,tag) VALUES (?,?,?)', [$uid, $cid, $tag])->rowCount()) {
            triggerAutomation($uid, 'contact_tagged', $cid);
        }
    };
    if (in_array($action, ['add_to_list', 'remove_from_list'], true) && !$ownList((int)($_POST['list_id'] ?? 0))) {
        $err = 'List not found.'; $action = '';
    }

    if ($action === 'add_to_list') {
        $listId = (int)$_POST['list_id'];
        $cids   = array_map('intval', (array)($_POST['contact_ids'] ?? []));
        $added  = 0;
        foreach ($cids as $cid) {
            $exists = db()->count('SELECT COUNT(*) FROM contacts WHERE id=? AND user_id=?', [$cid, $uid]);
            if ($exists) {
                try {
                    db()->run('INSERT IGNORE INTO contact_list_members (list_id,contact_id) VALUES (?,?)', [$listId,$cid]);
                    $added++;
                } catch (\Throwable $e) {}
            }
        }
        db()->run('UPDATE contact_lists SET contact_count=(SELECT COUNT(*) FROM contact_list_members WHERE list_id=?) WHERE id=?', [$listId,$listId]);
        $msg = "Added $added contacts to list.";
    }

    if ($action === 'remove_from_list') {
        $listId = (int)$_POST['list_id'];
        $cid    = (int)$_POST['contact_id'];
        db()->run('DELETE FROM contact_list_members WHERE list_id=? AND contact_id=?', [$listId,$cid]);
        db()->run('UPDATE contact_lists SET contact_count=(SELECT COUNT(*) FROM contact_list_members WHERE list_id=?) WHERE id=?', [$listId,$listId]);
        $msg = 'Removed from list.';
    }

    if ($action === 'add_tag') {
        $cid = (int)$_POST['contact_id'];
        $tag = mb_substr(strtolower(trim(sanitize($_POST['tag'] ?? ''))), 0, 100);
        if ($tag && $ownContact($cid)) {
            $tagAdded($cid, $tag);
            $msg = "Tag '$tag' added.";
        }
    }

    if ($action === 'bulk_tag') {
        $cids = array_map('intval', (array)($_POST['contact_ids'] ?? []));
        $tag  = mb_substr(strtolower(trim(sanitize($_POST['bulk_tag_value'] ?? ''))), 0, 100);
        $n = 0;
        if ($tag && !empty($cids)) {
            foreach ($cids as $cid) {
                if ($ownContact($cid)) { $tagAdded($cid, $tag); $n++; }
            }
            $msg = "Tag '$tag' added to $n contacts.";
        }
    }
}

// View specific list
$viewListId = (int)($_GET['list'] ?? 0);
$viewList   = $viewListId ? db()->row('SELECT * FROM contact_lists WHERE id=? AND user_id=?', [$viewListId, $uid]) : null;

$lists = db()->all('SELECT cl.*,(SELECT COUNT(*) FROM contact_list_members WHERE list_id=cl.id) as cnt FROM contact_lists cl WHERE cl.user_id=? ORDER BY cl.created_at DESC', [$uid]);

// All contacts with tags
$search = sanitize($_GET['q'] ?? '');
$tag    = sanitize($_GET['tag'] ?? '');
$where  = 'WHERE c.user_id=?'; $params = [$uid];
if ($search) { $where .= ' AND (c.name LIKE ? OR c.phone LIKE ?)'; $s="%$search%"; $params[]=($s); $params[]=($s); }
if ($tag)    { $where .= ' AND EXISTS (SELECT 1 FROM contact_tags ct WHERE ct.contact_id=c.id AND ct.tag=?)'; $params[]=$tag; }

$contacts = db()->all("SELECT c.*,GROUP_CONCAT(DISTINCT ct.tag SEPARATOR ',') as tags FROM contacts c LEFT JOIN contact_tags ct ON ct.contact_id=c.id $where GROUP BY c.id ORDER BY c.created_at DESC LIMIT 100", $params);
$allTags  = db()->all('SELECT DISTINCT ct.tag, COUNT(*) as cnt FROM contact_tags ct JOIN contacts c ON c.id=ct.contact_id WHERE c.user_id=? GROUP BY ct.tag ORDER BY cnt DESC LIMIT 30', [$uid]);

include __DIR__ . '/../includes/user_header.php';
?>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠ <?= e($err) ?></div><?php endif; ?>

<div class="g21 mb2">

  <!-- Lists panel -->
  <div>
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
      <div style="font-size:15px;font-weight:800;color:#fff;">Contact Lists</div>
      <button class="btn btn-primary btn-sm" onclick="openModal('createListModal')">+ New List</button>
    </div>

    <!-- All contacts summary -->
    <a href="?list=0" style="display:flex;align-items:center;gap:12px;background:<?= !$viewListId?'var(--accent-dim)':'var(--surface)' ?>;border:1px solid <?= !$viewListId?'var(--border3)':'var(--border)' ?>;border-radius:10px;padding:12px 14px;margin-bottom:8px;text-decoration:none;cursor:pointer;transition:all .2s;">
      <div style="width:34px;height:34px;border-radius:8px;background:var(--surface3);display:flex;align-items:center;justify-content:center;font-size:16px;">👥</div>
      <div style="flex:1;"><div style="font-weight:700;color:#fff;font-size:13px;">All Contacts</div><div style="font-size:11px;color:var(--text2);"><?= db()->count('SELECT COUNT(*) FROM contacts WHERE user_id=?',[$uid]) ?> total</div></div>
    </a>

    <?php foreach ($lists as $l): ?>
    <a href="?list=<?= $l['id'] ?>" style="display:flex;align-items:center;gap:12px;background:<?= $viewListId==$l['id']?'var(--accent-dim)':'var(--surface)' ?>;border:1px solid <?= $viewListId==$l['id']?'var(--border3)':'var(--border)' ?>;border-radius:10px;padding:12px 14px;margin-bottom:8px;text-decoration:none;transition:all .2s;">
      <div style="width:34px;height:34px;border-radius:8px;background:<?= e($l['color']) ?>22;border:1px solid <?= e($l['color']) ?>44;display:flex;align-items:center;justify-content:center;font-size:16px;">📋</div>
      <div style="flex:1;min-width:0;">
        <div style="font-weight:700;color:#fff;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($l['name']) ?></div>
        <div style="font-size:11px;color:var(--text2);"><?= $l['cnt'] ?> contacts</div>
      </div>
      <form method="POST" onsubmit="return confirm('Delete?')" onclick="event.stopPropagation()">
        <?= csrfField() ?><input type="hidden" name="action" value="delete_list"><input type="hidden" name="list_id" value="<?= $l['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm" onclick="event.stopPropagation()">🗑</button>
      </form>
    </a>
    <?php endforeach; ?>

    <?php if (empty($lists)): ?>
    <div style="text-align:center;padding:30px;color:var(--muted);font-size:13px;">No lists yet.<br>Create your first segment.</div>
    <?php endif; ?>

    <!-- Tags cloud -->
    <?php if (!empty($allTags)): ?>
    <div style="margin-top:20px;">
      <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:10px;">Tags</div>
      <div style="display:flex;flex-wrap:wrap;gap:6px;">
        <?php foreach ($allTags as $t): ?>
        <a href="?tag=<?= urlencode($t['tag']) ?>" style="background:<?= $tag===$t['tag']?'var(--accent-dim)':'var(--surface2)' ?>;border:1px solid <?= $tag===$t['tag']?'var(--border3)':'var(--border)' ?>;color:<?= $tag===$t['tag']?'var(--accent)':'var(--text2)' ?>;padding:4px 10px;border-radius:20px;font-size:12px;text-decoration:none;transition:all .15s;">
          <?= e($t['tag']) ?> <span style="opacity:.6;">(<?= $t['cnt'] ?>)</span>
        </a>
        <?php endforeach; ?>
        <?php if ($tag): ?><a href="?" style="color:var(--danger);font-size:12px;padding:4px 8px;">✕ Clear</a><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Contacts panel -->
  <div>
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
      <div style="font-size:15px;font-weight:800;color:#fff;">
        <?= $viewList ? e($viewList['name']) : 'All Contacts' ?>
        <span style="font-size:12px;font-weight:400;color:var(--muted);margin-left:8px;"><?= count($contacts) ?> shown</span>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <form method="GET" style="display:flex;gap:6px;">
          <?php if ($viewListId): ?><input type="hidden" name="list" value="<?= $viewListId ?>"> <?php endif; ?>
          <div class="search-box"><span>🔍</span><input type="search" name="q" placeholder="Search…" value="<?= e($search) ?>"></div>
          <button type="submit" class="btn btn-secondary btn-sm">Go</button>
        </form>
        <?php if (!empty($contacts) && !empty($lists)): ?>
        <button class="btn btn-secondary btn-sm" onclick="openModal('bulkAddModal')">+ Add to List</button>
        <button class="btn btn-secondary btn-sm" onclick="openModal('bulkTagModal')">🏷 Bulk Tag</button>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th><input type="checkbox" id="chkAll" onclick="document.querySelectorAll('.cchk').forEach(c=>c.checked=this.checked)"></th>
              <th>Contact</th><th>Phone</th><th>Tags</th><th>Source</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($contacts)): ?>
            <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--muted);">No contacts found.</td></tr>
            <?php endif; ?>
            <?php foreach ($contacts as $c): ?>
            <tr>
              <td><input type="checkbox" class="cchk" value="<?= $c['id'] ?>"></td>
              <td>
                <div style="display:flex;align-items:center;gap:8px;">
                  <div style="width:28px;height:28px;border-radius:7px;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;color:#000;"><?= strtoupper(substr($c['name'],0,2)) ?></div>
                  <span class="td-bold"><?= e($c['name']) ?></span>
                </div>
              </td>
              <td style="font-family:monospace;font-size:12px;"><?= e($c['phone']) ?></td>
              <td>
                <div style="display:flex;gap:4px;flex-wrap:wrap;">
                  <?php foreach (array_filter(explode(',',$c['tags']??'')) as $t): ?>
                  <span style="background:var(--accent-dim);color:var(--accent);padding:2px 7px;border-radius:10px;font-size:11px;"><?= e($t) ?></span>
                  <?php endforeach; ?>
                  <button class="btn btn-secondary btn-sm" style="font-size:10px;padding:2px 6px;" onclick="addTag(<?= $c['id'] ?>)">+tag</button>
                </div>
              </td>
              <td><span class="badge badge-gray"><?= ucfirst($c['source']??'manual') ?></span></td>
              <td>
                <?php if ($viewListId): ?>
                <form method="POST" style="display:inline;">
                  <?= csrfField() ?><input type="hidden" name="action" value="remove_from_list"><input type="hidden" name="list_id" value="<?= $viewListId ?>"><input type="hidden" name="contact_id" value="<?= $c['id'] ?>">
                  <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Create List Modal -->
<div class="modal-overlay" id="createListModal">
  <div class="modal" style="max-width:400px;">
    <div class="modal-title">New Contact List</div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="create_list">
      <div class="form-group"><label class="form-label">List Name *</label><input type="text" name="name" class="form-control" placeholder="VIP Customers" required></div>
      <div class="form-group"><label class="form-label">Description</label><input type="text" name="description" class="form-control" placeholder="Optional description"></div>
      <div class="form-group">
        <label class="form-label">Color</label>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <?php foreach (['#25d366','#4f8ef7','#ff8c42','#9b7cf4','#f5c842','#ff4757','#7fffd4'] as $col): ?>
          <label style="cursor:pointer;">
            <input type="radio" name="color" value="<?= $col ?>" style="display:none;" <?= $col==='#25d366'?'checked':'' ?>>
            <div style="width:26px;height:26px;border-radius:50%;background:<?= $col ?>;border:2px solid transparent;transition:border-color .15s;" onclick="this.previousElementSibling.checked=true;document.querySelectorAll('[name=color]').forEach(r=>r.nextElementSibling.style.borderColor=r.checked?'#fff':'transparent')"></div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('createListModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create List</button>
      </div>
    </form>
  </div>
</div>

<!-- Bulk Add to List Modal -->
<div class="modal-overlay" id="bulkAddModal">
  <div class="modal" style="max-width:400px;">
    <div class="modal-title">Add Selected to List</div>
    <form method="POST" id="bulkAddForm">
      <?= csrfField() ?><input type="hidden" name="action" value="add_to_list">
      <div id="bulkHiddenIds"></div>
      <div class="form-group">
        <label class="form-label">Select List</label>
        <select name="list_id" class="form-control" required>
          <option value="">— Choose list —</option>
          <?php foreach ($lists as $l): ?>
          <option value="<?= $l['id'] ?>"><?= e($l['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('bulkAddModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Add to List</button>
      </div>
    </form>
  </div>
</div>

<!-- Bulk Tag Modal -->
<div class="modal-overlay" id="bulkTagModal">
  <div class="modal" style="max-width:400px;">
    <div class="modal-title">Bulk Add Tag</div>
    <form method="POST" id="bulkTagForm">
      <?= csrfField() ?><input type="hidden" name="action" value="bulk_tag">
      <div id="bulkTagIds"></div>
      <div class="form-group"><label class="form-label">Tag Name</label><input type="text" name="bulk_tag_value" class="form-control" placeholder="e.g. vip, lead, customer" required></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('bulkTagModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Add Tag</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Tag Modal -->
<div class="modal-overlay" id="addTagModal">
  <div class="modal" style="max-width:360px;">
    <div class="modal-title">Add Tag</div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="add_tag"><input type="hidden" name="contact_id" id="tagContactId">
      <div class="form-group"><label class="form-label">Tag</label><input type="text" name="tag" class="form-control" placeholder="e.g. vip, lead" required autofocus></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addTagModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Add</button>
      </div>
    </form>
  </div>
</div>

<script>
function addTag(cid) {
  document.getElementById('tagContactId').value = cid;
  openModal('addTagModal');
}
function getSelected() {
  return [...document.querySelectorAll('.cchk:checked')].map(c=>c.value);
}
document.getElementById('bulkAddModal').addEventListener('click', function(e) {
  if (e.target === this) return;
  const sel = getSelected();
  const c = document.getElementById('bulkHiddenIds');
  c.innerHTML = sel.map(id=>`<input type="hidden" name="contact_ids[]" value="${id}">`).join('');
});
document.getElementById('bulkTagModal').addEventListener('click', function(e) {
  if (e.target === this) return;
  const sel = getSelected();
  const c = document.getElementById('bulkTagIds');
  c.innerHTML = sel.map(id=>`<input type="hidden" name="contact_ids[]" value="${id}">`).join('');
});
// populate hidden IDs on modal open
document.querySelectorAll('[onclick*="bulkAddModal"],[onclick*="bulkTagModal"]').forEach(btn => {
  btn.addEventListener('click', function() {
    const sel = getSelected();
    if (sel.length === 0) { alert('Select at least one contact first.'); return; }
    ['bulkHiddenIds','bulkTagIds'].forEach(id => {
      const c = document.getElementById(id);
      if (c) c.innerHTML = sel.map(v=>`<input type="hidden" name="contact_ids[]" value="${v}">`).join('');
    });
  });
});
</script>

<?php include __DIR__ . '/../includes/user_footer.php'; ?>