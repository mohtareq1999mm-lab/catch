<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
    | Allowed origins. The guest_currency cookie is a host-only cookie carried
    | by the browser on every request. For cross-origin Frontend → Backend
    | requests to carry the cookie, `supports_credentials` must be true and the
    | Frontend origin must be listed here (a wildcard "*" is rejected by
    | browsers when credentials are enabled).
    |
    | Local dev example: CORS_ALLOWED_ORIGINS=http://localhost:3000,http://localhost:8000
    | Production example: CORS_ALLOWED_ORIGINS=https://your-frontend.example.com,https://www.your-frontend.example.com
    |
    | IMPORTANT: When CORS_SUPPORTS_CREDENTIALS=true, a wildcard "*" is never
    | returned as Access-Control-Allow-Origin. The middleware echoes the
    | requesting Origin only when it is explicitly listed here.
    */
    'allowed_origins' => (function () {
        $supportsCredentials = filter_var(env('CORS_SUPPORTS_CREDENTIALS', false), FILTER_VALIDATE_BOOLEAN);

        $raw = env('CORS_ALLOWED_ORIGINS', '');

        // Default to wildcard only when credentials are NOT required.
        // When credentials are required, an explicit origin list must be provided.
        if ($raw === '' || $raw === null) {
            $raw = $supportsCredentials ? '' : '*';
        }

        $origins = array_values(array_filter(array_map('trim', explode(',', (string) $raw))));

        // Never allow wildcard with credentials — browsers reject it.
        if ($supportsCredentials) {
            $origins = array_values(array_filter($origins, fn ($o) => $o !== '*'));
        }

        return $origins;
    })(),

    'allowed_origins_patterns' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS_PATTERNS', ''))
    ))),

    /*
    | Allowed headers. Must explicitly list headers used by the Frontend when
    | credentials are enabled (wildcard is not reliably honored with
    | Access-Control-Allow-Credentials: true).
    | We allow the common headers plus lang and x-channel used by Meem.
    */
    'allowed_headers' => array_values(array_filter(array_map('trim', explode(',', env(
        'CORS_ALLOWED_HEADERS',
        'Content-Type,Accept,Authorization,lang,x-channel,X-Channel,X-Requested-With,Origin,X-Currency'
    ))))),

    'exposed_headers' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CORS_EXPOSED_HEADERS', ''))
    ))),

    'max_age' => (int) env('CORS_MAX_AGE', 0),

    /*
    | Whether the response should allow credentialed requests (cookies,
    | authorization headers, TLS client certificates). Enable together with an
    | explicit `allowed_origins` list for the Frontend when the guest_currency
    | cookie must be sent cross-origin. When false (default), the cookie is
    | only sent on same-site requests (e.g. localhost:3000 → localhost:8000).
    */
    'supports_credentials' => filter_var(env('CORS_SUPPORTS_CREDENTIALS', false), FILTER_VALIDATE_BOOLEAN),

];
