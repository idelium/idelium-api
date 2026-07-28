<?php

namespace App\Http\Controllers;

use App\Jobs\DispatchIntegrationDeliveryJob;
use App\Models\AuditEvent;
use App\Models\IntegrationDelivery;
use App\Models\IntegrationEndpoint;
use App\Services\AuditEventService;
use App\Services\CapabilityService;
use App\Services\IntegrationEndpointService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

class IntegrationEndpointController extends Controller
{
    public function __construct(
        private readonly CapabilityService $capabilities,
        private readonly IntegrationEndpointService $integrations,
        private readonly AuditEventService $auditEvents,
    ) {}

    public function index(Request $request, int $idProject)
    {
        $this->capabilities->require($request->user(), 'integrations.read');
        $context = $this->tenantContext($request);
        $this->integrations->assertProjectScope($context->activeTenantId, $idProject);

        return response()->json([
            'data' => IntegrationEndpoint::query()
                ->where('idCostumer', $context->activeTenantId)
                ->where('idProject', $idProject)
                ->orderBy('name')
                ->get()
                ->map(fn (IntegrationEndpoint $endpoint): array => $this->serializeEndpoint($endpoint)),
        ]);
    }

    public function store(Request $request, int $idProject)
    {
        $this->capabilities->require($request->user(), 'integrations.manage');
        $context = $this->tenantContext($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'adapter' => ['required', 'string', Rule::in(config('integrations.allowed_adapters'))],
            'url' => ['required', 'string', 'max:2048', 'url'],
            'secret' => ['required', 'string', 'min:16', 'max:2000'],
            'events' => ['nullable', 'array'],
            'events.*' => ['string', 'max:128'],
        ]);

        $endpoint = $this->integrations->create($context->activeTenantId, $idProject, $validated);
        $this->auditEvents->record(
            $request,
            'integration_endpoint.create',
            'integration_endpoint',
            (string) $endpoint->id,
            afterValues: [
                'name' => $endpoint->name,
                'adapter' => $endpoint->adapter,
                'url' => $endpoint->url,
                'events' => $endpoint->events,
                'secret' => $validated['secret'],
            ],
            projectId: $endpoint->idProject,
        );

        return response()->json([
            'data' => $this->serializeEndpoint($endpoint),
        ], 201);
    }

    public function test(Request $request, int $idProject, IntegrationEndpoint $integrationEndpoint)
    {
        $this->capabilities->require($request->user(), 'integrations.manage');
        $context = $this->tenantContext($request);
        $this->integrations->assertProjectScope($context->activeTenantId, $idProject);
        abort_unless(
            (int) $integrationEndpoint->idCostumer === $context->activeTenantId
            && (int) $integrationEndpoint->idProject === $idProject,
            404
        );

        $delivery = $this->integrations->createDelivery(
            $integrationEndpoint,
            'integration.test',
            [
                'message' => 'Idelium integration test delivery.',
                'requestedBy' => $request->user()->email,
            ],
            'integration.test:'.now()->format('YmdHis').':'.$request->user()->id,
            false,
        );
        DispatchIntegrationDeliveryJob::dispatch($delivery->id);
        $this->auditEvents->record(
            $request,
            'integration_delivery.test',
            'integration_delivery',
            (string) $delivery->id,
            afterValues: [
                'deliveryId' => $delivery->deliveryId,
                'event' => $delivery->event,
                'status' => $delivery->status,
            ],
            projectId: $delivery->idProject,
        );

        return response()->json([
            'data' => $this->serializeDelivery($delivery),
        ], 202);
    }

    public function updateStatus(Request $request, int $idProject, IntegrationEndpoint $integrationEndpoint)
    {
        $this->capabilities->require($request->user(), 'integrations.manage');
        $context = $this->tenantContext($request);
        $this->integrations->assertProjectScope($context->activeTenantId, $idProject);
        abort_unless(
            (int) $integrationEndpoint->idCostumer === $context->activeTenantId
            && (int) $integrationEndpoint->idProject === $idProject,
            404
        );

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in([
                IntegrationEndpoint::STATUS_ACTIVE,
                IntegrationEndpoint::STATUS_DISABLED,
            ])],
        ]);
        $before = ['status' => $integrationEndpoint->status];
        $integrationEndpoint->forceFill([
            'status' => $validated['status'],
        ])->save();

        $this->auditEvents->record(
            $request,
            'integration_endpoint.status_update',
            'integration_endpoint',
            (string) $integrationEndpoint->id,
            beforeValues: $before,
            afterValues: ['status' => $integrationEndpoint->status],
            projectId: $integrationEndpoint->idProject,
        );

        return response()->json([
            'data' => $this->serializeEndpoint($integrationEndpoint),
        ]);
    }

    public function rotateSecret(Request $request, int $idProject, IntegrationEndpoint $integrationEndpoint)
    {
        $this->capabilities->require($request->user(), 'integrations.manage');
        $context = $this->tenantContext($request);
        $this->integrations->assertProjectScope($context->activeTenantId, $idProject);
        abort_unless(
            (int) $integrationEndpoint->idCostumer === $context->activeTenantId
            && (int) $integrationEndpoint->idProject === $idProject,
            404
        );

        $validated = $request->validate([
            'secret' => ['required', 'string', 'min:16', 'max:2000'],
        ]);

        $integrationEndpoint->forceFill([
            'secretEncrypted' => Crypt::encryptString($validated['secret']),
            'metadata' => array_merge($integrationEndpoint->metadata ?? [], [
                'secretRotatedAt' => now()->toISOString(),
            ]),
        ])->save();

        $this->auditEvents->record(
            $request,
            'integration_endpoint.rotate_secret',
            'integration_endpoint',
            (string) $integrationEndpoint->id,
            afterValues: [
                'secret' => $validated['secret'],
                'secretRotatedAt' => $integrationEndpoint->metadata['secretRotatedAt'] ?? null,
            ],
            projectId: $integrationEndpoint->idProject,
        );

        return response()->json([
            'data' => $this->serializeEndpoint($integrationEndpoint),
        ]);
    }

    public function deliveries(Request $request, int $idProject)
    {
        $this->capabilities->require($request->user(), 'integrations.read');
        $context = $this->tenantContext($request);
        $this->integrations->assertProjectScope($context->activeTenantId, $idProject);

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in([
                IntegrationDelivery::STATUS_PENDING,
                IntegrationDelivery::STATUS_SENT,
                IntegrationDelivery::STATUS_FAILED,
                IntegrationDelivery::STATUS_DEAD_LETTER,
            ])],
        ]);

        $query = IntegrationDelivery::query()
            ->where('idCostumer', $context->activeTenantId)
            ->where('idProject', $idProject)
            ->latest();

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json([
            'data' => $query->limit(100)->get()->map(
                fn (IntegrationDelivery $delivery): array => $this->serializeDelivery($delivery)
            ),
        ]);
    }

    public function replay(Request $request, int $idProject, IntegrationDelivery $integrationDelivery)
    {
        $this->capabilities->require($request->user(), 'integrations.manage');
        $context = $this->tenantContext($request);
        $this->integrations->assertProjectScope($context->activeTenantId, $idProject);
        abort_unless(
            (int) $integrationDelivery->idCostumer === $context->activeTenantId
            && (int) $integrationDelivery->idProject === $idProject,
            404
        );

        $integrationDelivery->forceFill([
            'status' => IntegrationDelivery::STATUS_PENDING,
            'nextAttemptAt' => null,
        ])->save();
        DispatchIntegrationDeliveryJob::dispatch($integrationDelivery->id);
        $this->auditEvents->record(
            $request,
            'integration_delivery.replay',
            'integration_delivery',
            (string) $integrationDelivery->id,
            afterValues: [
                'deliveryId' => $integrationDelivery->deliveryId,
                'status' => $integrationDelivery->status,
            ],
            projectId: $integrationDelivery->idProject,
        );

        return response()->json([
            'data' => $this->serializeDelivery($integrationDelivery->fresh()),
        ], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEndpoint(IntegrationEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'idProject' => $endpoint->idProject,
            'name' => $endpoint->name,
            'adapter' => $endpoint->adapter,
            'url' => $endpoint->url,
            'events' => $endpoint->events ?? ['*'],
            'status' => $endpoint->status,
            'secretConfigured' => $endpoint->secretEncrypted !== '',
            'schemaVersion' => $endpoint->metadata['schemaVersion'] ?? config('integrations.schema_version'),
            'createdAt' => optional($endpoint->created_at)->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDelivery(IntegrationDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'deliveryId' => $delivery->deliveryId,
            'event' => $delivery->event,
            'status' => $delivery->status,
            'attempts' => $delivery->attempts,
            'responseStatus' => $delivery->responseStatus,
            'nextAttemptAt' => optional($delivery->nextAttemptAt)->toISOString(),
            'sentAt' => optional($delivery->sentAt)->toISOString(),
        ];
    }
}
