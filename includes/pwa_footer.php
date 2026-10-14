<?php // PWA: service worker + "Install app" button — include before </body> ?>
<script>
(function () {
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
  }
  const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  const btns = () => document.querySelectorAll('#installBtn, [data-install]');
  let deferred = null;

  // Android / Chrome / Edge: native install prompt
  window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    deferred = e;
    btns().forEach(b => b.hidden = false);
  });
  window.addEventListener('appinstalled', () => { deferred = null; btns().forEach(b => b.hidden = true); });

  // iPhone / iPad Safari has no prompt: show the button with Add-to-Home-Screen steps
  const ios = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
  if (ios && !standalone) btns().forEach(b => b.hidden = false);

  document.addEventListener('click', async e => {
    const b = e.target.closest('#installBtn, [data-install]');
    if (!b) return;
    e.preventDefault();
    if (deferred) {
      deferred.prompt();
      await deferred.userChoice;
      deferred = null;
      btns().forEach(x => x.hidden = true);
    } else if (ios) {
      alert('Install WP API Pro:\n\n1. Tap the Share button (square with ↑) in Safari\n2. Choose "Add to Home Screen"\n3. Tap "Add"');
    }
  });
})();
</script>
