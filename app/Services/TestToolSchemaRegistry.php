<?php

namespace App\Services;

class TestToolSchemaRegistry
{
    private const SCHEMAS = [
        'selenium.v1' => [
            'runtime' => 'selenium',
            'scopes' => ['environment', 'step', 'result'],
            'legacyAliases' => ['selenium'],
            'stepKeys' => ['command', 'steps'],
            'environmentKeys' => ['browser', 'browserName', 'capabilities', 'gridUrl'],
            'resultKeys' => ['assertions', 'artifacts', 'commandTrace', 'logs'],
        ],
        'selenium.webdriver.v2' => [
            'runtime' => 'selenium',
            'scopes' => ['environment', 'step', 'result'],
            'legacyAliases' => ['seleniumOrAppium'],
            'stepKeys' => ['command', 'steps', 'locator', 'target'],
            'environmentKeys' => ['browser', 'browserName', 'capabilities', 'gridUrl'],
            'resultKeys' => ['assertions', 'artifacts', 'bidiArtifacts', 'commandTrace', 'logs', 'networkEvents'],
        ],
        'selenium.bidi.diagnostics.v1' => [
            'runtime' => 'selenium',
            'scopes' => ['result'],
            'legacyAliases' => [],
            'resultKeys' => ['artifacts', 'bidiArtifacts', 'commandTrace', 'logs', 'networkEvents'],
        ],
        'appium.v2' => [
            'runtime' => 'appium',
            'scopes' => ['environment', 'step', 'result'],
            'legacyAliases' => ['appium'],
            'stepKeys' => ['command', 'steps', 'locator', 'target'],
            'environmentKeys' => ['appiumServer', 'appiumDesiredCaps', 'desiredCapabilities'],
            'resultKeys' => ['assertions', 'artifacts', 'commandTrace', 'logs', 'videos'],
        ],
        'postman.safe.v1' => [
            'runtime' => 'postman',
            'scopes' => ['environment', 'step', 'result'],
            'legacyAliases' => ['postman'],
            'stepKeys' => ['collection', 'collectionId', 'requests', 'item', 'info'],
            'environmentKeys' => ['variables', 'environment', 'baseUrl'],
            'resultKeys' => ['assertions', 'executions', 'requests', 'scriptFailures'],
        ],
        'postman.newman.v1' => [
            'runtime' => 'postman',
            'scopes' => ['environment', 'step', 'result'],
            'legacyAliases' => ['postmanNewman', 'newman'],
            'stepKeys' => ['collection', 'collectionPath', 'collectionId', 'requests', 'item', 'info'],
            'environmentKeys' => ['variables', 'environment', 'environmentPath', 'globalsPath'],
            'resultKeys' => ['assertions', 'executions', 'requests', 'scriptFailures', 'console', 'logs'],
        ],
        'dsl.source.v1' => [
            'runtime' => 'dsl',
            'scopes' => ['step'],
            'legacyAliases' => [],
            'stepKeys' => ['languageVersion', 'source'],
        ],
        'dsl.ast.v1' => [
            'runtime' => 'dsl',
            'scopes' => ['step'],
            'legacyAliases' => [],
            'stepKeys' => ['ast', 'languageVersion'],
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

        if (! $this->usesAllowedTopLevelFields($decoded, $schemaId, $scope)) {
            return 'The payload contains fields that are not supported by the declared schemaVersion.';
        }

        if (! $this->hasRequiredRuntimeShape($decoded, $schemaId, $scope)) {
            return sprintf(
                'The %s payload does not contain any fields supported by %s.',
                $scope,
                $schemaId
            );
        }

        if ($scope === 'step') {
            $dslError = $this->validateDslStepPayload($decoded, $schemaId);
            if ($dslError !== null) {
                return $dslError;
            }
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

    private function hasRequiredRuntimeShape(
        array $payload,
        string $schemaId,
        string $scope
    ): bool {
        $keys = self::SCHEMAS[$schemaId][$scope.'Keys'] ?? [];

        if ($keys === []) {
            return true;
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return true;
            }
        }

        return false;
    }

    private function usesAllowedTopLevelFields(
        array $payload,
        string $schemaId,
        string $scope
    ): bool {
        $allowedKeys = array_merge(
            ['runtime', 'schemaVersion', 'schema', 'metadata', 'summary', 'startedAt', 'finishedAt', 'durationMilliseconds'],
            self::SCHEMAS[$schemaId][$scope.'Keys'] ?? []
        );

        foreach (array_keys($payload) as $key) {
            if (! in_array((string) $key, $allowedKeys, true)) {
                return false;
            }
        }

        return true;
    }

    private function validateDslStepPayload(array $payload, string $schemaId): ?string
    {
        if (! in_array($schemaId, ['dsl.source.v1', 'dsl.ast.v1'], true)) {
            return null;
        }

        if (($payload['languageVersion'] ?? null) !== '1.0') {
            return 'The DSL languageVersion is not supported.';
        }

        if ($schemaId === 'dsl.source.v1') {
            $source = $payload['source'] ?? null;
            if (! is_string($source) || trim($source) === '') {
                return 'The DSL source payload requires a non-empty source string.';
            }

            if (strlen($source) > 262144) {
                return 'The DSL source payload exceeds the allowed size.';
            }

            if (! str_starts_with(ltrim($source), 'idelium 1.0')) {
                return 'The DSL source payload must declare idelium 1.0.';
            }

            return null;
        }

        $ast = $payload['ast'] ?? null;
        if (! is_array($ast)) {
            return 'The DSL AST payload requires an ast object.';
        }

        if (($ast['kind'] ?? null) !== 'document'
            || ($ast['schemaVersion'] ?? null) !== '1.0'
            || ($ast['languageVersion'] ?? null) !== '1.0') {
            return 'The DSL AST payload must be a canonical document with schemaVersion and languageVersion 1.0.';
        }

        $tests = $ast['tests'] ?? null;
        if (! is_array($tests) || $tests === []) {
            return 'The DSL AST payload requires at least one test.';
        }

        return null;
    }
}
