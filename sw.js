/**
 * ADF System - Push Notification Service Worker
 * Handles real Web Push notifications via VAPID
 */

const CACHE_NAME = 'adf-system-v3';

// Install event
self.addEventListener('install', (event) => {
    console.log('[SW] Installed');
    self.skipWaiting();
});

// Activate event
self.addEventListener('activate', (event) => {
    console.log('[SW] Activated');
    event.waitUntil(clients.claim());
});

// ═══ PUSH EVENT — real server push via VAPID ═══
self.addEventListener('push', (event) => {
    console.log('[SW] Push received');

    let data = {
        title: 'ADF System',
        body: 'Ada notifikasi baru',
        icon: '/assets/img/logo.png',
        badge: '/assets/img/badge.png',
        tag: 'adf-notification',
        data: {}
    };

    if (event.data) {
        try {
            const payload = event.data.json();
            data = { ...data, ...payload };
        } catch (e) {
            data.body = event.data.text();
        }
    }

    const options = {
        body: data.body,
        icon: data.icon || '/assets/img/logo.png',
        badge: data.badge || '/assets/img/badge.png',
        tag: data.tag || 'adf-push-' + Date.now(),
        vibrate: data.vibrate || [200, 100, 200, 100, 200],
        requireInteraction: true,
        data: data.data || {},
        actions: [
            { action: 'view', title: '👁️ Lihat Detail' },
            { action: 'dismiss', title: '✖️ Tutup' }
        ]
    };

    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

// ═══ NOTIFICATION CLICK ═══
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    if (event.action === 'dismiss') return;

    const urlToOpen = event.notification.data?.url || '/index.php';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then((clientList) => {
                for (const client of clientList) {
                    if ('focus' in client) {
                        client.navigate(urlToOpen);
                        return client.focus();
                    }
                }
                if (clients.openWindow) {
                    return clients.openWindow(urlToOpen);
                }
            })
    );
});

// ═══ PUSH SUBSCRIPTION CHANGE (browser rotates keys) ═══
self.addEventListener('pushsubscriptionchange', (event) => {
    console.log('[SW] Subscription changed, re-subscribing...');
    event.waitUntil(
        self.registration.pushManager.subscribe(event.oldSubscription.options)
            .then((newSub) => {
                return fetch('/api/push-subscription.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'subscribe',
                        subscription: newSub.toJSON()
                    })
                });
            })
    );
});

// Background sync for offline capability
self.addEventListener('sync', (event) => {
    if (event.tag === 'sync-notifications') {
        event.waitUntil(syncNotifications());
    }
});

async function syncNotifications() {
    console.log('[SW] Syncing notifications...');
}
