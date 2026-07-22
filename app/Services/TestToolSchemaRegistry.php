<?php

namespace App\Services;

class TestToolSchemaRegistry
{
    private const SCHEMAS = [
        'selenium.v1' => [
            'runtime' => 'selenium',
            'scopes' => ['environment', 'step', 'result'],
            'legacyAliases' => ['selenium'],
        ],
        'selenium.webdriver.v2' => [
            'runtime' => 'selenium',
            'scopes' => ['environment', 'step', 'result'],
            'legacyAliases' => ['seleniumOrAppium'],
        ],
        'appium.v2' => [
            'runtime' => 'appium',
            'scopes' => ['environment', 'step', 'result'],
            'legacyAliases' => ['appium'],
        ],
        'postman.safe.v1' => [
            'runtime' => 'postman',
            'scopes' => ['environment', 'step', 'result'],
            'legacyAliases' => ['postman'],
        ],
        'postman.newman.v1' => [
            'runtime' => 'postman',
            'scopes' => ['environment', 'step', 'result'],
            'legacyAliases' => ['postmanNewman', 'newman'],
        ],
    ];

    public function supportedSchemaIds(): array
    {
        return array_keys(self::SCHEMAS);
    }

    public function supports(string $schemaId, string $scope): bool
    {
        return in_array($scope, self::SCHEMAS[$schemaId]['scopes'] ?? [], true);
    }

    public function validatePayload(mixed $payload, string $scope): ?string
    {
        $decoded = $this->decodePayload($payload);
        if ($decoded === null) {
            return null;
        }

        $runtime = $decoded['runtime'] ?? null;
        $schemaId = $decoded['schemaVersion'] ?? $decoded['schema'] ?? null;

        if ($runtime === null && $schemaId === null) {
            return null;
        }

        if (! is_string($runtime) || trim($runtime) === '') {
            return 'The payload runtime is required when schema metadata is provided.';
        }

        if (! is_string($schemaId) || trim($schemaId) === '') {
            return 'The payload schemaVersion is required when runtime metadata is provided.';
        }

        if (! array_key_exists($schemaId, self::SCHEMAS)) {
            return 'The payload schemaVersion is not supported.';
        }

        if (self::SCHEMAS[$schemaId]['runtime'] !== $runtime) {
            return 'The payload runtime does not match the declared schemaVersion.';
        }

        if (! $this->supports($schemaId, $scope)) {
            return 'The payload schemaVersion is not supported for this API field.';
        }

        return null;
    }

    private function decodePayload(mixed $payload): ?array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (! is_string($payload) || trim($payload) === '') {
            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }
}
