<?php



/*
| Broadcast Channels
|
| Register all of the application's broadcast channels.
*/
Broadcast::channel('public-messages', function () {
    return true; // Public channel
});