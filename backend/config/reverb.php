<?php

return (function () {
    $resolve = static function (string $reverbKey, string $pusherKey, $default = null) {
        $value = env($reverbKey);
        if ($value !== null) {
            return $value;
        }

        $fallback = env($pusherKey);
        if ($fallback !== null) {
            return $fallback;
        }

        return $default;
    };

    return [
        'apps' => [
            (function () use ($resolve) {
                $origins = array_filter(array_map('trim', explode(',', (string) $resolve('REVERB_ALLOWED_ORIGINS', 'PUSHER_ALLOWED_ORIGINS', '*'))));
                if (empty($origins)) {
                    $origins = ['*'];
                }

                return [
                    'app_id' => $resolve('REVERB_APP_ID', 'PUSHER_APP_ID', 'predictive-patterns'),
                    'key' => $resolve('REVERB_APP_KEY', 'PUSHER_APP_KEY', 'local-key'),
                    'secret' => $resolve('REVERB_APP_SECRET', 'PUSHER_APP_SECRET', 'local-secret'),
                    'allowed_origins' => $origins,
                    'enable_client_messages' => filter_var($resolve('REVERB_CLIENT_MESSAGES', 'PUSHER_CLIENT_MESSAGES', false), FILTER_VALIDATE_BOOL),
                    'enable_statistics' => filter_var($resolve('REVERB_ENABLE_STATISTICS', 'PUSHER_ENABLE_STATISTICS', false), FILTER_VALIDATE_BOOL),
                ];
            })(),
        ],

        'server' => [
            'host' => $resolve('REVERB_SERVER_HOST', 'PUSHER_SERVER_HOST', '0.0.0.0'),
            'port' => (int) ($resolve('REVERB_SERVER_PORT', 'PUSHER_SERVER_PORT', $resolve('REVERB_PORT', 'PUSHER_PORT', 8080))),
            'mode' => $resolve('REVERB_SERVER_MODE', 'PUSHER_SERVER_MODE', 'worker'),
        ],

        'metrics' => [
            'enabled' => filter_var($resolve('REVERB_METRICS_ENABLED', 'PUSHER_METRICS_ENABLED', false), FILTER_VALIDATE_BOOL),
        ],
    ];
})();
