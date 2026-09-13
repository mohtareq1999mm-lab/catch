window._ = require('lodash');

/**
 * We'll load the axios HTTP library which allows us to easily issue requests
 * to our Laravel back-end. This library automatically handles sending the
 * CSRF token as a header based on the value of the "XSRF" token cookie.
 */

window.axios = require('axios');

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allows your team to easily build robust real-time web applications.
 */

import Echo from 'laravel-echo';

window.Pusher = require('pusher-js');

const pusherKey = process.env.MIX_PUSHER_APP_KEY || process.env.VITE_PUSHER_APP_KEY || '';
const pusherCluster = process.env.MIX_PUSHER_APP_CLUSTER || process.env.VITE_PUSHER_APP_CLUSTER || 'mt1';
const pusherHost = process.env.MIX_PUSHER_HOST || process.env.VITE_PUSHER_HOST || undefined;
const pusherPort = process.env.MIX_PUSHER_PORT || process.env.VITE_PUSHER_PORT || undefined;
const pusherScheme = process.env.MIX_PUSHER_SCHEME || process.env.VITE_PUSHER_SCHEME || undefined;

if (pusherKey) {
    window.Echo = new Echo({
        broadcaster: 'pusher',
        key: pusherKey,
        cluster: pusherCluster,
        wsHost: pusherHost,
        wsPort: pusherPort ? parseInt(pusherPort, 10) : undefined,
        wssPort: pusherPort ? parseInt(pusherPort, 10) : undefined,
        forceTLS: (pusherScheme || 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
        disableStats: true,
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
            },
        },
    });
}
