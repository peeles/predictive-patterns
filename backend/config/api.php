<?php

return [
    'tokens' => array_values(array_filter(array_map(
        static fn (string $token): string => trim($token),
        explode(',', (string) env('API_TOKENS', ''))
    ))),
    'rate_limit' => (int) env('API_RATE_LIMIT', 60),
];
