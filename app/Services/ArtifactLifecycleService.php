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

    public function legalHoldEnabled(ArtifactDescriptor $artifact): bool
    {
        $metadata = $artifact->metadata ?? [];

        return (bool) ($metadata['legalHold']['enabled'] ?? false);
    }
}
