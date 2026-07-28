<?php

namespace App\Jobs;

use App\Models\ArtifactDescriptor;
use App\Models\AuditEvent;
use App\Services\ArtifactLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class PurgeArtifactDescriptorJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $artifactDescriptorId
    ) {}

    public function handle(ArtifactLifecycleService $artifactLifecycle): void
    {
        $artifact = ArtifactDescriptor::query()->find($this->artifactDescriptorId);

        if (! $artifact instanceof ArtifactDescriptor) {
            return;
        }

        if (! $artifactLifecycle->hardDeleteEligible($artifact)) {
            $this->audit($artifact, AuditEvent::RESULT_FAILURE, [
                'reason' => 'Artifact descriptor is not eligible for hard delete.',
                'impact' => $artifactLifecycle->impactSummary($artifact),
            ]);

            return;
        }

        $before = $artifact->toArray();
        $artifact->delete();

        AuditEvent::create([
            'actorUserId' => null,
            'actorTenantId' => $artifact->idCostumer,
            'activeTenantId' => $artifact->idCostumer,
            'idProject' => $artifact->idProject,
            'action' => 'artifact.hard_delete',
            'targetType' => 'artifact_descriptor',
            'targetId' => (string) $artifact->id,
            'beforeValues' => $before,
            'afterValues' => null,
            'result' => AuditEvent::RESULT_SUCCESS,
            'sourceIp' => null,
            'correlationId' => (string) Str::uuid(),
            'metadata' => [
                'job' => self::class,
                'idempotencyKey' => $this->idempotencyKey(),
            ],
        ]);
    }

    public function idempotencyKey(): string
    {
        return 'artifact-hard-delete:'.$this->artifactDescriptorId;
    }

    public function artifactDescriptorId(): int
    {
        return $this->artifactDescriptorId;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function audit(ArtifactDescriptor $artifact, string $result, array $metadata): void
    {
        AuditEvent::create([
            'actorUserId' => null,
            'actorTenantId' => $artifact->idCostumer,
            'activeTenantId' => $artifact->idCostumer,
            'idProject' => $artifact->idProject,
            'action' => 'artifact.hard_delete',
            'targetType' => 'artifact_descriptor',
            'targetId' => (string) $artifact->id,
            'beforeValues' => [
                'state' => $artifact->state,
                'retentionUntil' => optional($artifact->retentionUntil)->toISOString(),
                'metadata' => $artifact->metadata,
            ],
            'afterValues' => null,
            'result' => $result,
            'sourceIp' => null,
            'correlationId' => (string) Str::uuid(),
            'metadata' => array_merge($metadata, [
                'job' => self::class,
                'idempotencyKey' => $this->idempotencyKey(),
            ]),
        ]);
    }
}
