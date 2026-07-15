<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function create(User $actor, User $candidate): bool
    {
        return $this->canManage($actor, $candidate);
    }

    public function update(User $actor, User $target): bool
    {
        return $this->canManage($actor, $target);
    }

    public function delete(User $actor, User $target): bool
    {
        return $this->canManage($actor, $target);
    }

    private function canManage(User $actor, User $target): bool
    {
        if ((int) $actor->role === 1) {
            return true;
        }

        return (int) $actor->role === 2
            && (int) $target->role > 1
            && (int) $target->idCostumer === (int) $actor->idCostumer;
    }
}
