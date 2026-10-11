<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

/*
|--------------------------------------------------------------------------
| Sanctum: personal access tokens for the two mobile apps
|--------------------------------------------------------------------------
|
| Bearer tokens only. There is no SPA cookie flow: no stateful domains, no fallback to the web session guard
| (a session user must never pass for a token with every ability), and no csrf-cookie route.
|
*/

return [

    'stateful' => [],

    'guard' => [],

    'routes' => false,

    // Null on purpose: every token carries its own expires_at, set from app_token_days when it is issued.
    'expiration' => null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

    // Days a token lives, by the app ability it carries.
    'app_token_days' => [
        'customer' => 90,
        'technician' => 30,
    ],

];
