<?php

return [
    'schema_version' => '2026-07-28.v1',
    'signature_tolerance_seconds' => env('IDELIUM_WEBHOOK_SIGNATURE_TOLERANCE', 300),
    'timeout_seconds' => env('IDELIUM_WEBHOOK_TIMEOUT_SECONDS', 5),
    'max_attempts' => env('IDELIUM_WEBHOOK_MAX_ATTEMPTS', 3),
    'retry_backoff_seconds' => [
        60,
        300,
        900,
    ],
    'allowed_adapters' => [
        'webhook',
        'jira',
        'slack',
        'teams',
    ],
];
