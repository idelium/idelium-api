<?php

namespace App\Services;

use App\Models\AssetVersion;
use App\Models\TestCycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AssetVersionService
{
    public const ALLOWED_ASSET_TYPES = [
        'environment',
        'step',
        'test',
        'test_cycle',
    ];

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
    public function diff(AssetVersion $from, AssetVersion $to): array
    {
        $fromSnapshot = $from->snapshot ?? [];
        $toSnapshot = $to->snapshot ?? [];
        $keys = collect(array_keys($fromSnapshot))
            ->merge(array_keys($toSnapshot))
            ->unique()
            ->sort()
            ->values();

        $added = [];
        $removed = [];
        $changed = [];

        foreach ($keys as $key) {
            $fromHasKey = array_key_exists($key, $fromSnapshot);
            $toHasKey = array_key_exists($key, $toSnapshot);

            if (! $fromHasKey && $toHasKey) {
                $added[$key] = $toSnapshot[$key];

                continue;
            }

            if ($fromHasKey && ! $toHasKey) {
                $removed[$key] = $fromSnapshot[$key];

                continue;
            }

            if ($fromSnapshot[$key] !== $toSnapshot[$key]) {
                $changed[$key] = [
                    'from' => $fromSnapshot[$key],
                    'to' => $toSnapshot[$key],
                ];
            }
        }

        return [
            'from' => $this->versionReference($from),
            'to' => $this->versionReference($to),
            'changes' => [
                'added' => $added,
                'removed' => $removed,
                'changed' => $changed,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function response(AssetVersion $version, bool $includeSnapshot = true): array
    {
        $payload = [
            'id' => $version->id,
            'idProject' => $version->idProject,
            'assetType' => $version->assetType,
            'assetId' => $version->assetId,
            'version' => $version->version,
            'actorUserId' => $version->actorUserId,
            'reason' => $version->reason,
            'createdAt' => optional($version->created_at)->toISOString(),
        ];

        if ($includeSnapshot) {
            $payload['snapshot'] = $version->snapshot ?? [];
        }

        return $payload;
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
