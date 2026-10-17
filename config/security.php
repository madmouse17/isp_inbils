<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Record enumeration detection
    |--------------------------------------------------------------------------
    |
    | DetectRecordEnumeration counts the record-level 404s of a signed-in user (nobody who is signed out is counted):
    | a public id that names no record, a record of another company, a record the policy hides. Past `threshold` of
    | them within `window` minutes it writes one `security.enumeration_suspected` audit entry and answers 429 for
    | `cooldown` minutes. A missing permission (403) is never counted.
    |
    | The detection is off in `testing` and `e2e`, where suites open many records that do not exist on purpose; the
    | tests of the middleware switch it on themselves.
    |
    */

    'enumeration' => [
        'enabled' => (bool) env('SECURITY_ENUMERATION_ENABLED', ! in_array(env('APP_ENV'), ['testing', 'e2e'], true)),
        'threshold' => (int) env('SECURITY_ENUMERATION_THRESHOLD', 30),
        'window' => (int) env('SECURITY_ENUMERATION_WINDOW', 5),
        'cooldown' => (int) env('SECURITY_ENUMERATION_COOLDOWN', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | TRUSTED_PROXIES lists the reverse proxies or load balancers in front of the app: comma-separated addresses (CIDR
    | ranges work), or `*` only when the proxy is the single way in. Their X-Forwarded-* headers then name the client,
    | for the audit entries, the per-address throttles and the generated URLs. Empty trusts nobody, and a client can
    | never name its own address. App\Http\Middleware\TrustConfiguredProxies reads it on every request: .env is not
    | loaded yet when bootstrap/app.php configures the middleware.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES'),

];
