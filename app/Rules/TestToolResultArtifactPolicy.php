<?php

namespace App\Rules;

use App\Services\TestToolResultPayloadPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class TestToolResultArtifactPolicy implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $message = app(TestToolResultPayloadPolicy::class)
            ->validateArtifactPolicy($value);

        if ($message !== null) {
            $fail($message);
        }
    }
}
