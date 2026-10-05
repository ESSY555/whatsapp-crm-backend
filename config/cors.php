<?php

return [

    /*
    | Cross-Origin Resource Sharing. The API is consumed by the Crediping
    | frontend with bearer tokens, so credentials are not needed and the
    | allowed origins are restricted to the configured frontend URL(s).
    */

    'paths' => ['api/*', 'up'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:5173')))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
