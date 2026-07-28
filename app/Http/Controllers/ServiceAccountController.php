<?php

namespace App\Http\Controllers;

use App\Models\ServiceAccount;
use App\Services\AuditEventService;
use App\Services\CapabilityService;
use App\Services\ServiceAccountService;
use Illuminate\Http\Request;

class ServiceAccountController extends Controller
{
    public function __construct(
        private readonly CapabilityService $capabilities,
        private readonly ServiceAccountService $serviceAccounts,
        private readonly AuditEventService $auditEvents,
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
        $this->auditEvents->record(
            $request,
            'service_account.create',
            'service_account',
            (string) $created['serviceAccount']->id,
            afterValues: [
                'name' => $created['serviceAccount']->name,
                'credentialId' => $created['serviceAccount']->credentialId,
                'secret' => $created['secret'],
                'scopes' => $created['serviceAccount']->scopes ?? [],
                'expiresAt' => optional($created['serviceAccount']->expiresAt)->toISOString(),
            ],
            projectId: $created['serviceAccount']->idProject,
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

        $revoked = $this->serviceAccounts->revoke($serviceAccount);
        $this->auditEvents->record(
            $request,
            'service_account.revoke',
            'service_account',
            (string) $serviceAccount->id,
            beforeValues: [
                'revokedAt' => optional($serviceAccount->getOriginal('revokedAt'))->toISOString(),
            ],
            afterValues: [
                'revokedAt' => optional($revoked->revokedAt)->toISOString(),
            ],
            projectId: $revoked->idProject,
        );

        return response()->json([
            'data' => $revoked,
        ]);
    }
}
