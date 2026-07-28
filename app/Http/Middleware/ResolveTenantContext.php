<?php

namespace App\Http\Middleware;

use App\Models\Costumer;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantContext
{
    public const ATTRIBUTE = 'tenantContext';

    public const SESSION_ACTIVE_TENANT = 'idelium.activeTenantId';

    public const SESSION_IMPERSONATION_REASON = 'idelium.impersonationReason';

    public const SESSION_IMPERSONATION_EXPIRES_AT = 'idelium.impersonationExpiresAt';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        $activeTenantId = $request->hasSession()
            ? $request->session()->get(self::SESSION_ACTIVE_TENANT)
            : null;

        $activeTenantId = is_numeric($activeTenantId)
            ? (int) $activeTenantId
            : (int) $user->idCostumer;
        $reason = $request->hasSession()
            ? $request->session()->get(self::SESSION_IMPERSONATION_REASON)
            : null;
        $expiresAt = $request->hasSession()
            ? $request->session()->get(self::SESSION_IMPERSONATION_EXPIRES_AT)
            : null;

        if (
            ! $this->canUseTenant($user, $activeTenantId)
            || ! Costumer::whereKey($activeTenantId)->exists()
            || $this->isExpired($expiresAt)
        ) {
            $activeTenantId = (int) $user->idCostumer;
            $reason = null;
            $expiresAt = null;
            if ($request->hasSession()) {
                $this->clearSessionContext($request);
            }
        }

        $tenantContext = TenantContext::forUser(
            $user,
            $activeTenantId,
            is_string($reason) ? $reason : null,
            is_string($expiresAt) ? $expiresAt : null,
        );
        $request->attributes->set(self::ATTRIBUTE, $tenantContext);

        $user->setAttribute('authenticatedTenantId', (int) $user->getOriginal('idCostumer'));
        $user->setAttribute('idCostumer', $tenantContext->activeTenantId);

        return $next($request);
    }

    private function canUseTenant($user, int $tenantId): bool
    {
        return (int) $user->role === 1
            || (int) $user->getOriginal('idCostumer') === $tenantId;
    }

    private function isExpired(mixed $expiresAt): bool
    {
        if (! is_string($expiresAt) || $expiresAt === '') {
            return false;
        }

        return Carbon::parse($expiresAt)->isPast();
    }

    private function clearSessionContext(Request $request): void
    {
        $request->session()->forget([
            self::SESSION_ACTIVE_TENANT,
            self::SESSION_IMPERSONATION_REASON,
            self::SESSION_IMPERSONATION_EXPIRES_AT,
        ]);
    }
}
