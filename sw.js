/* ============================================================
   Mailroom System - Service Worker
   Cache-first for static assets, network-first for pages,
   offline fallback when the server is unreachable.
   ============================================================ */

const VERSION = 'mr-v1';
const STATIC_CACHE = VERSION + '-static';
const RUNTIME_CACHE = VERSION + '-runtime';

const STATIC_ASSETS = [
  './',
  './manifest.json',
  './offline.html',
  './assets/app.css',
  './assets/app.js',
  './images/logo.png',
  './images/icons/192.png',
  './images/icons/512.png',
  './images/icons/maskable-512.png',
  './images/icons/apple-touch-icon.png',
  './images/icons/favicon-64.png'
];

// Fonts / icons served from a CDN (runtime cached, not pre-cached)
const RUNTIME_PREFIXES = [
  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome',
  'https://cdn.tailwindcss.com',
  'https://fonts.googleapis.com',
  'https://fonts.gstatic.com'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE).then((cache) => cache.addAll(STATIC_ASSETS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // Only handle same-origin + known CDN requests
  const isSameOrigin = url.origin === self.location.origin;
  const isCdn = RUNTIME_PREFIXES.some((p) => event.request.url.startsWith(p));
  if (!isSameOrigin && !isCdn) return;

  // Navigation / page documents: network-first with offline fallback
  if (event.request.mode === 'navigate' || /\.php$/.test(url.pathname)) {
    event.respondWith(
      fetch(event.request)
        .then((response) => {
          const copy = response.clone();
          caches.open(STATIC_CACHE).then((cache) => cache.put(event.request, copy));
          return response;
        })
        .catch(() =>
          caches.match(event.request).then((cached) =>
            cached || caches.match('./offline.html')
          )
        )
    );
    return;
  }

  // Everything else: cache-first (stale-while-revalidate)
  event.respondWith(
    caches.match(event.request).then((cached) => {
      const network = fetch(event.request)
        .then((response) => {
          if (response && response.ok) {
            const copy = response.clone();
            (isSameOrigin ? caches.open(STATIC_CACHE) : caches.open(RUNTIME_CACHE))
              .then((cache) => cache.put(event.request, copy));
          }
          return response;
        })
        .catch(() => cached);
      return cached || network;
    })
  );
});