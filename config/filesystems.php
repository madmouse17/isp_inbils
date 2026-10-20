<?php

/*
|--------------------------------------------------------------------------
| One storage tree per environment
|--------------------------------------------------------------------------
|
| PHPUnit, the E2E server and the dev site each have their own database, so the same company id means different
| companies in each, and their files used to land in the same `companies/{id}` folders. STORAGE_ENV_SUFFIX puts every
| environment under its own directory of the same parents: phpunit.xml pins `/testing`, scripts/e2e-server-env.mjs
| pins `/e2e`, dev and production leave it empty and keep the roots they always had, so no file moves. A file is still
| addressed as `companies/{id}/...` relative to its disk. This is the only place the key is read.
|
*/
$storageSuffix = (string) env('STORAGE_ENV_SUFFIX', '');

if (preg_match('#^(/[A-Za-z0-9_-]+)?$#D', $storageSuffix) !== 1) {
    throw new InvalidArgumentException('STORAGE_ENV_SUFFIX must be empty or one directory name with a leading slash, such as /e2e.');
}

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'.$storageSuffix),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'.$storageSuffix),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage'.$storageSuffix,
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    // The link stays on the base public root: `/storage/e2e/...` is a folder under the one directory it points to.
    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
