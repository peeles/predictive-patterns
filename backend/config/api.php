<?php

use App\Enums\Role;

return [
    'rate_limits' => [
        Role::Admin->value => (int) env('API_RATE_LIMIT_ADMIN', 240),
        Role::Analyst->value => (int) env('API_RATE_LIMIT_ANALYST', 120),
        Role::Viewer->value => (int) env('API_RATE_LIMIT_VIEWER', 60),
    ],
    'idempotency_ttl' => (int) env('API_IDEMPOTENCY_TTL', 300),
    'payload_limits' => [
        'ingest' => (int) env('API_PAYLOAD_MAX_KB', 20_480),
        'predict' => (int) env('API_PREDICT_MAX_KB', 10_240),
    ],
    'allowed_ingest_mimes' => array_values(array_filter(array_map(
        static fn (string $mime): string => trim($mime),
        explode(',', (string) env('API_ALLOWED_INGEST_MIMES', 'text/csv,application/json,application/geo+json'))
    ))),
];
