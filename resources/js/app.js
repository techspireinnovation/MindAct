import './bootstrap';

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Required for Laravel Echo
window.Pusher = Pusher;

// Laravel Reverb configuration
window.Echo = new Echo({
    broadcaster: 'reverb',
    host: window.location.hostname + ':8082', // default Reverb port
});

// Listening to the "products" channel for the "ProductUpdated" event
window.Echo.channel('products')
    .listen('.ProductUpdated', (e) => {
        console.log('📦 Product Updated Event Received!');
        console.log('Product:', e.products);
        console.log('Action:', e.action);
    });
