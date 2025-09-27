<?php

$scheme = env('REVERB_SCHEME', env('PUSHER_SCHEME', 'http'));
$usingTls = $scheme === 'https';

return [
    'default' => env('BROADCAST_DRIVER', 'reverb'),

    'connections' => [
        // Native Reverb driver
        'reverb' => [
            'driver' => 'reverb',
            'key'    => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host'   => env('REVERB_HOST', '127.0.0.1'),
                'port'   => env('REVERB_PORT', $usingTls ? 443 : 8080),
                'scheme' => $scheme,
                'useTLS' => $usingTls,
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key'    => env('ABLY_KEY'),
        ],

        'redis' => [
            'driver'     => 'redis',
            'connection' => env('BROADCAST_REDIS_CONNECTION', 'default'),
        ],

        'log' => ['driver' => 'log'],
        'null' => ['driver' => 'null'],
    ],
];
