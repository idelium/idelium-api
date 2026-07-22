<?php

namespace App\Rules;

use App\Services\TestToolSchemaRegistry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class TestToolSchemaPayload implements ValidationRule
{
    public function __construct(private readonly string $scope) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $message = app(TestToolSchemaRegistry::class)->validatePayload(
            $value,
            $this->scope
        );

        if ($message !== null) {
            $fail($message);
        }
    }
}
