const CACHE_NAME = 'salle-des-profs-cache-v12';

const STATIC_ASSETS = [
    './',
    './index.php',
    './assets/app.css',
    './assets/app.js',
    './assets/public-hero-social.png'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(STATIC_ASSETS))
            .catch(() => null)
    );

    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys
                    .filter((key) => key !== CACHE_NAME)
                    .map((key) => caches.delete(key))
            );
        })
    );

    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    event.respondWith(
        fetch(request).catch(() => {
            return caches.match(request);
        })
    );
});
