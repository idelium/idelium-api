<?php

namespace App\Services;

use App\Models\IdentityProvider;
use App\Models\Role;
use App\Models\ScimIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IdentityLifecycleService
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array{user: User, scimIdentity: ScimIdentity, created: bool}
     */
    public function upsertScimUser(IdentityProvider $provider, array $attributes): array
    {
        $this->assertProviderUsable($provider);

        return DB::transaction(function () use ($provider, $attributes): array {
            $externalId = (string) $attributes['externalId'];
            $email = strtolower((string) $attributes['userName']);
            $groups = array_values(array_unique($attributes['groups'] ?? []));
            $active = (bool) ($attributes['active'] ?? true);
            $role = $this->roleForGroups($provider, $groups);

            $identity = ScimIdentity::query()
                ->where('idCostumer', $provider->idCostumer)
                ->where('identityProviderId', $provider->id)
                ->where('externalId', $externalId)
                ->first();

            $user = $identity?->userId
                ? User::whereKey($identity->userId)->where('idCostumer', $provider->idCostumer)->first()
                : null;
            $user ??= User::query()
                ->where('idCostumer', $provider->idCostumer)
                ->where('email', $email)
                ->first();

            if ($user instanceof User && (bool) $user->isBreakGlass) {
                throw ValidationException::withMessages([
                    'userName' => ['SCIM cannot modify a break-glass account.'],
                ]);
            }

            $created = ! $user instanceof User;
            if (! $user instanceof User) {
                $user = new User;
                $user->password = Hash::make(Str::random(64));
                $user->email_verified_at = now();
                $user->idCostumer = $provider->idCostumer;
            }

            $user->name = (string) ($attributes['displayName'] ?? $email);
            $user->email = $email;
            $user->role = $role;
            $user->status = $active ? 'active' : 'disabled';
            $user->identityProviderId = $provider->id;
            $user->externalId = $externalId;
            $user->mfaRequired = true;
            $user->save();

            if (! $active) {
                $user->tokens()->delete();
            }

            $identity = ScimIdentity::updateOrCreate([
                'idCostumer' => $provider->idCostumer,
                'identityProviderId' => $provider->id,
                'externalId' => $externalId,
            ], [
                'userId' => $user->id,
                'userName' => $email,
                'groups' => $groups,
                'active' => $active,
                'lastSyncedAt' => now(),
            ]);

            return [
                'user' => $user,
                'scimIdentity' => $identity,
                'created' => $created,
            ];
        });
    }

    public function markBreakGlass(User $user, string $reason, bool $enabled): User
    {
        $user->forceFill([
            'isBreakGlass' => $enabled,
            'breakGlassReason' => $enabled ? $reason : null,
            'mfaRequired' => $enabled ? false : (bool) $user->mfaRequired,
            'status' => $enabled ? 'active' : ($user->status ?? 'active'),
        ])->save();

        return $user;
    }

    public function recordBreakGlassTest(User $user): User
    {
        if (! (bool) $user->isBreakGlass) {
            throw ValidationException::withMessages([
                'account' => ['Only break-glass accounts can record break-glass access tests.'],
            ]);
        }

        $user->forceFill([
            'lastBreakGlassTestAt' => now(),
        ])->save();

        return $user;
    }

    private function assertProviderUsable(IdentityProvider $provider): void
    {
        if ($provider->type !== IdentityProvider::TYPE_SCIM || $provider->status !== IdentityProvider::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'identityProvider' => ['The SCIM identity provider is not active.'],
            ]);
        }
    }

    /**
     * @param  array<int, string>  $groups
     */
    private function roleForGroups(IdentityProvider $provider, array $groups): int
    {
        $map = $provider->groupRoleMap ?? [];
        foreach ($groups as $group) {
            if (isset($map[$group]) && Role::whereKey((int) $map[$group])->exists()) {
                return (int) $map[$group];
            }
        }

        return Role::where('name', 'user')->value('id') ?? 3;
    }
}
