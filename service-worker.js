const CACHE_NAME = 'qm-v1.1.0';

const STATIC_ASSETS = [
  './',
  './index.php',
  './assets/css/app.css',
  './assets/js/api-client.js',
  './assets/js/app.js',
  './assets/js/components/connections.js',
  './assets/js/components/browser.js',
  './assets/js/components/query-editor.js',
  './assets/js/components/audit.js',
  './assets/js/components/help.js',
  './assets/js/components/users.js',
  './assets/js/components/client-export.js',
  './assets/js/components/sql-intellisense.js',
  './assets/js/components/multi-query.js',
  './assets/js/components/cross-join.js',
  './assets/js/components/schema-compare.js',
  './assets/help-content.html',
  './assets/icons/icon.svg',
  './manifest.json'
];

const CDN_ASSETS = [
  'https://cdn.jsdelivr.net/npm/sweetalert2@11'
];

// Install: cache static assets
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      return cache.addAll([...STATIC_ASSETS, ...CDN_ASSETS]);
    }).then(() => self.skipWaiting())
  );
});

// Activate: clean old caches
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => {
      return Promise.all(
        keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))
      );
    }).then(() => self.clients.claim())
  );
});

// Fetch: network-first for API, cache-first for assets
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);

  // Never cache API calls - always go to network
  if (url.pathname.includes('/api/')) {
    event.respondWith(
      fetch(event.request).catch(() => {
        return new Response(
          JSON.stringify({ error: 'Sin conexión al servidor. Verifica que el servidor esté encendido.' }),
          { status: 503, headers: { 'Content-Type': 'application/json' } }
        );
      })
    );
    return;
  }

  // Cache-first for static assets and CDN
  event.respondWith(
    caches.match(event.request).then(cached => {
      if (cached) {
        // Return cache immediately, update in background
        const fetchPromise = fetch(event.request).then(response => {
          if (response.ok) {
            caches.open(CACHE_NAME).then(cache => cache.put(event.request, response));
          }
          return response.clone();
        }).catch(() => {});
        return cached;
      }
      // Not in cache, fetch from network and cache it
      return fetch(event.request).then(response => {
        if (response.ok && event.request.method === 'GET') {
          const clone = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
        }
        return response;
      });
    })
  );
});
