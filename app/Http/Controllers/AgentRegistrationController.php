<?php

namespace App\Http\Controllers;

use App\Models\AgentRegistration;
use App\Services\AuditEventService;
use App\Services\CapabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AgentRegistrationController extends Controller
{
    public function __construct(
        private readonly CapabilityService $capabilities,
        private readonly AuditEventService $auditEvents,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->capabilities->require($request->user(), 'agents.read');
        $context = $this->tenantContext($request);

        return response()->json([
            'data' => AgentRegistration::query()
                ->where('idCostumer', $context->activeTenantId)
                ->orderBy('agentId')
                ->get()
                ->map(fn (AgentRegistration $agent) => $this->agentResponse($agent))
                ->values(),
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $customer = $this->ideliumCustomer($request);
        $validated = $request->validate([
            'agentId' => ['required', 'string', 'max:128'],
            'version' => ['nullable', 'string', 'max:64'],
            'runtimes' => ['sometimes', 'array'],
            'capabilities' => ['sometimes', 'array'],
            'maxConcurrency' => ['sometimes', 'integer', 'min:1', 'max:256'],
            'health' => [
                'sometimes',
                'string',
                Rule::in([
                    AgentRegistration::HEALTH_UNKNOWN,
                    AgentRegistration::HEALTH_HEALTHY,
                    AgentRegistration::HEALTH_UNHEALTHY,
                ]),
            ],
        ]);

        $agent = DB::transaction(function () use ($request, $customer, $validated) {
            $agent = AgentRegistration::firstOrNew([
                'idCostumer' => $customer->id,
                'agentId' => $validated['agentId'],
            ]);
            $isNew = ! $agent->exists;
            $before = $agent->exists ? $this->agentResponse($agent) : null;
            $agent->fill([
                'status' => $agent->status ?? AgentRegistration::STATUS_PENDING,
                'version' => $validated['version'] ?? $agent->version,
                'runtimes' => $validated['runtimes'] ?? ($agent->runtimes ?? []),
                'capabilities' => $validated['capabilities'] ?? ($agent->capabilities ?? []),
                'maxConcurrency' => (int) ($validated['maxConcurrency'] ?? ($agent->maxConcurrency ?? 1)),
                'health' => $validated['health'] ?? ($agent->health ?? AgentRegistration::HEALTH_UNKNOWN),
                'lastSeenAt' => now(),
            ]);
            $agent->save();

            $this->auditEvents->record(
                $request,
                $isNew ? 'agent.register' : 'agent.refresh',
                'agent_registration',
                (string) $agent->id,
                beforeValues: $before,
                afterValues: $this->agentResponse($agent),
            );

            return $agent;
        });

        return response()->json([
            'data' => $this->agentResponse($agent),
        ], $agent->wasRecentlyCreated ? 201 : 200);
    }

    public function updateStatus(
        Request $request,
        AgentRegistration $agentRegistration
    ): JsonResponse {
        $this->capabilities->require($request->user(), 'agents.manage');
        $context = $this->tenantContext($request);
        abort_unless((int) $agentRegistration->idCostumer === $context->activeTenantId, 404);
        $validated = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::in([
                    AgentRegistration::STATUS_APPROVED,
                    AgentRegistration::STATUS_MAINTENANCE,
                    AgentRegistration::STATUS_DRAINING,
                    AgentRegistration::STATUS_DISABLED,
                ]),
            ],
        ]);

        $before = $this->agentResponse($agentRegistration);
        $agentRegistration->status = $validated['status'];
        $agentRegistration->save();

        $this->auditEvents->record(
            $request,
            'agent.status_update',
            'agent_registration',
            (string) $agentRegistration->id,
            beforeValues: $before,
            afterValues: $this->agentResponse($agentRegistration),
        );

        return response()->json([
            'data' => $this->agentResponse($agentRegistration),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function agentResponse(AgentRegistration $agent): array
    {
        return [
            'id' => $agent->id,
            'agentId' => $agent->agentId,
            'status' => $agent->status,
            'version' => $agent->version,
            'runtimes' => $agent->runtimes ?? [],
            'capabilities' => $agent->capabilities ?? [],
            'maxConcurrency' => $agent->maxConcurrency,
            'health' => $agent->health,
            'lastSeenAt' => optional($agent->lastSeenAt)->toISOString(),
            'createdAt' => optional($agent->created_at)->toISOString(),
            'updatedAt' => optional($agent->updated_at)->toISOString(),
        ];
    }
}
