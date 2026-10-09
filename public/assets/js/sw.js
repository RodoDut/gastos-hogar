self.addEventListener('install', function (event) {
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(clients.claim());
});

self.addEventListener('fetch', function (event) {
  // No interceptar POST: Chrome ya consume el body multipart del Web Share
  // Target antes de disparar este evento, así que reenviarlo con
  // fetch(event.request) llega al servidor sin el archivo. Sin cache
  // offline no hay motivo para interceptar nada que no sea GET.
  if (event.request.method !== 'GET') {
    return;
  }
  event.respondWith(fetch(event.request));
});
