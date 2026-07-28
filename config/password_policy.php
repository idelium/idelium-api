<?php

return [
    'min_length' => env('IDELIUM_PASSWORD_MIN_LENGTH', 12),
    'require_mixed_case' => env('IDELIUM_PASSWORD_REQUIRE_MIXED_CASE', true),
    'require_number' => env('IDELIUM_PASSWORD_REQUIRE_NUMBER', true),
    'require_symbol' => env('IDELIUM_PASSWORD_REQUIRE_SYMBOL', true),
    'reject_common' => env('IDELIUM_PASSWORD_REJECT_COMMON', true),
    'common_passwords' => [
        'admin',
        'password',
        'password1',
        'password123',
        'qwerty',
        'qwerty123',
        'letmein',
        'welcome',
        'welcome1',
        'changeme',
        'idelium',
        'idelium123',
    ],
];
