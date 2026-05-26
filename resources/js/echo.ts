import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

(window as any).Pusher = Pusher;

const echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_PUSHER_APP_KEY as string,
    cluster: (import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'us2') as string,
    forceTLS: true,
    enabledTransports: ['ws', 'wss'],
});

export default echo;
