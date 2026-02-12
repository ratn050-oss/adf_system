/**
 * ADF System - Push Notification Service Worker
 * Handles background notifications for end-shift alerts
 */

const CACHE_NAME = 'adf-system-v1';

// Install event
self.addEventListener('install', (event) => {
    console.log('Service Worker installed');
    self.skipWaiting();
});

// Activate event
self.addEventListener('activate', (event) => {
    console.log('Service Worker activated');
    event.waitUntil(clients.claim());
});

// Push event - when notification received
self.addEventListener('push', (event) => {
    console.log('Push notification received');
    
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
            data = { ...data, ...event.data.json() };
        } catch (e) {
            data.body = event.data.text();
        }
    }
    
    const options = {
        body: data.body,
        icon: data.icon || '/assets/img/logo.png',
        badge: data.badge || '/assets/img/badge.png',
        tag: data.tag || 'adf-notification',
        vibrate: [200, 100, 200, 100, 200],
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

// Notification click event
self.addEventListener('notificationclick', (event) => {
    console.log('Notification clicked:', event.action);
    
    event.notification.close();
    
    if (event.action === 'dismiss') {
        return;
    }
    
    // Open the relevant page
    const urlToOpen = event.notification.data?.url || '/index.php';
    
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then((clientList) => {
                // Check if there's already an open window
                for (const client of clientList) {
                    if (client.url.includes('adf') && 'focus' in client) {
                        client.navigate(urlToOpen);
                        return client.focus();
                    }
                }
                // If no window open, open new one
                if (clients.openWindow) {
                    return clients.openWindow(urlToOpen);
                }
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
    console.log('Syncing notifications...');
}
