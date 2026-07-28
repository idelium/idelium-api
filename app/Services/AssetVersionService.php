<?php

namespace App\Services;

use App\Models\AssetVersion;
use App\Models\TestCycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AssetVersionService
{
    public function record(Request $request, Model $asset, string $assetType, string $reason): AssetVersion
    {
        return DB::transaction(function () use ($request, $asset, $assetType, $reason) {
            $assetId = (int) $asset->getKey();
            $tenantId = (int) $asset->getAttribute('idCostumer');
            $projectId = (int) $asset->getAttribute('idProject');
            $nextVersion = ((int) AssetVersion::where('idCostumer', $tenantId)
                ->where('assetType', $assetType)
                ->where('assetId', $assetId)
                ->max('version')) + 1;

            return AssetVersion::create([
                'idCostumer' => $tenantId,
                'idProject' => $projectId,
                'assetType' => $assetType,
                'assetId' => $assetId,
                'version' => $nextVersion,
                'actorUserId' => optional($request->user())->id,
                'reason' => $reason,
                'snapshot' => $this->snapshot($asset),
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function executionSnapshotForTestCycle(TestCycle $testCycle): array
    {
        $testCycleVersion = $this->latestVersion(
            (int) $testCycle->idCostumer,
            'test_cycle',
            (int) $testCycle->id
        );
        $config = $this->decodeConfig($testCycle->config);

        return [
            'schemaVersion' => '2026-07-28',
            'testCycle' => $this->versionReference($testCycleVersion),
            'tests' => $this->referencesFromConfig($config, 'tests', 'test', (int) $testCycle->idCostumer),
            'steps' => $this->referencesFromConfig($config, 'steps', 'step', (int) $testCycle->idCostumer),
            'environments' => $this->referencesFromConfig($config, 'environments', 'environment', (int) $testCycle->idCostumer),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Model $asset): array
    {
        return collect($asset->getAttributes())
            ->except(['created_at', 'updated_at'])
            ->all();
    }

    private function latestVersion(int $tenantId, string $assetType, int $assetId): ?AssetVersion
    {
        return AssetVersion::where('idCostumer', $tenantId)
            ->where('assetType', $assetType)
            ->where('assetId', $assetId)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, array<string, mixed>>
     */
    private function referencesFromConfig(
        array $config,
        string $key,
        string $assetType,
        int $tenantId
    ): array {
        $assetIds = collect($config[$key] ?? [])
            ->map(fn ($item) => is_array($item) ? ($item['id'] ?? $item['assetId'] ?? null) : $item)
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values();

        return $assetIds
            ->map(fn (int $assetId) => $this->versionReference(
                $this->latestVersion($tenantId, $assetType, $assetId),
                $assetType,
                $assetId
            ))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function versionReference(
        ?AssetVersion $version,
        ?string $fallbackType = null,
        ?int $fallbackId = null
    ): ?array {
        if ($version === null) {
            return $fallbackType === null ? null : [
                'assetType' => $fallbackType,
                'assetId' => $fallbackId,
                'version' => null,
            ];
        }

        return [
            'assetType' => $version->assetType,
            'assetId' => $version->assetId,
            'version' => $version->version,
            'versionId' => $version->id,
        ];
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
}
