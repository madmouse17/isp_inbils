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
    | Midtrans (Snap)
    |--------------------------------------------------------------------------
    |
    | IMPORT SOURCE ONLY. Each company keeps its own Midtrans account in
    | Pengaturan → Integrasi (table company_integrations); the app reads these
    | keys only in `php artisan inbils:integrations:import-env --company=<code>`,
    | which copies server_key, is_production, allow_sandbox_in_production and
    | base_url into that company's row. A company without a row has Midtrans off.
    | merchant_id and client_key are not imported: nothing reads them. `base_url`
    | points the gateway at a local mock and is honoured only in the local,
    | testing and e2e environments and never when is_production is true. The
    | notification URL is built per company (/webhooks/midtrans/{company public_id})
    | and sent as X-Override-Notification on every Snap order over HTTPS.
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

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Cloud API (Meta)
    |--------------------------------------------------------------------------
    |
    | IMPORT SOURCE ONLY, except `graph_version`, which every company shares at
    | runtime. Each company keeps its own access token, phone number id, app
    | secret and verify token in Pengaturan → Integrasi; the import command
    | (`inbils:integrations:import-env --company=<code>`) copies these keys into
    | that company's row once. A company without a row sends nothing: every
    | message is logged as skipped (disabled). The webhook stays one shared URL
    | (/webhooks/whatsapp), routed to the company by phone_number_id and signed
    | with that company's own app secret. business_account_id is not imported.
    | Templates and per-company switches: config/whatsapp.php.
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
    | `enabled` is an IMPORT SOURCE ONLY: the import command copies the `radius`
    | database connection (RADIUS_DB_*) into the company's own RADIUS integration
    | when it is true. At runtime each company switches RADIUS on and names its own
    | FreeRADIUS database in Pengaturan → Integrasi; without a row nothing is read
    | from or written to a RADIUS database and no Disconnect-Request is sent.
    | `coa_timeout` is the runtime value shared by every company: the seconds to wait
    | for a Disconnect answer. RADIUS shared secrets live encrypted in nas_devices.
    |
    */
    'radius' => [
        'enabled' => (bool) env('RADIUS_ENABLED', false),
        'coa_timeout' => 3,
    ],

];
