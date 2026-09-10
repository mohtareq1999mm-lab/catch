<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Channel
    |--------------------------------------------------------------------------
    |
    | This value determines the default channel when no header is present.
    |
    */
    'default' => env('CHANNEL_DEFAULT', 'home'),

    /*
    |--------------------------------------------------------------------------
    | Accepted Channels
    |--------------------------------------------------------------------------
    |
    | List of valid channel values accepted by the middleware.
    |
    */
    'accepted' => [
        'home',
        'fast-shipping',
    ],

    /*
    |--------------------------------------------------------------------------
    | Header Name
    |--------------------------------------------------------------------------
    |
    | The request header used to determine the active channel.
    |
    */
    'header' => env('CHANNEL_HEADER', 'X-Channel'),

    /*
    |--------------------------------------------------------------------------
    | Strict Mode
    |--------------------------------------------------------------------------
    |
    | When true, invalid channel values cause a 400 Bad Request response.
    | When false, invalid values silently fall back to the default channel.
    |
    */
    'strict' => env('CHANNEL_STRICT', false),

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Enables or disables channel-based filtering globally.
    | Set to false to disable the feature without code changes.
    |
    */
    'enabled' => env('CHANNEL_ENABLED', true),
];
