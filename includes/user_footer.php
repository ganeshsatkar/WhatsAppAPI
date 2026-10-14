  </div><!-- end .page-content -->
</div><!-- end .main-wrap -->

<?php
// Bottom navigation (phones only — hidden on desktop by CSS)
$bnPath = basename($_SERVER['PHP_SELF']);
$bnOpen = (int)($openChats ?? 0);
$bnav = [
    ['dashboard.php', '🏠', 'Home'],
    ['inbox.php',     '💬', 'Inbox'],
    ['campaigns.php', '📢', 'Campaigns'],
    ['contacts.php',  '👥', 'Contacts'],
];
?>
<nav class="bnav" aria-label="Quick navigation">
  <?php foreach ($bnav as [$file, $ic, $label]): ?>
  <a href="/user/<?= $file ?>" class="<?= $bnPath === $file ? 'on' : '' ?>">
    <span class="bn-ic"><?= $ic ?></span><?= $label ?>
    <?php if ($file === 'inbox.php' && $bnOpen > 0): ?><span class="bn-badge"><?= $bnOpen > 99 ? '99+' : $bnOpen ?></span><?php endif; ?>
  </a>
  <?php endforeach; ?>
  <button type="button" onclick="toggleMenu()" aria-label="All menu items"><span class="bn-ic">☰</span>More</button>
</nav>

<script>
window.CSRF_TOKEN = '<?= csrfToken() ?>';
window.APP_URL = '<?= APP_URL ?>';
function openModal(id){document.getElementById(id)?.classList.add('open');}
function closeModal(id){document.getElementById(id)?.classList.remove('open');}
document.addEventListener('click',function(e){
  if(e.target.classList.contains('modal-overlay')) e.target.classList.remove('open');
});
function toast(msg,type='success'){
  const el=document.createElement('div');
  const mobile=window.innerWidth<=768;
  el.style.cssText=`position:fixed;${mobile?'left:12px;right:12px;bottom:calc(76px + env(safe-area-inset-bottom));text-align:center;':'bottom:24px;right:24px;'}z-index:9999;background:${type==='success'?'#25d366':'#ff4d6d'};color:${type==='success'?'#000':'#fff'};padding:12px 20px;border-radius:10px;font-size:13.5px;font-weight:700;box-shadow:0 4px 20px rgba(0,0,0,0.4);animation:slideUp .3s ease;font-family:Cabinet Grotesk,sans-serif;`;
  el.textContent=msg;
  document.body.appendChild(el);
  setTimeout(()=>el.remove(),3500);
}
async function api(endpoint,data={},method='POST'){
  const res=await fetch(window.APP_URL+endpoint,{
    method,
    headers:{'Content-Type':'application/json','X-CSRF-TOKEN':window.CSRF_TOKEN},
    body:method!=='GET'?JSON.stringify(data):undefined
  });
  return res.json();
}

// ── Mobile menu ──────────────────────────────────────────
function toggleMenu(force){
  const open = typeof force === 'boolean' ? force : !document.body.classList.contains('menu-open');
  document.body.classList.toggle('menu-open', open);
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') toggleMenu(false); });
document.querySelectorAll('#sidebar a').forEach(a => a.addEventListener('click', () => toggleMenu(false)));
(function swipeToClose(){   // swipe left on the open menu closes it
  let x0 = null; const sb = document.getElementById('sidebar'); if (!sb) return;
  sb.addEventListener('touchstart', e => { x0 = e.touches[0].clientX; }, {passive:true});
  sb.addEventListener('touchend', e => { if (x0 !== null && x0 - e.changedTouches[0].clientX > 60) toggleMenu(false); x0 = null; }, {passive:true});
})();

// ── Wide tables scroll sideways inside their card instead of stretching the page ──
document.querySelectorAll('.page-content table').forEach(t => {
  if (t.closest('.table-wrap')) return;
  const w = document.createElement('div'); w.className = 'table-wrap';
  t.parentNode.insertBefore(w, t); w.appendChild(t);
});
</script>
<?php include __DIR__ . '/pwa_footer.php'; ?>
<style>@keyframes slideUp{from{transform:translateY(20px);opacity:0;}to{transform:translateY(0);opacity:1;}}</style>
</body>
</html>
