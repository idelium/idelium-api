<?php

namespace App\Services;

use App\Models\ArtifactDescriptor;
use App\Models\PerformedTestCycle;
use Illuminate\Validation\ValidationException;

class ArtifactDescriptorService
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function register(array $attributes): ArtifactDescriptor
    {
        $this->validate($attributes);

        $run = PerformedTestCycle::query()
            ->whereKey($attributes['performedTestCycleId'])
            ->where('idCostumer', $attributes['idCostumer'])
            ->firstOrFail();

        return ArtifactDescriptor::create([
            'idCostumer' => $attributes['idCostumer'],
            'idProject' => $attributes['idProject'],
            'performedTestCycleId' => $run->id,
            'performedTestId' => $attributes['performedTestId'] ?? null,
            'performedStepId' => $attributes['performedStepId'] ?? null,
            'artifactType' => $attributes['artifactType'],
            'name' => $attributes['name'],
            'contentType' => $attributes['contentType'],
            'sizeBytes' => $attributes['sizeBytes'],
            'checksumSha256' => strtolower($attributes['checksumSha256']),
            'storageKey' => $attributes['storageKey'],
            'state' => $attributes['state'] ?? ArtifactDescriptor::STATE_AVAILABLE,
            'retentionUntil' => $attributes['retentionUntil']
                ?? now()->addDays((int) config('artifacts.default_retention_days', 30)),
            'metadata' => $attributes['metadata'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function validate(array $attributes): void
    {
        $errors = [];
        $required = [
            'idCostumer',
            'idProject',
            'performedTestCycleId',
            'artifactType',
            'name',
            'contentType',
            'sizeBytes',
            'checksumSha256',
            'storageKey',
        ];

        foreach ($required as $field) {
            if (! array_key_exists($field, $attributes) || $attributes[$field] === '') {
                $errors[$field][] = 'The field is required.';
            }
        }

        if (isset($attributes['contentType'])
            && ! in_array($attributes['contentType'], config('artifacts.allowed_content_types', []), true)) {
            $errors['contentType'][] = 'The artifact content type is not allowed.';
        }

        if (isset($attributes['sizeBytes'])
            && (int) $attributes['sizeBytes'] > (int) config('artifacts.max_size_bytes')) {
            $errors['sizeBytes'][] = 'The artifact exceeds the configured size limit.';
        }

        if (isset($attributes['checksumSha256'])
            && ! preg_match('/^[a-fA-F0-9]{64}$/', (string) $attributes['checksumSha256'])) {
            $errors['checksumSha256'][] = 'The artifact checksum must be a SHA-256 hex digest.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
