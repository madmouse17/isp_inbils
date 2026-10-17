<?php

/*
|--------------------------------------------------------------------------
| Firebase Cloud Messaging (push to the customer and technician apps)
|--------------------------------------------------------------------------
|
| Only the messaging side of kreait/laravel-firebase is used. This file replaces the package's own config (merged
| shallowly), so every other component of the Admin SDK stays unconfigured.
|
| `credentials` is the path of the Admin SDK service-account JSON, absolute or relative to base_path(). The file is a
| secret: it lives outside git and outside the web root (in Docker, compose.yaml mounts it read-only at
| /run/secrets/firebase-service-account.json). Empty means push is off: App\Notifications\Channels\FcmChannel adds no
| channel and the notifications keep their bell, mail and WhatsApp copies. See docs/operations/push-notifications.md.
|
*/

return [

    'default' => 'app',

    'projects' => [
        'app' => [
            'credentials' => env('FIREBASE_CREDENTIALS'),

            // Holds the Google access token the SDK fetches with the key (valid for an hour), so a send does not
            // sign in again. `file` stays on the container's own storage volume, away from the shared Redis.
            'cache_store' => 'file',

            'http_client_options' => [
                // Seconds before a call to FCM counts as failed; a push job must not hold a worker for long.
                'timeout' => 10,
            ],
        ],
    ],

];
