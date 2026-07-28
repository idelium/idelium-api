<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class EnvironmentSecretPolicy
{
    public const REDACTED = '[REDACTED]';

    /**
     * @return array<string, mixed>|string
     */
    public function redactConfig(mixed $config): array|string
    {
        $decoded = $this->decodeConfig($config);
        if (! is_array($decoded)) {
            return $config;
        }

        return $this->redactArray($decoded);
    }

    public function assertNoInlineSecrets(mixed $config): void
    {
        $decoded = $this->decodeConfig($config);
        if (! is_array($decoded)) {
            return;
        }

        $paths = $this->inlineSecretPaths($decoded);
        if ($paths !== []) {
            throw ValidationException::withMessages([
                'config' => [
                    'Environment configuration must use secretRef for sensitive values: '
                    .implode(', ', $paths),
                ],
            ]);
        }
    }

    private function decodeConfig(mixed $config): mixed
    {
        if (is_string($config)) {
            $decoded = json_decode($config, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $config;
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function redactArray(array $values): array
    {
        $redacted = [];
        foreach ($values as $key => $value) {
            if ($this->isSecretReference($value) || $this->isSensitiveKey((string) $key)) {
                $redacted[$key] = self::REDACTED;
                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redactArray($value) : $value;
        }

        return $redacted;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<int, string>
     */
    private function inlineSecretPaths(array $values, string $prefix = ''): array
    {
        $paths = [];
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if ($this->isSecretReference($value)) {
                continue;
            }

            if ($this->isSensitiveKey((string) $key) && $this->hasPlainSecretValue($value)) {
                $paths[] = $path;
                continue;
            }

            if (is_array($value)) {
                $paths = array_merge($paths, $this->inlineSecretPaths($value, $path));
            }
        }

        return $paths;
    }

    private function isSecretReference(mixed $value): bool
    {
        return is_array($value)
            && isset($value['secretRef'])
            && is_string($value['secretRef'])
            && $value['secretRef'] !== '';
    }

    private function hasPlainSecretValue(mixed $value): bool
    {
        return is_scalar($value) && (string) $value !== '';
    }

    private function isSensitiveKey(string $key): bool
    {
        return preg_match(
            '/password|passwd|secret|token|apikey|api_key|authorization|cookie|credential|session/i',
            $key
        ) === 1;
    }
}
