import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});

async function registerPush() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

    try {
        // ✅ I-register ang SW at hintayin na maging ready
        const reg = await navigator.serviceWorker.register('/sw.js');
        await navigator.serviceWorker.ready; // ← MAHALAGANG DAGDAG

        // Check kung naka-subscribe na
        const existing = await reg.pushManager.getSubscription();
        if (existing) {
            // I-save na lang ang existing subscription
            await saveSubscription(existing);
            return;
        }

        const vapidRes = await fetch('/api/push/vapid-key');
        const { key } = await vapidRes.json();

        const sub = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(key),
        });

        await saveSubscription(sub);
    } catch (err) {
        console.warn('[Push] Registration failed:', err);
        // Tukuyin if it's Brave / AbortError
        if (err.name === 'AbortError' || (err.message && err.message.includes('AbortError'))) {
            alert('Push services disabled. Kung gamit mo ay Brave Browser, pakibuksan ang brave://settings/privacy at i-enable ang "Use Google services for push messaging" para maka-receive ng notifications.');
        }
    }
}

async function saveSubscription(sub) {
    await fetch('/api/push/subscribe', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify(sub.toJSON()),
    });
}

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64  = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    return Uint8Array.from([...rawData].map(c => c.charCodeAt(0)));
}

// Register after page load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', registerPush);
} else {
    registerPush();
}