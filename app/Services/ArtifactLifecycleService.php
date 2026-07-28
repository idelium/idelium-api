<?php

namespace App\Services;

use App\Models\ArtifactDescriptor;
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

    public function legalHoldEnabled(ArtifactDescriptor $artifact): bool
    {
        $metadata = $artifact->metadata ?? [];

        return (bool) ($metadata['legalHold']['enabled'] ?? false);
    }
}
