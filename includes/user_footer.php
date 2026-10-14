  </div><!-- end .page-content -->
</div><!-- end .main-wrap -->
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
  el.style.cssText=`position:fixed;bottom:24px;right:24px;z-index:9999;background:${type==='success'?'#25d366':'#ff4d6d'};color:${type==='success'?'#000':'#fff'};padding:12px 20px;border-radius:10px;font-size:13.5px;font-weight:700;box-shadow:0 4px 20px rgba(0,0,0,0.4);animation:slideUp .3s ease;font-family:Cabinet Grotesk,sans-serif;`;
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
</script>
<style>@keyframes slideUp{from{transform:translateY(20px);opacity:0;}to{transform:translateY(0);opacity:1;}}</style>
</body>
</html>
