<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Project;
use App\Services\OidcWorkloadIdentityService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OidcWorkloadIdentityController extends Controller
{
    public function __construct(
        private readonly OidcWorkloadIdentityService $workloadIdentity,
    ) {}

    public function exchange(Request $request)
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'max:64'],
            'projectId' => ['required', 'integer'],
            'assertion' => ['required', 'string'],
        ]);

        try {
            $issued = $this->workloadIdentity->exchange(
                $validated['provider'],
                $validated['assertion'],
                (int) $validated['projectId']
            );
            $token = $issued['workloadToken'];

            $this->audit(
                request: $request,
                activeTenantId: $token->idCostumer,
                projectId: $token->idProject,
                action: 'oidc_workload_token.exchange',
                targetId: (string) $token->id,
                result: AuditEvent::RESULT_SUCCESS,
                afterValues: [
                    'provider' => $token->provider,
                    'subject' => $token->subject,
                    'repository' => $token->repository,
                    'ref' => $token->ref,
                    'environment' => $token->environment,
                    'token' => '[REDACTED]',
                ],
                metadata: [
                    'claims' => $issued['claims'],
                ],
            );

            return response()->json([
                'tokenType' => 'Bearer',
                'token' => $issued['token'],
                'expiresAt' => $token->expiresAt->toISOString(),
                'scopes' => $token->scopes ?? [],
                'projectId' => $token->idProject,
            ], 201);
        } catch (ValidationException $exception) {
            $project = Project::query()->find($validated['projectId'] ?? null);
            if ($project instanceof Project) {
                $this->audit(
                    request: $request,
                    activeTenantId: $project->idCostumer,
                    projectId: $project->id,
                    action: 'oidc_workload_token.reject',
                    targetId: null,
                    result: AuditEvent::RESULT_FAILURE,
                    afterValues: [
                        'provider' => $validated['provider'] ?? null,
                        'projectId' => $validated['projectId'] ?? null,
                        'assertion' => '[REDACTED]',
                    ],
                    metadata: [
                        'errors' => $exception->errors(),
                    ],
                );
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed>|null $afterValues
     * @param array<string, mixed>|null $metadata
     */
    private function audit(
        Request $request,
        int $activeTenantId,
        ?int $projectId,
        string $action,
        ?string $targetId,
        string $result,
        ?array $afterValues = null,
        ?array $metadata = null,
    ): void {
        AuditEvent::create([
            'actorUserId' => null,
            'actorTenantId' => $activeTenantId,
            'activeTenantId' => $activeTenantId,
            'idProject' => $projectId,
            'action' => $action,
            'targetType' => 'oidc_workload_token',
            'targetId' => $targetId,
            'beforeValues' => null,
            'afterValues' => $afterValues,
            'result' => $result,
            'sourceIp' => $request->ip(),
            'correlationId' => (string) Str::uuid(),
            'metadata' => $metadata,
        ]);
    }
}
