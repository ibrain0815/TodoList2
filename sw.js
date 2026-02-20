const CACHE_NAME = 'task-cal-v1';
const ASSETS_TO_CACHE = [
    '/',
    '/index.html',
    '/manifest.json',
    '/icon-192.png',
    '/icon-512.png'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(ASSETS_TO_CACHE);
        })
    );
});

self.addEventListener('fetch', (event) => {
    event.respondWith(
        caches.match(event.request).then((response) => {
            return response || fetch(event.request);
        })
    );
});

// Notification click event handler
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    // 알림 데이터에서 이동할 날짜 가져오기 (예: '2024-02-01')
    const targetDate = event.notification.data?.date;
    const urlToOpen = new URL('/', self.location.origin);
    if (targetDate) {
        urlToOpen.searchParams.set('date', targetDate);
    }

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
            // 이미 열려있는 창이 있으면 해당 창으로 포커스
            for (const client of windowClients) {
                if (client.url.includes(self.location.origin) && 'focus' in client) {
                    // 열려있는 창에 날짜 이동 메시지 전송
                    if (targetDate) {
                        client.postMessage({ type: 'MOVE_TO_DATE', date: targetDate });
                    }
                    return client.focus();
                }
            }
            // 열려있는 창이 없으면 파라미터를 포함하여 새로 열기
            if (clients.openWindow) {
                return clients.openWindow(urlToOpen.toString());
            }
        })
    );
});
