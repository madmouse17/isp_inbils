<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Route groups
    |--------------------------------------------------------------------------
    |
    | The operator panel and the tenant app are two separate Inertia shells, so
    | each page only receives the routes of its own shell (resources/views/app.blade.php
    | picks the group). Moving between the shells is always a full page load.
    |
    */

    'groups' => [
        'platform' => ['platform.*'],
        'tenant' => ['!platform.*'],
    ],

];
