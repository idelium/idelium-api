<?php

namespace App\Services;

use App\Models\ArtifactDescriptor;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ArtifactLifecycleService
{
    public function setLegalHold(
        ArtifactDescriptor $artifact,
        bool $enabled,
        ?string $reason
    ): ArtifactDescriptor {
        $metadata = $artifact->metadata ?? [];
        $metadata['legalHold'] = [
            'enabled' => $enabled,
            'reason' => $enabled ? $reason : null,
            'changedAt' => now()->toISOString(),
        ];

        $artifact->metadata = $metadata;
        $artifact->save();

        return $artifact;
    }

    public function markDeleted(ArtifactDescriptor $artifact): ArtifactDescriptor
    {
        if ($this->legalHoldEnabled($artifact)) {
            throw ValidationException::withMessages([
                'artifact' => ['Artifact is under legal hold and cannot be deleted.'],
            ]);
        }

        $artifact->state = ArtifactDescriptor::STATE_DELETED;
        $artifact->save();

        return $artifact;
    }

    public function archive(
        ArtifactDescriptor $artifact,
        ?string $reason,
        ?string $restoreBy
    ): ArtifactDescriptor {
        if ($this->legalHoldEnabled($artifact)) {
            throw ValidationException::withMessages([
                'artifact' => ['Artifact is under legal hold and cannot be archived.'],
            ]);
        }

        if ($artifact->state === ArtifactDescriptor::STATE_DELETED) {
            throw ValidationException::withMessages([
                'state' => ['Deleted artifacts cannot be archived.'],
            ]);
        }

        $metadata = $artifact->metadata ?? [];
        $metadata['archive'] = [
            'reason' => $reason,
            'archivedAt' => now()->toISOString(),
            'restoreBy' => $restoreBy,
        ];

        $artifact->state = ArtifactDescriptor::STATE_ARCHIVED;
        $artifact->metadata = $metadata;
        $artifact->save();

        return $artifact;
    }

    public function restore(ArtifactDescriptor $artifact): ArtifactDescriptor
    {
        if ($artifact->state !== ArtifactDescriptor::STATE_ARCHIVED) {
            throw ValidationException::withMessages([
                'state' => ['Only archived artifacts can be restored.'],
            ]);
        }

        $metadata = $artifact->metadata ?? [];
        $archive = $metadata['archive'] ?? [];
        $archive['restoredAt'] = now()->toISOString();
        $metadata['archive'] = $archive;

        $artifact->state = ArtifactDescriptor::STATE_AVAILABLE;
        $artifact->metadata = $metadata;
        $artifact->save();

        return $artifact;
    }

    /**
     * @return array<string, mixed>
     */
    public function impactSummary(ArtifactDescriptor $artifact): array
    {
        $legalHold = $this->legalHoldEnabled($artifact);
        $retentionExpired = $this->retentionExpired($artifact);

        return [
            'artifact' => [
                'id' => $artifact->id,
                'name' => $artifact->name,
                'artifactType' => $artifact->artifactType,
                'contentType' => $artifact->contentType,
                'sizeBytes' => $artifact->sizeBytes,
                'state' => $artifact->state,
                'retentionUntil' => optional($artifact->retentionUntil)->toISOString(),
                'performedTestCycleId' => $artifact->performedTestCycleId,
                'performedTestId' => $artifact->performedTestId,
                'performedStepId' => $artifact->performedStepId,
            ],
            'summary' => [
                'legalHold' => $legalHold,
                'retentionExpired' => $retentionExpired,
                'hardDeleteEligible' => $this->hardDeleteEligible($artifact),
                'affectedDescriptors' => 1,
                'storageBytes' => $artifact->sizeBytes,
            ],
            'blockers' => $this->lifecycleBlockers($artifact),
            'actions' => [
                'archiveAllowed' => ! $legalHold && $artifact->state !== ArtifactDescriptor::STATE_DELETED,
                'restoreAllowed' => $artifact->state === ArtifactDescriptor::STATE_ARCHIVED,
                'deleteMarkerAllowed' => ! $legalHold,
                'hardDeleteAllowed' => $this->hardDeleteEligible($artifact),
            ],
        ];
    }

    /**
     * @return Collection<int, ArtifactDescriptor>
     */
    public function hardDeleteCandidates(int $limit = 100): Collection
    {
        return ArtifactDescriptor::query()
            ->whereNotNull('retentionUntil')
            ->where('retentionUntil', '<=', now())
            ->whereIn('state', [
                ArtifactDescriptor::STATE_ARCHIVED,
                ArtifactDescriptor::STATE_DELETED,
                ArtifactDescriptor::STATE_EXPIRED,
            ])
            ->orderBy('retentionUntil')
            ->limit($limit)
            ->get()
            ->filter(fn (ArtifactDescriptor $artifact) => $this->hardDeleteEligible($artifact))
            ->values();
    }

    public function hardDeleteEligible(ArtifactDescriptor $artifact): bool
    {
        return ! $this->legalHoldEnabled($artifact)
            && $this->retentionExpired($artifact)
            && in_array($artifact->state, [
                ArtifactDescriptor::STATE_ARCHIVED,
                ArtifactDescriptor::STATE_DELETED,
                ArtifactDescriptor::STATE_EXPIRED,
            ], true);
    }

    public function retentionExpired(ArtifactDescriptor $artifact): bool
    {
        return $artifact->retentionUntil !== null && $artifact->retentionUntil->lte(now());
    }

    public function legalHoldEnabled(ArtifactDescriptor $artifact): bool
    {
        $metadata = $artifact->metadata ?? [];

        return (bool) ($metadata['legalHold']['enabled'] ?? false);
    }

    /**
     * @return array<int, string>
     */
    private function lifecycleBlockers(ArtifactDescriptor $artifact): array
    {
        $blockers = [];

        if ($this->legalHoldEnabled($artifact)) {
            $blockers[] = 'Artifact is under legal hold.';
        }

        if (! $this->retentionExpired($artifact)) {
            $blockers[] = 'Retention period has not expired.';
        }

        if (! in_array($artifact->state, [
            ArtifactDescriptor::STATE_ARCHIVED,
            ArtifactDescriptor::STATE_DELETED,
            ArtifactDescriptor::STATE_EXPIRED,
        ], true)) {
            $blockers[] = 'Artifact state is not eligible for hard delete.';
        }

        return $blockers;
    }
}
