<?php

namespace App\Services;

use App\Models\Plugin;
use InvalidArgumentException;

class PluginManifestService
{
    public const API_VERSION = 'idelium-plugin/1.1';

    public const LEGACY_API_VERSION = 'idelium-plugin-legacy/1';

    public const APPROVED_STATUS = 'approved';

    public const UNAPPROVED_STATUS = 'unapproved';

    /**
     * Normalize UI or API input into the persisted plugin manifest.
     */
    public function normalizeForStorage(mixed $payload, ?string $pluginName = null): array
    {
        if (is_array($payload) && $this->isEnterpriseManifest($payload)) {
            return $this->validateEnterpriseManifest($payload, $pluginName);
        }

        $source = $this->extractLegacySource($payload);

        return [
            'apiVersion' => self::API_VERSION,
            'name' => $pluginName,
            'source' => $source,
            'sourceSha256' => $this->sourceHash($source),
            'approvalStatus' => self::UNAPPROVED_STATUS,
            'provenance' => [
                'reviewed' => false,
                'source' => 'idelium-web',
            ],
            'capabilities' => ['step'],
            'execution' => [
                'mode' => 'subprocess',
                'timeoutSeconds' => 30,
            ],
        ];
    }

    /**
     * Return the source-only shape still consumed by the current Web editor.
     */
    public function webEditorPayload(Plugin $plugin): string
    {
        return json_encode([$this->sourceFromStoredCode($plugin->code)], JSON_THROW_ON_ERROR);
    }

    /**
     * Return the CLI-facing enterprise manifest.
     */
    public function cliPayload(Plugin $plugin): string
    {
        $manifest = $this->normalizeForStorage(
            $this->decodeStoredCode($plugin->code),
            $plugin->name
        );

        return json_encode($manifest, JSON_THROW_ON_ERROR);
    }

    public function metadata(Plugin $plugin): array
    {
        $manifest = $this->normalizeForStorage(
            $this->decodeStoredCode($plugin->code),
            $plugin->name
        );

        return [
            'approvalStatus' => $manifest['approvalStatus'],
            'sourceSha256' => $manifest['sourceSha256'],
            'provenanceReviewed' => $manifest['provenance']['reviewed'],
            'pluginApiVersion' => $manifest['apiVersion'],
            'executionMode' => $manifest['execution']['mode'],
        ];
    }

    public function sourceHash(string $source): string
    {
        return hash('sha256', $source);
    }

    private function validateEnterpriseManifest(array $payload, ?string $pluginName): array
    {
        $source = $this->extractRequiredString($payload, 'source');
        $sourceSha256 = $this->extractRequiredString($payload, 'sourceSha256');
        $approvalStatus = (string) ($payload['approvalStatus'] ?? self::UNAPPROVED_STATUS);
        $provenance = is_array($payload['provenance'] ?? null) ? $payload['provenance'] : [];
        $execution = is_array($payload['execution'] ?? null) ? $payload['execution'] : [];
        $capabilities = is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : ['step'];

        if ($sourceSha256 !== $this->sourceHash($source)) {
            throw new InvalidArgumentException('Plugin source hash does not match the manifest.');
        }

        if ($approvalStatus === self::APPROVED_STATUS) {
            if (($provenance['reviewed'] ?? false) !== true) {
                throw new InvalidArgumentException('Approved plugins require reviewed provenance.');
            }
            if (($execution['mode'] ?? null) !== 'subprocess') {
                throw new InvalidArgumentException('Approved plugins must use subprocess execution mode.');
            }
        }

        return [
            'apiVersion' => self::API_VERSION,
            'name' => (string) ($payload['name'] ?? $pluginName),
            'source' => $source,
            'sourceSha256' => $sourceSha256,
            'approvalStatus' => $approvalStatus,
            'provenance' => [
                'reviewed' => (bool) ($provenance['reviewed'] ?? false),
                'source' => (string) ($provenance['source'] ?? 'idelium-api'),
            ],
            'capabilities' => array_values(array_intersect($capabilities, ['step'])),
            'execution' => [
                'mode' => (string) ($execution['mode'] ?? 'subprocess'),
                'timeoutSeconds' => $this->boundedTimeout($execution['timeoutSeconds'] ?? 30),
            ],
        ];
    }

    private function sourceFromStoredCode(?string $storedCode): string
    {
        return $this->extractLegacySource($this->decodeStoredCode($storedCode));
    }

    private function decodeStoredCode(?string $storedCode): mixed
    {
        if ($storedCode === null || $storedCode === '') {
            return '';
        }

        $decoded = json_decode($storedCode, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $storedCode;
    }

    private function extractLegacySource(mixed $payload): string
    {
        if (is_string($payload)) {
            return $payload;
        }

        if (is_array($payload)) {
            if ($this->isEnterpriseManifest($payload)) {
                return $this->extractRequiredString($payload, 'source');
            }

            $first = reset($payload);
            if (is_string($first)) {
                return $first;
            }
        }

        throw new InvalidArgumentException('Plugin source code is required.');
    }

    private function isEnterpriseManifest(array $payload): bool
    {
        return ($payload['apiVersion'] ?? null) === self::API_VERSION;
    }

    private function extractRequiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Plugin '.$key.' is required.');
        }

        return $value;
    }

    private function boundedTimeout(mixed $timeout): int
    {
        $value = (int) $timeout;

        return max(1, min(300, $value));
    }
}
