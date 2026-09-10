<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Digital Product System
    |--------------------------------------------------------------------------
    |
    | Kill-switch for the digital product subsystem. When disabled, digital
    | fulfillment listeners exit early and download endpoints refuse access.
    |
    */

    'enabled' => env('DIGITAL_PRODUCTS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Asset Type Registry
    |--------------------------------------------------------------------------
    |
    | Single source of truth for digital asset validation and delivery
    | metadata. Consumed by App\Services\Digital\AssetTypeRegistry; never
    | hardcode extensions/MIME rules elsewhere.
    |
    | Each FILE category declares its full target `extensions` / `mime_types`
    | plus an `active_extensions` / `active_mime_types` subset which is what
    | the CURRENT upload pipeline accepts. Only DOCUMENT(pdf) is active —
    | Workstream 4 widens the active subsets once server-side content
    | sniffing ships.
    |
    */

    // A1: software/packaged binaries stay rejected until ops explicitly
    // enables them AND Workstream 4 hardens the pipeline for them.
    'allow_software_assets' => env('DIGITAL_ALLOW_SOFTWARE_ASSETS', false),

    'asset_types' => [
        \App\Enums\DigitalAssetType::FILE->value => [
            'enabled' => true,
            'downloadable' => true,
            'streamable' => false,
            'previewable' => true,
            'url_allowed' => false,
            'checksum_required' => true,
            'requires_secret' => false,

            'categories' => [
                \App\Enums\DigitalAssetCategory::DOCUMENT->value => [
                    'extensions' => ['pdf', 'epub', 'doc', 'docx', 'txt', 'rtf', 'odt'],
                    'mime_types' => [
                        'application/pdf',
                        'application/x-pdf',
                        'application/epub+zip',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'text/plain',
                        'application/rtf',
                        'application/vnd.oasis.opendocument.text',
                    ],
                    // Transitional: exact parity with the legacy PDF-only flow.
                    'active_extensions' => ['pdf'],
                    'active_mime_types' => ['application/pdf', 'application/x-pdf'],
                    'streamable' => false,
                    'previewable' => true,
                ],

                \App\Enums\DigitalAssetCategory::SPREADSHEET->value => [
                    'extensions' => ['xls', 'xlsx', 'csv', 'ods'],
                    'mime_types' => [
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'text/csv',
                        'application/vnd.oasis.opendocument.spreadsheet',
                    ],
                    'active_extensions' => [],
                    'active_mime_types' => [],
                    'streamable' => false,
                    'previewable' => false,
                ],

                \App\Enums\DigitalAssetCategory::PRESENTATION->value => [
                    'extensions' => ['ppt', 'pptx', 'odp'],
                    'mime_types' => [
                        'application/vnd.ms-powerpoint',
                        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                        'application/vnd.oasis.opendocument.presentation',
                    ],
                    'active_extensions' => [],
                    'active_mime_types' => [],
                    'streamable' => false,
                    'previewable' => false,
                ],

                \App\Enums\DigitalAssetCategory::ARCHIVE->value => [
                    'extensions' => ['zip', '7z', 'tar', 'gz', 'tgz'],
                    'mime_types' => [
                        'application/zip',
                        'application/x-7z-compressed',
                        'application/x-tar',
                        'application/gzip',
                        'application/x-zip-compressed',
                    ],
                    'active_extensions' => [],
                    'active_mime_types' => [],
                    'streamable' => false,
                    'previewable' => false,
                ],

                \App\Enums\DigitalAssetCategory::AUDIO->value => [
                    'extensions' => ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'flac'],
                    'mime_types' => [
                        'audio/mpeg',
                        'audio/wav',
                        'audio/x-wav',
                        'audio/mp4',
                        'audio/aac',
                        'audio/ogg',
                        'audio/flac',
                    ],
                    'active_extensions' => ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'flac'],
                    'active_mime_types' => [
                        'audio/mpeg','audio/wav','audio/x-wav','audio/mp4','audio/aac','audio/ogg','audio/flac',
                    ],
                    'streamable' => true,
                    'previewable' => true,
                ],

                \App\Enums\DigitalAssetCategory::VIDEO->value => [
                    'extensions' => ['mp4', 'webm', 'mov', 'mkv'],
                    'mime_types' => [
                        'video/mp4',
                        'video/webm',
                        'video/quicktime',
                        'video/x-matroska',
                    ],
                    // W7/A3 activated: HTTP Range streaming for entitled users.
                    'active_extensions' => ['mp4', 'webm', 'mov', 'mkv'],
                    'active_mime_types' => [
                        'video/mp4',
                        'video/webm',
                        'video/quicktime',
                        'video/x-matroska',
                    ],
                    'streamable' => true,
                    'previewable' => true,
                ],

                \App\Enums\DigitalAssetCategory::IMAGE->value => [
                    'extensions' => ['jpg', 'jpeg', 'png', 'webp', 'svg', 'gif', 'tif', 'tiff'],
                    'mime_types' => [
                        'image/jpeg',
                        'image/png',
                        'image/webp',
                        'image/svg+xml',
                        'image/gif',
                        'image/tiff',
                    ],
                    'active_extensions' => [],
                    'active_mime_types' => [],
                    'streamable' => false,
                    'previewable' => true,
                ],

                // A1: recognized only when allow_software_assets = true.
                \App\Enums\DigitalAssetCategory::SOFTWARE->value => [
                    'extensions' => ['exe', 'msi', 'dmg', 'apk', 'ipa', 'appimage'],
                    'mime_types' => [
                        'application/vnd.microsoft.portable-executable',
                        'application/x-msdownload',
                        'application/x-msi',
                        'application/x-apple-diskimage',
                        'application/vnd.android.package-archive',
                        'application/octet-stream',
                    ],
                    'active_extensions' => [],
                    'active_mime_types' => [],
                    'streamable' => false,
                    'previewable' => false,
                ],
            ],
        ],

        \App\Enums\DigitalAssetType::URL->value => [
            'enabled' => true,
            'downloadable' => false,
            'streamable' => false,
            'previewable' => false,
            'url_allowed' => true,
            'checksum_required' => false,
            'requires_secret' => false,
            'categories' => [],
        ],

        \App\Enums\DigitalAssetType::LICENSE->value => [
            'enabled' => true,
            'downloadable' => false,
            'streamable' => false,
            'previewable' => false,
            'url_allowed' => false,
            'checksum_required' => false,
            'requires_secret' => true,
            'categories' => [],
        ],

        \App\Enums\DigitalAssetType::ACCESS->value => [
            'enabled' => true,
            'downloadable' => false,
            'streamable' => false,
            'previewable' => false,
            'url_allowed' => false,
            'checksum_required' => false,
            'requires_secret' => true,
            'categories' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | External URLs (Workstream 5)
    |--------------------------------------------------------------------------
    |
    | The server NEVER fetches a stored external URL — authorization happens
    | on the customer endpoints and the client browses the resource directly.
    | Validation is therefore static + one-time DNS resolution at creation;
    | redirect targets cannot be re-validated because no request is proxied.
    |
    */

    'external_urls' => [
        'allowed_schemes' => ['https'],
        'blocked_hostnames' => ['localhost'],
        'blocked_suffixes' => ['.local', '.internal', '.localhost'],
        // Non-empty = strict allowlist of public hosts.
        'allowed_hostnames' => [],
        'allow_userinfo' => false,
        'max_length' => 2048,
    ],

    /*
    |--------------------------------------------------------------------------
    | Licenses (Workstream 5, decision A2)
    |--------------------------------------------------------------------------
    */

    'licenses' => [
        // One-time reveal: once revealed_at is set, further reveals are refused.
        'one_time_reveal' => env('DIGITAL_LICENSE_ONE_TIME_REVEAL', true),
        'max_batch_keys' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy upload constraints (TRANSITIONAL)
    |--------------------------------------------------------------------------
    |
    | max_upload_kb remains LIVE: it is the global size fallback used by the
    | registry when a category does not define its own limit.
    |
    | allowed_mimes / allowed_mime_types are DEPRECATED — the registry's
    | per-category active_* lists superseded them. They are kept only so
    | external tooling reading this file keeps working during migration;
    | no runtime code consumes them anymore.
    |
    */

    'allowed_mimes' => ['pdf'],
    'allowed_mime_types' => ['application/pdf', 'application/x-pdf'],
    'max_upload_kb' => env('DIGITAL_MAX_UPLOAD_KB', 20480),

    /*
    |--------------------------------------------------------------------------
    | Downloads
    |--------------------------------------------------------------------------
    */

    'download_limit' => (int) env('DIGITAL_DOWNLOAD_LIMIT', 5),

    /*
    | Signed download URL lifetime in minutes.
    */
    'signed_url_ttl_minutes' => (int) env('DIGITAL_SIGNED_URL_TTL', 30),
];
