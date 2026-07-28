<?php

namespace App\Support\Tenancy;

use App\Models\Costumer;
use App\Models\User;

class TenantContext
{
    public function __construct(
        public readonly ?int $actorUserId,
        public readonly int $actorTenantId,
        public readonly int $activeTenantId,
        public readonly ?string $impersonationReason = null,
        public readonly ?string $impersonationExpiresAt = null,
    ) {}

    public static function forUser(
        User $user,
        ?int $activeTenantId = null,
        ?string $impersonationReason = null,
        ?string $impersonationExpiresAt = null,
    ): self
    {
        $actorTenantId = (int) $user->idCostumer;

        return new self(
            actorUserId: (int) $user->id,
            actorTenantId: $actorTenantId,
            activeTenantId: $activeTenantId ?? $actorTenantId,
            impersonationReason: $impersonationReason,
            impersonationExpiresAt: $impersonationExpiresAt,
        );
    }

    public static function forCustomerKey(Costumer $customer): self
    {
        return new self(
            actorUserId: null,
            actorTenantId: (int) $customer->id,
            activeTenantId: (int) $customer->id,
        );
    }

    public function isImpersonating(): bool
    {
        return $this->activeTenantId !== $this->actorTenantId;
    }

    public function activeCustomer(): Costumer
    {
        return Costumer::findOrFail($this->activeTenantId);
    }
}
