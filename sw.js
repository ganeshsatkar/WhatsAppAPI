// WP API Pro — service worker
// Makes the app installable and shows a friendly page when the phone is offline.
// Logged-in pages and API calls are NEVER cached (they hold private customer data);
// only the offline page, icons and the manifest are stored.
const VERSION = 'wpapi-pro-v1';
const PRECACHE = ['/offline.html', '/manifest.webmanifest',
  '/assets/icons/icon-192.png', '/assets/icons/icon-512.png', '/assets/icons/favicon-32.png'];

self.addEventListener('install', event => {
  event.waitUntil(caches.open(VERSION).then(c => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(keys.filter(k => k !== VERSION).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  const req = event.request;
  if (req.method !== 'GET') return;                         // POST/AJAX go straight to the network
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;           // fonts, Razorpay, Facebook SDK etc.

  // Page navigations: always fresh from the network; offline page only when there is no connection
  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(() => caches.match('/offline.html')));
    return;
  }

  // App icons / manifest: cache-first
  if (url.pathname.startsWith('/assets/icons/') || url.pathname === '/manifest.webmanifest') {
    event.respondWith(caches.match(req).then(hit => hit || fetch(req)));
  }
});
