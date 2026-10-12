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
    | environments and never when is_production is true. `notification_url`
    | (HTTPS only) is sent as X-Override-Notification on every Snap order, so
    | notifications reach this install without editing the Midtrans dashboard.
    |
    */
    'midtrans' => [
        'merchant_id' => env('MIDTRANS_MERCHANT_ID'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'is_production' => (bool) env('MIDTRANS_IS_PRODUCTION', false),
        'allow_sandbox_in_production' => (bool) env('MIDTRANS_ALLOW_SANDBOX_IN_PRODUCTION', false),
        'base_url' => env('MIDTRANS_BASE_URL'),
        'notification_url' => env('MIDTRANS_NOTIFICATION_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Cloud API (Meta)
    |--------------------------------------------------------------------------
    |
    | One install-wide sender. The feature is off while the access token or the phone number id is
    | empty: every message is then logged as skipped (disabled) and nothing leaves the server.
    | `app_secret` signs the delivery webhook (X-Hub-Signature-256); `webhook_verify_token` answers
    | Meta's one-off GET handshake. Templates and per-company switches: config/whatsapp.php.
    |
    */
    'whatsapp' => [
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'graph_version' => env('WHATSAPP_GRAPH_VERSION') ?: 'v25.0',
    ],

    /*
    |--------------------------------------------------------------------------
    | FreeRADIUS provisioning
    |--------------------------------------------------------------------------
    |
    | `enabled` is the installation switch: while false nothing is read from or written to the
    | RADIUS database and no Disconnect-Request is sent. `coa_timeout` is the seconds to wait for
    | a Disconnect answer. RADIUS shared secrets live encrypted in nas_devices, never here.
    |
    */
    'radius' => [
        'enabled' => (bool) env('RADIUS_ENABLED', false),
        'coa_timeout' => 3,
    ],

];
