const CACHE_NAME = 'bf10-pwa-v1';
const ASSETS = [
  '/pwa-demo.html',
  '/manifest.json',
  '/img/icon-192.png',
  '/img/icon-512.png'
];

// Instalar: cachear archivos básicos
self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(ASSETS))
  );
  self.skipWaiting();
});

// Activar: limpiar caches antiguas
self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

// Fetch: servir desde cache, si no, ir a red
self.addEventListener('fetch', e => {
  e.respondWith(
    caches.match(e.request).then(cached => cached || fetch(e.request))
  );
});
