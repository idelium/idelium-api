<?php

namespace App\Services;

class RunMetadataService
{
    private const RUN_FIELDS = [
        'build',
        'commit',
        'branch',
        'repository',
        'initiator',
        'pipeline',
    ];

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function normalize(array $metadata): array
    {
        $metadata = $this->redact($metadata);
        $existingRun = is_array($metadata['run'] ?? null) ? $metadata['run'] : [];
        $run = [];

        foreach (self::RUN_FIELDS as $field) {
            $value = $existingRun[$field] ?? $metadata[$field] ?? null;
            $run[$field] = is_scalar($value) && $value !== ''
                ? (string) $value
                : null;
        }

        $run['workloadIdentity'] = $this->normalizeWorkloadIdentity(
            is_array($existingRun['workloadIdentity'] ?? null)
                ? $existingRun['workloadIdentity']
                : ($metadata['workloadIdentity'] ?? [])
        );

        $metadata['run'] = $run;

        foreach (array_merge(self::RUN_FIELDS, ['workloadIdentity']) as $legacyKey) {
            unset($metadata[$legacyKey]);
        }

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, string|null>  $filters
     */
    public function matchesFilters(array $metadata, array $filters): bool
    {
        $run = is_array($metadata['run'] ?? null) ? $metadata['run'] : [];

        foreach ($filters as $field => $expected) {
            if ($expected === null || $expected === '') {
                continue;
            }

            if ((string) ($run[$field] ?? '') !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    public function filterFields(): array
    {
        return self::RUN_FIELDS;
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return array<string, string|null>
     */
    private function normalizeWorkloadIdentity(array $identity): array
    {
        return [
            'provider' => $this->scalarOrNull($identity['provider'] ?? null),
            'issuer' => $this->scalarOrNull($identity['issuer'] ?? null),
            'subject' => $this->scalarOrNull($identity['subject'] ?? null),
            'audience' => $this->scalarOrNull($identity['audience'] ?? null),
        ];
    }

    private function scalarOrNull(mixed $value): ?string
    {
        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function redact(array $values): array
    {
        $redacted = [];

        foreach ($values as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $redacted;
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match(
            '/password|passwd|secret|token|apikey|api_key|authorization|cookie|credential|session/i',
            $key
        ) === 1;
    }
}
