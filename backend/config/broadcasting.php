<?php

return (function () {
    $driver = env('BROADCAST_DRIVER', 'reverb');
    if ($driver === 'pusher') {
        $driver = 'reverb';
    }

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
        'default' => $driver,

        'connections' => (function () use ($resolve) {
            $scheme = strtolower((string) $resolve('REVERB_SCHEME', 'PUSHER_SCHEME', 'http'));

            $reverb = [
                'driver' => 'reverb',
                'key' => $resolve('REVERB_APP_KEY', 'PUSHER_APP_KEY'),
                'secret' => $resolve('REVERB_APP_SECRET', 'PUSHER_APP_SECRET'),
                'app_id' => $resolve('REVERB_APP_ID', 'PUSHER_APP_ID'),
                'options' => [
                    'host' => $resolve('REVERB_HOST', 'PUSHER_HOST', '127.0.0.1'),
                    'port' => (int) $resolve('REVERB_PORT', 'PUSHER_PORT', 8080),
                    'scheme' => $scheme,
                    'useTLS' => $scheme === 'https',
                ],
            ];

            return [
                'reverb' => $reverb,
                'pusher' => $reverb,
                'ably' => [
                    'driver' => 'ably',
                    'key' => env('ABLY_KEY'),
                ],

                'redis' => [
                    'driver' => 'redis',
                    'connection' => env('BROADCAST_REDIS_CONNECTION', 'default'),
                ],

                'log' => [
                    'driver' => 'log',
                ],

                'null' => [
                    'driver' => 'null',
                ],
            ];
        })(),
    ];
})();
