<?php

return [
    'ttl_seconds' => env('IDELIUM_RUN_TOKEN_TTL_SECONDS', 300),
    'require_for_claim' => env('IDELIUM_RUN_TOKEN_REQUIRED_FOR_CLAIM', true),
];
