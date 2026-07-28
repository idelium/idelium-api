<?php

namespace App\Http\Middleware;

use App\Models\Costumer;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenantContext
{
    public const ATTRIBUTE = 'tenantContext';

    public const SESSION_ACTIVE_TENANT = 'idelium.activeTenantId';

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

        if (
            ! $this->canUseTenant($user, $activeTenantId)
            || ! Costumer::whereKey($activeTenantId)->exists()
        ) {
            $activeTenantId = (int) $user->idCostumer;
            if ($request->hasSession()) {
                $request->session()->forget(self::SESSION_ACTIVE_TENANT);
            }
        }

        $tenantContext = TenantContext::forUser($user, $activeTenantId);
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
}
