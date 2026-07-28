<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Audit Fail-Safe Policy
    |--------------------------------------------------------------------------
    |
    | Privileged operations should fail closed when their audit event cannot be
    | persisted. Non-privileged telemetry may log and continue, but security and
    | tenant-boundary activity must keep this setting enabled.
    |
    */

    'fail_safe' => env('IDELIUM_AUDIT_FAIL_SAFE', true),
];
