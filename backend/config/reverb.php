<?php

return [
    'apps' => [
        (function () {
            $origins = array_filter(array_map('trim', explode(',', env('REVERB_ALLOWED_ORIGINS', '*'))));
            if (empty($origins)) {
                $origins = ['*'];
            }

            return [
                'app_id' => env('REVERB_APP_ID', 'predictive-patterns'),
                'key' => env('REVERB_APP_KEY', 'local-key'),
                'secret' => env('REVERB_APP_SECRET', 'local-secret'),
                'allowed_origins' => $origins,
                'enable_client_messages' => env('REVERB_CLIENT_MESSAGES', false),
                'enable_statistics' => env('REVERB_ENABLE_STATISTICS', false),
            ];
        })(),
    ],

    'server' => [
        'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
        'port' => env('REVERB_SERVER_PORT', env('REVERB_PORT', 8080)),
        'mode' => env('REVERB_SERVER_MODE', 'worker'),
    ],

    'metrics' => [
        'enabled' => env('REVERB_METRICS_ENABLED', false),
    ],
];
