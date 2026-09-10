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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'myfatoorah' => [
        'api_key' => env('MYFATOORAH_API_KEY'),
        'base_url' => env('MYFATOORAH_BASE_URL', 'https://apitest.myfatoorah.com/v2/'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM_NUMBER'),
        'enabled' => env('TWILIO_ENABLED', false),
    ],

    'fcm' => [
        'credentials' => env('FCM_CREDENTIALS_PATH'),
        'enabled' => env('FCM_ENABLED', false),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

];
