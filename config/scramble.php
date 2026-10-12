<?php

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;

return [
    /*
     * Which routes to document. String or array form; use Scramble::routes() for custom selection.
     *
     * 'api_path' => [
     *     'include' => 'api',
     *     'exclude' => ['api/internal'],
     * ],
     *
     * Without *, patterns match path segments (api matches api and api/users, not apiary).
     * With *, Str::is is used (e.g. api/v*).
     *
     * One static include → default server is /{include} and paths are stripped (/users).
     * Multiple includes or wildcards → server defaults to / and paths stay full (/api/users).
     * Override with `servers`, or use Scramble::registerApi() for separate bases.
     */
    'api_path' => 'api/v1',

    /*
     * Your API domain. By default, app domain is used. This is also a part of the default API routes
     * matcher, so when implementing your own, make sure you use this config if needed.
     */
    'api_domain' => null,

    /*
     * The path where your OpenAPI specification will be exported.
     */
    'export_path' => 'docs/api/mobile-v1.openapi.json',

    /*
     * Cache configuration for the generated OpenAPI document.
     *
     * Use `scramble:cache` to warm the cache and `scramble:clear` to invalidate it.
     */
    'cache' => [
        'key' => 'scramble.openapi',
        'store' => 'file',
    ],

    'info' => [
        /*
         * API version.
         */
        'version' => '1.0.0',

        /*
         * Description rendered on the home page of the API documentation (`/docs/api`).
         */
        'description' => <<<'MD'
Contract of the Inbils customer and technician mobile apps. The apps hand-write their models from this document.
Regenerate it with `php artisan scramble:export` after any change to an `api/v1` route, request or resource.

## Authentication

Every endpoint except `POST auth/login`, `POST auth/two-factor` and `POST auth/forgot-password` needs
`Authorization: Bearer <token>`. A token belongs to one app install and carries one ability, `customer` or
`technician`, picked by the `app` field at login. A token expires 90 days (customer) or 30 days (technician) after it
is issued. `POST auth/token/refresh` swaps it for a new one; refresh when fewer than 7 days remain. Any 401 means
"sign in again". Logout, a password change, deactivation, a 2FA change and `DELETE devices/{id}` revoke tokens.

A user with confirmed two-factor authentication gets `202` from `POST auth/login` with a `challenge_id` (valid 300 s,
5 attempts) and no token. `POST auth/two-factor` with a TOTP or recovery code then issues the token.

## Errors

Every error under `api/v1` is JSON: `{"message": string, "code": string, "errors"?: {field: [string]}}`.
`message` is for developers and may be English or Indonesian; show your own text per `code`. `errors` appears only
with `validation_failed`. A 429 carries `Retry-After` (seconds).

| Status | `code` |
|---|---|
| 400 | `bad_request` |
| 401 | `unauthenticated` (missing, expired or revoked token), `account_inactive` (the token is deleted) |
| 403 | `forbidden` (wrong app ability or no permission), `app_not_allowed` (the account may not use this app), `password_reset_required` |
| 404 | `not_found` (also another customer's or company's record), `payment_unavailable` (online payment is off) |
| 405 | `method_not_allowed` |
| 408 | `request_timeout` |
| 409 | `conflict`, `idempotency_in_progress` |
| 413 | `payload_too_large` |
| 415 | `unsupported_media_type` |
| 422 | `validation_failed`, `unprocessable` (a well-formed request the business rules refuse), `idempotency_conflict`, `invoice_not_payable`, `challenge_expired` |
| 428 | `precondition_required`, `idempotency_key_required` |
| 429 | `too_many_requests` |
| 500 | `server_error` |
| other 4xx | `client_error` |

## Idempotency

Every `POST`, `PUT`, `PATCH` and `DELETE` except the three public auth endpoints needs `Idempotency-Key: <UUID>`
(missing: 428 `idempotency_key_required`; not a UUID: 422 `validation_failed`). Generate one key per user action and
reuse it for every retry of that action. For 24 hours the server stores the response per user and key:

- a retry with the same request gets the stored status and body again, with the header `Idempotent-Replayed: true`;
- the same key with a different request is 422 `idempotency_conflict`;
- the same key while the first request still runs is 409 `idempotency_in_progress`;
- 5xx and 429 responses are not stored, so retry them with the same key.

## Pagination

List endpoints answer `{data, links, meta}` (Laravel paginator). `?page=` starts at 1. `?per_page=` defaults to 20,
at most 50. One record is `{data}`.

## Rate limits

120 requests a minute per token, 20 sign-ins a minute per IP, 6 password-reset requests a minute per IP, 6 payment
starts and 30 uploads a minute per user.
MD,
    ],

    'ui' => [
        'title' => 'Inbils Mobile API',
    ],

    /*
     * Load Scramble's development tools on documentation pages. An explicit
     * SCRAMBLE_DEV_TOOLS value takes precedence over APP_DEBUG.
     */
    'dev_tools' => [
        'enabled' => env('SCRAMBLE_DEV_TOOLS', env('APP_DEBUG', false)),
    ],

    'renderer' => 'elements',

    'renderers' => [
        /*
         * Stoplight Elements config options: https://docs.stoplight.io/docs/elements/b074dc47b2826-elements-configuration-options
         */
        'elements' => [
            'view' => 'scramble::docs',
            'theme' => 'light',
            'hideTryIt' => false,
            'hideSchemas' => false,
            'logo' => '',
            'tryItCredentialsPolicy' => 'include',
            'layout' => 'responsive',
            'router' => 'hash',
        ],
        /*
         * Scalar API reference config options: https://scalar.com/products/api-references/configuration
         */
        'scalar' => [
            'view' => 'scramble::scalar',
            'cdn' => 'https://cdn.jsdelivr.net/npm/@scalar/api-reference',
            'theme' => 'laravel',
            'proxyUrl' => 'https://proxy.scalar.com',
            'darkMode' => false,
            'showDeveloperTools' => 'never',
            'agent' => ['disabled' => true],
            'credentials' => 'include',
        ],
    ],

    /*
     * The list of servers of the API. By default, when `null`, server URL will be created from
     * `scramble.api_path` and `scramble.api_domain` config variables. When providing an array, you
     * will need to specify the local server URL manually (if needed).
     *
     * Example of non-default config (final URLs are generated using Laravel `url` helper):
     *
     * ```php
     * 'servers' => [
     *     'Live' => 'api',
     *     'Prod' => 'https://scramble.dedoc.co/api',
     * ],
     * ```
     */
    'servers' => null,

    /**
     * Determines how Scramble stores the descriptions of enum cases.
     * Available options:
     * - 'description' – Case descriptions are stored as the enum schema's description using table formatting.
     * - 'extension' – Case descriptions are stored in the `x-enumDescriptions` enum schema extension.
     *
     *    @see https://redocly.com/docs-legacy/api-reference-docs/specification-extensions/x-enum-descriptions
     * - false - Case descriptions are ignored.
     */
    'enum_cases_description_strategy' => 'description',

    /**
     * Determines how Scramble stores the names of enum cases.
     * Available options:
     * - 'names' – Case names are stored in the `x-enumNames` enum schema extension.
     * - 'varnames' - Case names are stored in the `x-enum-varnames` enum schema extension.
     * - false - Case names are not stored.
     */
    'enum_cases_names_strategy' => false,

    /**
     * When Scramble encounters deep objects in query parameters, it flattens the parameters so the generated
     * OpenAPI document correctly describes the API. Flattening deep query parameters is relevant until
     * OpenAPI 3.2 is released and query string structure can be described properly.
     *
     * For example, this nested validation rule describes the object with `bar` property:
     * `['foo.bar' => ['required', 'int']]`.
     *
     * When `flatten_deep_query_parameters` is `true`, Scramble will document the parameter like so:
     * `{"name":"foo[bar]", "schema":{"type":"int"}, "required":true}`.
     *
     * When `flatten_deep_query_parameters` is `false`, Scramble will document the parameter like so:
     *  `{"name":"foo", "schema": {"type":"object", "properties":{"bar":{"type": "int"}}, "required": ["bar"]}, "required":true}`.
     */
    'flatten_deep_query_parameters' => true,

    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],

    'extensions' => [],

    /*
     * Automatically document API security (OpenAPI `security` / `securitySchemes`) based on route
     * middleware.
     *
     * Disabled by default. Uncomment the line below to enable `MiddlewareAuthSecurityStrategy`.
     * When at least one documented route uses middleware matching the configured patterns (by default
     * `auth` and `auth:*`), bearer auth is applied globally. Routes without matching middleware are
     * marked as public (`security: []`).
     *
     * Set to `null` explicitly to disable. If you already configure security manually via
     * `afterOpenApiGenerated` / `extendOpenApi`, keep this disabled to avoid duplicate schemes.
     *
     * Customize with a class-string or [class, options]:
     *
     * 'security_strategy' => [
     *     \Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy::class,
     *     [
     *         'middleware' => ['auth', 'auth:*'],
     *         'scheme' => \Dedoc\Scramble\Support\Generator\SecurityScheme::http('bearer'),
     *     ],
     * ],
     */
    // Null on purpose: the strategy cannot see the api.any/api.customer/api.technician group aliases, so
    // AppServiceProvider::configureApiDocumentation() marks the bearer scheme and the public routes instead.
    'security_strategy' => null,
];
