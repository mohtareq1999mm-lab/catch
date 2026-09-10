<?php

return [
    'clients' => [
        'client_a' => [
            'project_id' => env('FIREBASE_CLIENT_A_PROJECT_ID'),
            'credentials' => env('FIREBASE_CLIENT_A_CREDENTIALS'),
        ],
        'client_b' => [
            'project_id' => env('FIREBASE_CLIENT_B_PROJECT_ID'),
            'credentials' => env('FIREBASE_CLIENT_B_CREDENTIALS'),
        ],
    ],

    'credential_storage_path' => 'firebase',
];