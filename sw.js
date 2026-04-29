const CACHE = 'lm-v13';
const STATIC_ASSETS = [
  '/manifest.json',
  '/assets/icon-192.png',
  '/assets/icon-512.png',
];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(STATIC_ASSETS).catch(()=>{})));
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('message', e => {
  if (e.data?.type === 'SKIP_WAITING') self.skipWaiting();
});

self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET') return;
  const url = new URL(e.request.url);

  // API e version check: network-only, mai cache
  if (url.pathname.endsWith('/api.php') || url.pathname.endsWith('/version.php')) {
    e.respondWith(fetch(e.request).catch(() => new Response(JSON.stringify({ ok: false, error: 'offline' }), { headers: { 'Content-Type': 'application/json' } })));
    return;
  }

  // HTML / index: network-first (sennò non vedi nuove versioni di app.js/style.css)
  if (e.request.mode === 'navigate' || url.pathname === '/' || url.pathname.endsWith('.html')) {
    e.respondWith(
      fetch(e.request).then(resp => {
        const copy = resp.clone();
        caches.open(CACHE).then(c => c.put(e.request, copy));
        return resp;
      }).catch(() => caches.match(e.request).then(c => c || caches.match('/')))
    );
    return;
  }

  // Asset versionati (?v=N): cache-first; con nuova v il path cambia → cache miss → fetch fresco
  e.respondWith(
    caches.match(e.request).then(cached =>
      cached || fetch(e.request).then(resp => {
        if (resp.ok) {
          const copy = resp.clone();
          caches.open(CACHE).then(c => c.put(e.request, copy));
        }
        return resp;
      }).catch(() => cached)
    )
  );
});
