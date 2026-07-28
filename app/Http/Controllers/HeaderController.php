<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveTenantContext;
use App\Models\Costumer;
use App\Models\Project;
use App\Services\AuditEventService;
use App\Services\CapabilityService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

class HeaderController extends Controller
{
    public function __construct(
        private readonly AuditEventService $auditEvents,
        private readonly CapabilityService $capabilities,
    ) {}

    public function index(Request $request)
    {
        $header = [];
        $user = $request->user();
        $role = $user->role;
        $header['projects'] = Project::orderBy('created_at', 'asc')
            ->where('idCostumer', $user->idCostumer)
            ->get();
        if ($role == 1) {
            $header['costumers'] = Costumer::orderBy('created_at', 'asc')->get();
        }
        $header['tenantContext'] = [
            'actorUserId' => $user->id,
            'actorTenantId' => $this->tenantContext($request)->actorTenantId,
            'activeTenantId' => $this->tenantContext($request)->activeTenantId,
            'impersonating' => $this->tenantContext($request)->isImpersonating(),
            'impersonationReason' => $this->tenantContext($request)->impersonationReason,
            'impersonationExpiresAt' => $this->tenantContext($request)->impersonationExpiresAt,
        ];

        return response()->json($header);
    }

    public function changeCostumer(Request $request, $id)
    {
        $user = $request->user();
        $targetTenantId = (int) $id;

        $this->capabilities->require($user, 'tenant.switch');
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'expiresAt' => ['required', 'date', 'after:now'],
        ]);

        if (! $request->hasSession()) {
            return response()->json([
                'message' => 'Tenant switch requires a browser session.',
                'error' => [
                    'code' => 'TENANT_SESSION_REQUIRED',
                ],
            ], 409);
        }

        $targetTenant = Costumer::find($targetTenantId);
        if ($targetTenant === null) {
            return response()->json([
                'message' => 'Tenant was not found.',
                'error' => [
                    'code' => 'TENANT_NOT_FOUND',
                ],
            ], 404);
        }

        $request->session()->put(
            ResolveTenantContext::SESSION_ACTIVE_TENANT,
            $targetTenantId
        );
        $request->session()->put(
            ResolveTenantContext::SESSION_IMPERSONATION_REASON,
            $validated['reason']
        );
        $request->session()->put(
            ResolveTenantContext::SESSION_IMPERSONATION_EXPIRES_AT,
            $validated['expiresAt']
        );
        $request->attributes->set(
            ResolveTenantContext::ATTRIBUTE,
            TenantContext::forUser(
                $user,
                $targetTenantId,
                $validated['reason'],
                $validated['expiresAt'],
            )
        );

        $this->auditEvents->record(
            $request,
            'tenant.switch',
            'costumer',
            (string) $targetTenantId,
            beforeValues: [
                'activeTenantId' => (int) $user->getOriginal('idCostumer'),
            ],
            afterValues: [
                'activeTenantId' => $targetTenantId,
                'sessionToken' => $request->session()->getId(),
                'reason' => $validated['reason'],
                'expiresAt' => $validated['expiresAt'],
            ],
        );

        return response()->json([
            'tenantContext' => [
                'actorUserId' => $user->id,
                'actorTenantId' => (int) $user->getOriginal('idCostumer'),
                'activeTenantId' => $targetTenantId,
                'impersonating' => $targetTenantId !== (int) $user->getOriginal('idCostumer'),
                'impersonationReason' => $validated['reason'],
                'impersonationExpiresAt' => $validated['expiresAt'],
            ],
        ]);
    }
}
