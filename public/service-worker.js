const CACHE_PREFIX = 'ev-stats-static-';
const SERVICE_WORKER_URL = new URL(self.location.href);
const CACHE_VERSION = SERVICE_WORKER_URL.searchParams.get('v') || 'local';
const SAFE_CACHE_VERSION = CACHE_VERSION.replace(/[^a-zA-Z0-9._-]+/g, '-').slice(0, 120) || 'local';
const CACHE_NAME = `${CACHE_PREFIX}${SAFE_CACHE_VERSION}`;
const VERSION_QUERY = encodeURIComponent(CACHE_VERSION);
const STATIC_ASSETS = [
  `./assets/app.css?v=${VERSION_QUERY}`,
  `./assets/app.js?v=${VERSION_QUERY}`,
  `./assets/icons/icon-192.png?v=${VERSION_QUERY}`,
  `./assets/icons/icon-512.png?v=${VERSION_QUERY}`
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS)));
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys
        .filter((key) => key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME)
        .map((key) => caches.delete(key))
    ))
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;

  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin) return;

  const isStaticAsset = /\/assets\//.test(url.pathname);
  if (!isStaticAsset) return;

  event.respondWith(
    caches.match(event.request).then((cached) => {
      const network = fetch(event.request).then((response) => {
        if (response && response.ok) {
          const copy = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
        }
        return response;
      });

      return cached || network;
    })
  );
});
