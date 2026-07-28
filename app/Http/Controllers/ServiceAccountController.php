<?php

namespace App\Http\Controllers;

use App\Models\ServiceAccount;
use App\Services\CapabilityService;
use App\Services\ServiceAccountService;
use Illuminate\Http\Request;

class ServiceAccountController extends Controller
{
    public function __construct(
        private readonly CapabilityService $capabilities,
        private readonly ServiceAccountService $serviceAccounts,
    ) {}

    public function index(Request $request)
    {
        $this->capabilities->require($request->user(), 'api_keys.manage');
        $context = $this->tenantContext($request);

        return response()->json([
            'data' => ServiceAccount::query()
                ->where('idCostumer', $context->activeTenantId)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $this->capabilities->require($request->user(), 'api_keys.manage');
        $context = $this->tenantContext($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'idProject' => ['nullable', 'integer', 'exists:projects,id'],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string', 'max:128'],
            'expiresAt' => ['nullable', 'date', 'after:now'],
        ]);

        $created = $this->serviceAccounts->create(
            $context->activeTenantId,
            $validated['name'],
            $validated['idProject'] ?? null,
            $validated['scopes'] ?? [],
            isset($validated['expiresAt']) ? new \DateTimeImmutable($validated['expiresAt']) : null,
        );

        return response()->json([
            'data' => $created['serviceAccount'],
            'secret' => $created['secret'],
        ], 201);
    }

    public function revoke(Request $request, ServiceAccount $serviceAccount)
    {
        $this->capabilities->require($request->user(), 'api_keys.manage');
        $context = $this->tenantContext($request);

        abort_unless((int) $serviceAccount->idCostumer === $context->activeTenantId, 404);

        return response()->json([
            'data' => $this->serviceAccounts->revoke($serviceAccount),
        ]);
    }
}
