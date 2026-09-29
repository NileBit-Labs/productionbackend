<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
        fn (string $origin): bool => $origin !== '' && $origin !== '*',
    )),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Shop-Id'],
    'exposed_headers' => [],
    'max_age' => 3600,
    'supports_credentials' => false,
];
