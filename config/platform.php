<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform host
    |--------------------------------------------------------------------------
    |
    | The operator panel is served under /platform on the application host. When
    | the owner dedicates a host to it, set PLATFORM_DOMAIN: the platform routes
    | then bind to that host only, and the host is added to the trusted hosts.
    | A host that is neither the application host nor one of its subdomains needs
    | its own session and cookie plan (impersonation shares one session).
    |
    */

    'domain' => env('PLATFORM_DOMAIN'),

];
