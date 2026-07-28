<?php

namespace App\Library;

use RuntimeException;

class TestLauncherException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus
    ) {
        parent::__construct($message);
    }
}
