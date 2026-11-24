import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { ApiConfig } from '@/configs/api.config';

type ChatEcho = Echo<'pusher'>;

let echo: ChatEcho | null = null;
let tokenForEcho: string | null = null;

declare global {
    interface Window {
        Pusher: typeof Pusher;
    }
}

const envFlag = (value: string | undefined) => value === '1' || value === 'true';

export const getChatEcho = (): ChatEcho | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    const token = localStorage.getItem('access_token');
    if (!token) {
        return null;
    }

    if (echo && tokenForEcho === token) {
        return echo;
    }

    if (echo) {
        echo.disconnect();
        echo = null;
    }

    window.Pusher = Pusher;
    tokenForEcho = token;

    const forceTLS = envFlag(process.env.NEXT_PUBLIC_PUSHER_FORCE_SSL);
    const wsPort = Number(process.env.NEXT_PUBLIC_PUSHER_WS_PORT || (forceTLS ? 443 : 80));

    echo = new Echo({
        broadcaster: 'pusher',
        key: process.env.NEXT_PUBLIC_PUSHER_APP_KEY || '',
        cluster: process.env.NEXT_PUBLIC_PUSHER_CLUSTER || 'default',
        wsHost: process.env.NEXT_PUBLIC_PUSHER_WS_HOST || undefined,
        wsPort,
        wssPort: wsPort,
        forceTLS,
        // Enable both ws-named transports; `forceTLS` decides whether the "ws"
        // transport connects over ws:// or wss://. Restricting to these two keeps
        // pusher-js from falling back to its hosted SockJS/HTTP endpoints (wrong for Soketi).
        enabledTransports: ['ws', 'wss'],
        authEndpoint: `${ApiConfig.BASE_URL}/broadcasting/auth`,
        auth: {
            headers: {
                Authorization: `Bearer ${token}`,
                Accept: 'application/json',
            },
        },
    } as never);

    return echo;
};

export const disconnectChatEcho = () => {
    echo?.disconnect();
    echo = null;
    tokenForEcho = null;
};
