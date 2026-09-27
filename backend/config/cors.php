<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'up', 'health'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',',
        env('SASA_CORS_ORIGINS', env('SASA_WEB_URL', 'http://localhost:3000').',http://localhost:3000,http://127.0.0.1:3000')
    )))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-Sasa-Request-Id', 'X-Sasa-Sync-Cursor'],
    'max_age' => 3600,
    'supports_credentials' => true,
];
