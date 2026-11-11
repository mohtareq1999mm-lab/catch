<?php

if (function_exists('config_path') && file_exists(config_path('cors.php'))) {
    return require config_path('cors.php');
}

return [
    'paths' => ['api/*', '*', 'sanctum/csrf-cookie', 'graphql'],
    'allowed_methods' => ['*'],
    'allowed_origins' => (function () {
        $raw = env('CORS_ALLOWED_ORIGINS', '');
        return array_values(array_filter(array_map(fn ($o) => rtrim(trim($o), '/'), explode(',', (string) $raw))));
    })(),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => filter_var(env('CORS_SUPPORTS_CREDENTIALS', true), FILTER_VALIDATE_BOOLEAN),
];
