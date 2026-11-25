<?php

declare(strict_types=1);

return [
    /*
     * #186: recipient identity normalization.
     *
     * Fingerprints are HMAC-SHA256 over a channel-prefixed normalized contact
     * using a dedicated secret. The secret is intentionally separate from the
     * application key so that rotating APP_KEY never rewrites identities.
     * Missing secret => fail closed; never fall back to a predictable key.
     */
    'fingerprint_secret' => env('RECIPIENT_IDENTITY_FINGERPRINT_SECRET'),

    // Version of the current fingerprint hash. Aliases keep older versions so
    // rotation preserves the canonical recipient.
    'key_version' => env('RECIPIENT_IDENTITY_KEY_VERSION', 'v1'),

    /*
     * Reliable country context for E.164 normalization. A local number is only
     * normalized when the caller supplies one of these ISO-3166 alpha-2 codes;
     * the service never guesses a country or reinterprets an ambiguous local
     * number. Extend the map through deployment configuration.
     */
    'country_dial_codes' => [
        'AE' => '+971',
        'BH' => '+973',
        'CA' => '+1',
        'DE' => '+49',
        'DZ' => '+213',
        'EG' => '+20',
        'FR' => '+33',
        'GB' => '+44',
        'IQ' => '+964',
        'JO' => '+962',
        'KW' => '+965',
        'LB' => '+961',
        'LY' => '+218',
        'MA' => '+212',
        'OM' => '+968',
        'PS' => '+970',
        'QA' => '+974',
        'SA' => '+966',
        'SD' => '+249',
        'TN' => '+216',
        'TR' => '+90',
        'US' => '+1',
    ],
];
