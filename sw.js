// Sin caché y SIN listener de 'fetch', a propósito (misma lección que van4ever):
// este service worker solo existe para que la app sea instalable. Aquí hay datos
// de salud y todas las páginas llevan «no-store»; cachear algo sería un riesgo, y
// un listener vacío obliga al navegador a despertar el worker en cada navegación.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
