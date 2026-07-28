<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class CapabilityService
{
    /**
     * @return array<int, string>
     */
    public function forUser(User $user): array
    {
        return array_values(array_unique(
            config('capabilities.roles.'.(int) $user->role, [])
        ));
    }

    public function has(User $user, string $capability): bool
    {
        return in_array($capability, $this->forUser($user), true);
    }

    /**
     * @throws AuthorizationException
     */
    public function require(User $user, string $capability): void
    {
        if (! $this->has($user, $capability)) {
            throw new AuthorizationException(
                'The requested action is not authorized.',
                403
            );
        }
    }

    public function version(): string
    {
        return (string) config('capabilities.version');
    }
}
