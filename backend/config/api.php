<?php

use App\Enums\Role;
use Illuminate\Support\Collection;

return [
    'tokens' => Collection::make(explode(',', (string) env('API_TOKENS', '')))
        ->map(static function (string $entry): array {
            $parts = array_values(array_filter(array_map('trim', explode(':', $entry, 2))));

            if ($parts === []) {
                return [];
            }

            $token = $parts[0];
            $role = Role::tryFrom($parts[1] ?? '') ?? Role::Viewer;

            return ['token' => $token, 'role' => $role];
        })
        ->filter(fn (array $token): bool => ($token['token'] ?? '') !== '')
        ->values()
        ->all(),
    'rate_limits' => [
        Role::Admin->value => (int) env('API_RATE_LIMIT_ADMIN', 240),
        Role::Analyst->value => (int) env('API_RATE_LIMIT_ANALYST', 120),
        Role::Viewer->value => (int) env('API_RATE_LIMIT_VIEWER', 60),
    ],
    'payload_limits' => [
        'ingest' => (int) env('API_PAYLOAD_MAX_KB', 20_480),
        'predict' => (int) env('API_PREDICT_MAX_KB', 10_240),
    ],
    'allowed_ingest_mimes' => array_values(array_filter(array_map(
        static fn (string $mime): string => trim($mime),
        explode(',', (string) env('API_ALLOWED_INGEST_MIMES', 'text/csv,application/json,application/geo+json'))
    ))),
];
