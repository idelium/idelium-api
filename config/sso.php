<?php

return [
    'state_ttl_seconds' => env('IDELIUM_SSO_STATE_TTL_SECONDS', 300),
    'max_assertion_age_seconds' => env('IDELIUM_SSO_MAX_ASSERTION_AGE_SECONDS', 300),
    'session_state_key' => 'idelium.sso',
];
