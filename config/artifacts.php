<?php

return [
    'max_size_bytes' => env('IDELIUM_ARTIFACT_MAX_SIZE_BYTES', 50 * 1024 * 1024),
    'default_retention_days' => env('IDELIUM_ARTIFACT_RETENTION_DAYS', 30),
    'allowed_content_types' => [
        'application/json',
        'application/junit+xml',
        'application/xml',
        'text/markdown',
        'text/html',
        'text/plain',
        'image/png',
        'image/jpeg',
    ],
];
