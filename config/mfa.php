<?php

return [
    'issuer' => env('IDELIUM_MFA_ISSUER', 'Idelium'),
    'totp_period_seconds' => env('IDELIUM_MFA_TOTP_PERIOD_SECONDS', 30),
    'totp_digits' => env('IDELIUM_MFA_TOTP_DIGITS', 6),
    'totp_window' => env('IDELIUM_MFA_TOTP_WINDOW', 1),
    'recovery_code_count' => env('IDELIUM_MFA_RECOVERY_CODE_COUNT', 8),
    'step_up_ttl_seconds' => env('IDELIUM_MFA_STEP_UP_TTL_SECONDS', 900),
];
