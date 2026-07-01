<?php

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
            'root' => storage_path('app/private'),
            // No `serve` here: the signed /storage serve route belongs to the `private` disk below
            // (two local disks can't both serve at /storage). Sensitive uploads use `private`.
            'throw' => false,
            'report' => false,
        ],

        // Private disk for sensitive uploads (donation proofs, rescue photos, intake docs).
        // It is NEVER publicly served — there is deliberately no `url`, so files are reachable
        // only through short-lived signed links (Storage::disk('private')->temporaryUrl()).
        //
        // The driver is env-driven so the files persist in every environment:
        //   - dev / tests: `local` (storage/app/private); `serve => true` registers the signed
        //     local-serve route that temporaryUrl() points at.
        //   - prod: `s3` against a PRIVATE R2/S3 bucket (no public access) so uploads survive
        //     container redeploys; temporaryUrl() presigns natively. Use a SEPARATE bucket from the
        //     public one (PRIVATE_AWS_BUCKET) so objects aren't exposed via the public bucket URL.
        'private' => [
            'driver' => env('PRIVATE_FILESYSTEM_DRIVER', 'local'),
            // local driver
            'root' => storage_path('app/private'),
            'serve' => true,
            // s3 driver (used when PRIVATE_FILESYSTEM_DRIVER=s3) — falls back to the public AWS_*
            // credentials/region/endpoint, but the bucket must be its own private bucket.
            'key' => env('PRIVATE_AWS_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('PRIVATE_AWS_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('PRIVATE_AWS_DEFAULT_REGION', env('AWS_DEFAULT_REGION', 'auto')),
            'bucket' => env('PRIVATE_AWS_BUCKET'),
            'endpoint' => env('PRIVATE_AWS_ENDPOINT', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
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

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
