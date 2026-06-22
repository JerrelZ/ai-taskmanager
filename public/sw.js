const CACHE = 'tasks-shell-v3';
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE).then((cache) => cache.add(OFFLINE_URL)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

// Show incoming web-push notifications. Payload is JSON shaped by the
// laravel-notification-channels/webpush package.
self.addEventListener('push', (event) => {
    if (!event.data) {
        return;
    }

    let payload = {};
    try {
        payload = event.data.json();
    } catch (e) {
        payload = { title: 'Tasks', body: event.data.text() };
    }

    const title = payload.title || 'Tasks';
    const options = {
        body: payload.body || '',
        icon: payload.icon || '/icon-192.png',
        badge: payload.badge || '/icon-192.png',
        tag: payload.tag || undefined,
        data: payload.data || {},
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

// Focus an existing tab (or open one) at the notification's target URL.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            for (const client of clients) {
                if ('focus' in client) {
                    client.navigate(url);
                    return client.focus();
                }
            }
            return self.clients.openWindow(url);
        })
    );
});

// Network-first for navigations so the app stays fresh, with a cached
// fallback (the last-seen page, then the offline page) when offline.
// Other requests pass through untouched.
self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET' || request.mode !== 'navigate') {
        return;
    }

    event.respondWith(
        fetch(request)
            .then((response) => {
                const copy = response.clone();
                caches.open(CACHE).then((cache) => cache.put(request, copy));
                return response;
            })
            .catch(() =>
                caches.match(request).then((cached) => cached || caches.match(OFFLINE_URL))
            )
    );
});
