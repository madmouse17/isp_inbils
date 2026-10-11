<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Midtrans (Snap sandbox pilot)
    |--------------------------------------------------------------------------
    |
    | One install-wide merchant. The gateway is off while server_key is empty,
    | and off on a production host (APP_ENV=production) that runs sandbox keys
    | (is_production=false) unless allow_sandbox_in_production is set on purpose:
    | sandbox payments there would be booked as real ones. `base_url` points the
    | gateway at a local mock and is honoured only in the local, testing and e2e
    | environments and never when is_production is true.
    |
    */
    'midtrans' => [
        'merchant_id' => env('MIDTRANS_MERCHANT_ID'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'is_production' => (bool) env('MIDTRANS_IS_PRODUCTION', false),
        'allow_sandbox_in_production' => (bool) env('MIDTRANS_ALLOW_SANDBOX_IN_PRODUCTION', false),
        'base_url' => env('MIDTRANS_BASE_URL'),
    ],

];
