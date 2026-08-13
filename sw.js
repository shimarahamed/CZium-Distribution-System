// CZium Distribution — service worker
// Caches the app shell so the UI loads instantly and survives a flaky
// connection. API calls are always network-first: stale business data is
// worse than no data, so they are never served from cache.
const CACHE = 'czium-v3';
const SHELL = ['/', '/index.html', '/manifest.json'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET') return;
  if (url.origin !== self.location.origin) return;

  // Never cache the API or shared documents.
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/share.php')) return;

  // Hashed build assets are immutable — cache first.
  if (url.pathname.startsWith('/assets/')) {
    e.respondWith(
      caches.match(e.request).then(hit => hit || fetch(e.request).then(res => {
        const copy = res.clone();
        caches.open(CACHE).then(c => c.put(e.request, copy));
        return res;
      }))
    );
    return;
  }

  // Navigation: network first, fall back to the cached shell when offline.
  e.respondWith(
    fetch(e.request)
      .then(res => {
        const copy = res.clone();
        caches.open(CACHE).then(c => c.put(e.request, copy));
        return res;
      })
      .catch(() => caches.match(e.request).then(hit => hit || caches.match('/index.html')))
  );
});
