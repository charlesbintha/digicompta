self.addEventListener('push', event => {
    let payload = {title: 'Digi-Compta', body: 'Une nouvelle activité est disponible.'};
    try { if (event.data) payload = event.data.json(); } catch {}
    event.waitUntil(self.registration.showNotification(payload.title, {body: payload.body, tag: 'digicompta-activity', data: {url: '/'}}));
});
self.addEventListener('notificationclick', event => {
    event.notification.close();
    event.waitUntil(clients.matchAll({type: 'window', includeUncontrolled: true}).then(windows => {
        const existing = windows.find(window => new URL(window.url).origin === self.location.origin);
        return existing ? existing.focus() : clients.openWindow('/');
    }));
});
