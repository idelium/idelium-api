<?php

namespace App\Services;

class PasswordPolicy
{
    /**
     * @return array<int, string>
     */
    public function violations(string $password): array
    {
        $violations = [];
        $minLength = (int) config('password_policy.min_length', 12);

        if (mb_strlen($password) < $minLength) {
            $violations[] = "The password must be at least {$minLength} characters.";
        }

        if ((bool) config('password_policy.require_mixed_case', true)
            && (! preg_match('/[a-z]/', $password) || ! preg_match('/[A-Z]/', $password))) {
            $violations[] = 'The password must contain both uppercase and lowercase letters.';
        }

        if ((bool) config('password_policy.require_number', true)
            && ! preg_match('/[0-9]/', $password)) {
            $violations[] = 'The password must contain at least one number.';
        }

        if ((bool) config('password_policy.require_symbol', true)
            && ! preg_match('/[^A-Za-z0-9]/', $password)) {
            $violations[] = 'The password must contain at least one symbol.';
        }

        if ($this->isCommonPassword($password)) {
            $violations[] = 'The password is too common.';
        }

        return $violations;
    }

    public function passes(string $password): bool
    {
        return $this->violations($password) === [];
    }

    private function isCommonPassword(string $password): bool
    {
        if (! (bool) config('password_policy.reject_common', true)) {
            return false;
        }

        $normalized = mb_strtolower(trim($password));
        $commonPasswords = array_map(
            static fn ($value): string => mb_strtolower((string) $value),
            config('password_policy.common_passwords', [])
        );

        return in_array($normalized, $commonPasswords, true);
    }
}
