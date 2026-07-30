<?php

use App\Models\PushSubscription;

/*
|--------------------------------------------------------------------------
| Web Push (VAPID) — member PWA notifications
|--------------------------------------------------------------------------
|
| The PRIVATE key is a server-only secret: it lives in the environment, is read
| here, and is NEVER rendered into any client bundle. Only the PUBLIC key is
| exposed to the browser (needed to create a subscription). Every value has a
| safe EMPTY default, so an unconfigured install simply sends nothing — the send
| path degrades gracefully rather than throwing (see App\Notifications\*).
|
| Generate a keypair once with:  php artisan webpush:vapid
| then copy the values into VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY.
|
*/

return [

    'vapid' => [
        'subject' => env('VAPID_SUBJECT', config('app.url')),
        'public_key' => env('VAPID_PUBLIC_KEY', ''),
        'private_key' => env('VAPID_PRIVATE_KEY', ''),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    // Use the app's ULID-keyed subscription model (not the package's default), so
    // every subscription is keyed to a Member by a non-guessable identifier.
    'model' => PushSubscription::class,

    'table_name' => env('WEBPUSH_DB_TABLE', 'push_subscriptions'),

    'database_connection' => env('WEBPUSH_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),

    'client_options' => [],

    'automatic_padding' => env('WEBPUSH_AUTOMATIC_PADDING', true),

];
