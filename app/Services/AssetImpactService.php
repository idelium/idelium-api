<?php

namespace App\Services;

use App\Models\Test;
use App\Models\TestCycle;
use Illuminate\Support\Collection;

class AssetImpactService
{
    public const ALLOWED_ASSET_TYPES = [
        'environment',
        'plugin',
        'step',
        'test',
        'test_cycle',
    ];

    /**
     * @return array<string, mixed>
     */
    public function impact(int $tenantId, int $projectId, string $assetType, int $assetId): array
    {
        $dependentTests = $this->dependentTests($tenantId, $projectId, $assetType, $assetId);
        $dependentTestCycles = $this->dependentTestCycles(
            $tenantId,
            $projectId,
            $assetType,
            $assetId,
            $dependentTests->pluck('id')->map(fn ($id) => (int) $id)->all()
        );

        return [
            'asset' => [
                'assetType' => $assetType,
                'assetId' => $assetId,
            ],
            'summary' => [
                'tests' => $dependentTests->count(),
                'testCycles' => $dependentTestCycles->count(),
            ],
            'tests' => $dependentTests
                ->map(fn (Test $test) => $this->testResponse($test))
                ->values()
                ->all(),
            'testCycles' => $dependentTestCycles
                ->map(fn (TestCycle $testCycle) => $this->testCycleResponse($testCycle))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return Collection<int, Test>
     */
    private function dependentTests(
        int $tenantId,
        int $projectId,
        string $assetType,
        int $assetId
    ): Collection {
        if ($assetType === 'test') {
            return Test::query()
                ->where('idCostumer', $tenantId)
                ->where('idProject', $projectId)
                ->where('id', $assetId)
                ->orderBy('name')
                ->get();
        }

        return Test::query()
            ->where('idCostumer', $tenantId)
            ->where('idProject', $projectId)
            ->orderBy('name')
            ->get()
            ->filter(fn (Test $test) => $this->configReferences(
                $test->config,
                $this->referenceKeysFor($assetType),
                $assetId
            ))
            ->values();
    }

    /**
     * @param  array<int, int>  $dependentTestIds
     * @return Collection<int, TestCycle>
     */
    private function dependentTestCycles(
        int $tenantId,
        int $projectId,
        string $assetType,
        int $assetId,
        array $dependentTestIds
    ): Collection {
        if ($assetType === 'test_cycle') {
            return TestCycle::query()
                ->where('idCostumer', $tenantId)
                ->where('idProject', $projectId)
                ->where('id', $assetId)
                ->orderBy('name')
                ->get();
        }

        return TestCycle::query()
            ->where('idCostumer', $tenantId)
            ->where('idProject', $projectId)
            ->orderBy('name')
            ->get()
            ->filter(function (TestCycle $testCycle) use ($assetType, $assetId, $dependentTestIds) {
                if ($this->configReferences($testCycle->config, $this->referenceKeysFor($assetType), $assetId)) {
                    return true;
                }

                if ($dependentTestIds === []) {
                    return false;
                }

                return $this->configReferencesAny(
                    $testCycle->config,
                    $this->referenceKeysFor('test'),
                    $dependentTestIds
                );
            })
            ->values();
    }

    /**
     * @return array<int, string>
     */
    private function referenceKeysFor(string $assetType): array
    {
        return match ($assetType) {
            'environment' => ['environment', 'environments'],
            'plugin' => ['plugin', 'plugins'],
            'step' => ['step', 'steps'],
            'test' => ['test', 'tests'],
            'test_cycle' => ['testCycle', 'testCycles', 'test_cycle', 'test_cycles'],
            default => [],
        };
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function configReferences(mixed $config, array $keys, int $assetId): bool
    {
        return $this->configReferencesAny($config, $keys, [$assetId]);
    }

    /**
     * @param  array<int, string>  $keys
     * @param  array<int, int>  $assetIds
     */
    private function configReferencesAny(mixed $config, array $keys, array $assetIds): bool
    {
        $decoded = $this->decodeConfig($config);

        return $this->nodeReferencesAny($decoded, $keys, $assetIds);
    }

    /**
     * @param  array<int, string>  $keys
     * @param  array<int, int>  $assetIds
     */
    private function nodeReferencesAny(mixed $node, array $keys, array $assetIds, ?string $parentKey = null): bool
    {
        if (is_numeric($node) && $parentKey !== null && in_array($parentKey, $keys, true)) {
            return in_array((int) $node, $assetIds, true);
        }

        if (! is_array($node)) {
            return false;
        }

        foreach ($node as $key => $value) {
            $normalizedKey = is_string($key) ? $key : $parentKey;
            $keyMatches = $normalizedKey !== null && in_array($normalizedKey, $keys, true);

            if (
                $keyMatches
                && is_array($value)
                && $this->arrayContainsReference($value, $assetIds)
            ) {
                return true;
            }

            if ($this->nodeReferencesAny($value, $keys, $assetIds, $normalizedKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $value
     * @param  array<int, int>  $assetIds
     */
    private function arrayContainsReference(array $value, array $assetIds): bool
    {
        foreach ($value as $item) {
            if (is_numeric($item) && in_array((int) $item, $assetIds, true)) {
                return true;
            }

            if (
                is_array($item)
                && isset($item['id'])
                && is_numeric($item['id'])
                && in_array((int) $item['id'], $assetIds, true)
            ) {
                return true;
            }

            if (
                is_array($item)
                && isset($item['assetId'])
                && is_numeric($item['assetId'])
                && in_array((int) $item['assetId'], $assetIds, true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeConfig(mixed $config): array
    {
        if (is_array($config)) {
            return $config;
        }

        if (! is_string($config)) {
            return [];
        }

        $decoded = json_decode($config, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function testResponse(Test $test): array
    {
        return [
            'id' => $test->id,
            'name' => $test->name,
            'description' => $test->description,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function testCycleResponse(TestCycle $testCycle): array
    {
        return [
            'id' => $testCycle->id,
            'name' => $testCycle->name,
            'description' => $testCycle->description,
        ];
    }
}
