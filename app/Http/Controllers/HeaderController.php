<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveTenantContext;
use App\Models\Costumer;
use App\Models\Project;
use Illuminate\Http\Request;

class HeaderController extends Controller
{
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
        ];

        return response()->json($header);
    }

    public function changeCostumer(Request $request, $id)
    {
        $user = $request->user();
        $targetTenantId = (int) $id;

        if ((int) $user->role !== 1) {
            return response()->json([
                'message' => 'Tenant switch is not authorized.',
                'error' => [
                    'code' => 'TENANT_SWITCH_FORBIDDEN',
                ],
            ], 403);
        }

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

        return response()->json([
            'tenantContext' => [
                'actorUserId' => $user->id,
                'actorTenantId' => (int) $user->getOriginal('idCostumer'),
                'activeTenantId' => $targetTenantId,
                'impersonating' => $targetTenantId !== (int) $user->getOriginal('idCostumer'),
            ],
        ]);
    }
}
