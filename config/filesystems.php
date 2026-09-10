<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application. Just store away!
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been setup for each driver as an example of the required options.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
        ],

        'private' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'visibility' => 'private',
        ],

        'imports' => [
            'driver' => 'local',
            'root' => storage_path('app/private/imports'),
            'visibility' => 'private',
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL') . '/storage',
            'visibility' => 'public',
        ],
        'categories' => [
            'driver' => 'local',
            'root' => storage_path('app/public/categories'),
            'url' => env('APP_URL') . '/storage/categories',
            'visibility' => 'public',
        ],
        'shops' => [
            'driver' => 'local',
            'root' => storage_path('app/public/shops'),
            'url' => env('APP_URL') . '/storage/shops',
            'visibility' => 'public',
        ],
        'settings' => [
            'driver' => 'local',
            'root' => storage_path('app/public/settings'),
            'url' => env('APP_URL') . '/storage/settings',
            'visibility' => 'public',
        ],
        'users' => [
            'driver' => 'local',
            'root' => storage_path('app/public/users'),
            'url' => env('APP_URL') . '/storage/users',
            'visibility' => 'public',
        ],
        'banners' => [
            'driver' => 'local',
            'root' => storage_path('app/public/banners'),
            'url' => env('APP_URL') . '/storage/banners',
            'visibility' => 'public',
        ],
        'sliders' => [
            'driver' => 'local',
            'root' => storage_path('app/public/sliders'),
            'url' => env('APP_URL') . '/storage/sliders',
            'visibility' => 'public',
        ],
        'products' => [
            'driver' => 'local',
            'root' => storage_path('app/public/products'),
            'url' => env('APP_URL') . '/storage/products',
            'visibility' => 'public',
        ],
        'brands' => [
            'driver' => 'local',
            'root' => storage_path('app/public/brands'),
            'url' => env('APP_URL') . '/storage/brands',
            'visibility' => 'public',
        ],
        'coupons' => [
            'driver' => 'local',
            'root' => storage_path('app/public/coupons'),
            'url' => env('APP_URL') . '/storage/coupons',
            'visibility' => 'public',
        ],
        'reviews' => [
            'driver' => 'local',
            'root' => storage_path('app/public/reviews'),
            'url' => env('APP_URL') . '/storage/reviews',
            'visibility' => 'public',
        ],
        'flashSales' => [
            'driver' => 'local',
            'root' => storage_path('app/public/flash-sales'),
            'url' => env('APP_URL') . '/storage/flash-sales',
            'visibility' => 'public',
        ],
        'promotions' => [
            'driver' => 'local',
            'root' => storage_path('app/public/promotions'),
            'url' => env('APP_URL') . '/storage/promotions',
            'visibility' => 'public',
        ],
        'tags' => [
            'driver' => 'local',
            'root' => storage_path('app/public/tags'),
            'url' => env('APP_URL') . '/storage/tags',
            'visibility' => 'public',
        ],
        'static-pages' => [
            'driver' => 'local',
            'root' => storage_path('app/public/static-pages'),
            'url' => env('APP_URL') . '/storage/static-pages',
            'visibility' => 'public',
        ],
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'visibility' => 'public',
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
