<?php

namespace App\Services;

use App\Models\ServiceAccount;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ServiceAccountService
{
    public const TOKEN_PREFIX = 'idsa';

    /**
     * @param array<int, string> $scopes
     * @return array{serviceAccount: ServiceAccount, secret: string}
     */
    public function create(
        int $tenantId,
        string $name,
        ?int $projectId = null,
        array $scopes = [],
        ?\DateTimeInterface $expiresAt = null,
    ): array {
        $credentialId = self::TOKEN_PREFIX.'_'.Str::random(24);
        $secret = Str::random(64);

        $serviceAccount = ServiceAccount::create([
            'idCostumer' => $tenantId,
            'idProject' => $projectId,
            'name' => $name,
            'credentialId' => $credentialId,
            'secretHash' => Hash::make($secret),
            'scopes' => array_values(array_unique($scopes)),
            'expiresAt' => $expiresAt,
        ]);

        return [
            'serviceAccount' => $serviceAccount,
            'secret' => $credentialId.'.'.$secret,
        ];
    }

    public function authenticate(?string $secret): ?ServiceAccount
    {
        if (! is_string($secret) || $secret === '' || ! str_contains($secret, '.')) {
            return null;
        }

        [$credentialId, $plainSecret] = explode('.', $secret, 2);
        if (! str_starts_with($credentialId, self::TOKEN_PREFIX.'_') || $plainSecret === '') {
            return null;
        }

        $serviceAccount = ServiceAccount::where('credentialId', $credentialId)->first();
        if ($serviceAccount === null
            || $serviceAccount->isRevoked()
            || $serviceAccount->isExpired()
            || ! Hash::check($plainSecret, $serviceAccount->secretHash)) {
            return null;
        }

        $serviceAccount->forceFill(['lastUsedAt' => now()])->save();

        return $serviceAccount;
    }

    public function revoke(ServiceAccount $serviceAccount): ServiceAccount
    {
        $serviceAccount->forceFill(['revokedAt' => now()])->save();

        return $serviceAccount;
    }
}
