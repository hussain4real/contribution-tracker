self.addEventListener('install', (event) => {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
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
