/**
 * Service Worker — Staff Portal & Absensi PWA
 * Handles caching for offline / slow-network support
 */

const CACHE_NAME   = 'staff-portal-v5';
const APP_SHELL    = [
    './absen.php',
    './staff-portal.php',
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
];

// CDN assets that can be cached on first use (cache-first)
const CDN_PREFIXES = [
    'cdn.jsdelivr.net',
    'unpkg.com',
    'nominatim.openstreetmap.org', // reverse geocode — cache briefly
];

// ── INSTALL: pre-cache app shell ──────────────────────────
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            // Cache shell — ignore individual failures so SW still installs
            return Promise.allSettled(APP_SHELL.map(url => cache.add(url)));
        }).then(() => self.skipWaiting())
    );
});

// ── ACTIVATE: clean old caches ───────────────────────────
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

// ── FETCH: routing strategy ───────────────────────────────
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // 1. API calls → always network-only (data must be fresh)
    if (url.pathname.includes('attendance-clock.php') || url.pathname.includes('staff-api.php')) {
        event.respondWith(fetch(event.request).catch(() =>
            new Response(JSON.stringify({ success: false, message: 'Tidak ada koneksi internet.' }), {
                headers: { 'Content-Type': 'application/json' }
            })
        ));
        return;
    }

    // 2. OpenStreetMap tile images → cache-first (tiles don't change often)
    if (url.hostname.includes('tile.openstreetmap.org')) {
        event.respondWith(cacheFirst(event.request, 'tiles-v1'));
        return;
    }

    // 3. CDN resources (face-api, leaflet) → cache-first
    if (CDN_PREFIXES.some(p => url.hostname.includes(p))) {
        event.respondWith(cacheFirst(event.request, CACHE_NAME));
        return;
    }

    // 4. App shell (absen/staff-portal) → network-first, fall back to cache
    if (url.pathname.includes('absen.php') || url.pathname.includes('staff-portal.php') || url.pathname === '/modules/payroll/') {
        event.respondWith(networkFirst(event.request));
        return;
    }

    // 5. Everything else → network (admin pages, other modules)
    event.respondWith(fetch(event.request));
});

// ── Helper: Cache-First ───────────────────────────────────
async function cacheFirst(request, cacheName) {
    const cached = await caches.match(request);
    if (cached) return cached;
    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(cacheName);
            cache.put(request, response.clone());
        }
        return response;
    } catch (_) {
        return new Response('Offline', { status: 503 });
    }
}

// ── Helper: Network-First ─────────────────────────────────
async function networkFirst(request) {
    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(request, response.clone());
        }
        return response;
    } catch (_) {
        const cached = await caches.match(request);
        if (cached) return cached;
        return offlinePage();
    }
}

// ── Offline fallback page ─────────────────────────────────
function offlinePage() {
    return new Response(`
<!DOCTYPE html><html lang="id">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tidak Ada Koneksi</title>
<style>
  body{font-family:sans-serif;background:#0d1f3c;color:#fff;display:flex;align-items:center;justify-content:center;min-height:100vh;flex-direction:column;gap:16px;text-align:center;padding:24px;}
  .icon{font-size:64px;}
  h2{font-size:20px;font-weight:800;color:#f0b429;}
  p{color:rgba(255,255,255,0.6);font-size:14px;max-width:280px;}
  button{padding:12px 28px;background:#f0b429;color:#0d1f3c;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;margin-top:8px;}
</style></head>
<body>
  <div class="icon">📡</div>
  <h2>Tidak Ada Koneksi</h2>
  <p>Pastikan HP terhubung ke internet, lalu coba lagi.</p>
  <button onclick="location.reload()">🔄 Coba Lagi</button>
</body></html>
    `, { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}
