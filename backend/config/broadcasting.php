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
            $reverbScheme = strtolower((string) $resolve('REVERB_SCHEME', 'PUSHER_SCHEME', 'http'));

            $reverb = [
                'driver' => 'reverb',
                'key' => $resolve('REVERB_APP_KEY', 'PUSHER_APP_KEY'),
                'secret' => $resolve('REVERB_APP_SECRET', 'PUSHER_APP_SECRET'),
                'app_id' => $resolve('REVERB_APP_ID', 'PUSHER_APP_ID'),
                'options' => [
                    'host' => $resolve('REVERB_HOST', 'PUSHER_HOST', '127.0.0.1'),
                    'port' => (int) $resolve('REVERB_PORT', 'PUSHER_PORT', 8080),
                    'scheme' => $reverbScheme,
                    'useTLS' => $reverbScheme === 'https',
                ],
            ];

            $pusherConfigured = static function () {
                foreach (['PUSHER_APP_ID', 'PUSHER_APP_KEY', 'PUSHER_APP_SECRET'] as $key) {
                    $value = env($key);

                    if ($value === null || $value === '') {
                        return false;
                    }
                }

                return true;
            };

            $pusherScheme = strtolower((string) env('PUSHER_SCHEME', 'https'));
            $pusherOptions = [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'useTLS' => $pusherScheme === 'https',
            ];

            if ($pusherScheme !== '') {
                $pusherOptions['scheme'] = $pusherScheme;
            }

            if (($host = env('PUSHER_HOST')) !== null && $host !== '') {
                $pusherOptions['host'] = $host;
            }

            if (($port = env('PUSHER_PORT')) !== null && $port !== '') {
                $pusherOptions['port'] = (int) $port;
            }

            return [
                'reverb' => $reverb,
                'pusher' => $pusherConfigured()
                    ? [
                        'driver' => 'pusher',
                        'key' => env('PUSHER_APP_KEY'),
                        'secret' => env('PUSHER_APP_SECRET'),
                        'app_id' => env('PUSHER_APP_ID'),
                        'options' => $pusherOptions,
                    ]
                    : $reverb,
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
