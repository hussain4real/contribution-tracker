self.__FAMILYFUNDS_OLD_RUNTIME_CACHES = [
    'inertia-pages-v1',
    'inertia-pages-v2',
    'static-resources',
    'bunny-fonts-cache',
    'images-cache',
];

self.addEventListener('install', (event) => {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        Promise.all([
            self.clients.claim(),
            caches
                .keys()
                .then((cacheNames) =>
                    Promise.all(
                        cacheNames
                            .filter((cacheName) =>
                                self.__FAMILYFUNDS_OLD_RUNTIME_CACHES.includes(
                                    cacheName,
                                ),
                            )
                            .map((cacheName) => caches.delete(cacheName)),
                    ),
                ),
        ]),
    );
});

self.addEventListener('push', (event) => {
    let payload = {};

    if (event.data) {
        try {
            payload = event.data.json();
        } catch {
            payload = { body: event.data.text() };
        }
    }

    const title = payload.title || 'FamilyFunds';
    const options = { ...payload };
    delete options.title;

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target = event.notification.data?.url || '/notifications';
    const targetUrl = new URL(target, self.location.origin).href;

    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((clients) => {
                const matchingClient = clients.find((client) => client.url === targetUrl);

                if (matchingClient) {
                    return matchingClient.focus();
                }

                return self.clients.openWindow(targetUrl);
            }),
    );
});
