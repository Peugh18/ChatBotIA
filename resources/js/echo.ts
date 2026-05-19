import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const reverbEnabled = import.meta.env.VITE_REVERB_ENABLED === 'true';
const reverbKey = import.meta.env.VITE_REVERB_APP_KEY as string | undefined;

let echo: Echo | null = null;

if (reverbEnabled && reverbKey) {
    (window as any).Pusher = Pusher;

    echo = new Echo({
        broadcaster: 'reverb',
        key: reverbKey,
        wsHost: import.meta.env.VITE_REVERB_HOST as string,
        wsPort: (import.meta.env.VITE_REVERB_PORT ?? 8080) as number,
        wssPort: (import.meta.env.VITE_REVERB_PORT ?? 8080) as number,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}

export default echo;
