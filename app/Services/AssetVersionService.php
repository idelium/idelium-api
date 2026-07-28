<?php

namespace App\Services;

use App\Models\AssetVersion;
use App\Models\AssetVersionReviewEvent;
use App\Models\TestCycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
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
        $reviewEvent = $this->latestReviewEvent($version);
        $payload = [
            'id' => $version->id,
            'idProject' => $version->idProject,
            'assetType' => $version->assetType,
            'assetId' => $version->assetId,
            'version' => $version->version,
            'actorUserId' => $version->actorUserId,
            'reason' => $version->reason,
            'createdAt' => optional($version->created_at)->toISOString(),
            'review' => $this->reviewResponse($version, $reviewEvent),
        ];

        if ($includeSnapshot) {
            $payload['snapshot'] = $version->snapshot ?? [];
        }

        return $payload;
    }

    public function transitionReview(
        Request $request,
        AssetVersion $assetVersion,
        string $toStatus,
        ?string $comment = null
    ): AssetVersionReviewEvent {
        return DB::transaction(function () use ($request, $assetVersion, $toStatus, $comment) {
            $fromStatus = $this->currentReviewStatus($assetVersion);
            $this->assertAllowedReviewTransition($request, $assetVersion, $fromStatus, $toStatus);

            return AssetVersionReviewEvent::create([
                'idCostumer' => $assetVersion->idCostumer,
                'idProject' => $assetVersion->idProject,
                'assetVersionId' => $assetVersion->id,
                'fromStatus' => $fromStatus,
                'toStatus' => $toStatus,
                'comment' => $comment,
                'actorUserId' => optional($request->user())->id,
            ]);
        });
    }

    public function currentReviewStatus(AssetVersion $assetVersion): string
    {
        return $this->latestReviewEvent($assetVersion)?->toStatus
            ?? AssetVersionReviewEvent::STATUS_DRAFT;
    }

    /**
     * @return array<string, mixed>
     */
    public function reviewEventResponse(AssetVersionReviewEvent $event): array
    {
        return [
            'id' => $event->id,
            'assetVersionId' => $event->assetVersionId,
            'fromStatus' => $event->fromStatus,
            'toStatus' => $event->toStatus,
            'comment' => $event->comment,
            'actorUserId' => $event->actorUserId,
            'createdAt' => optional($event->created_at)->toISOString(),
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

    private function latestReviewEvent(AssetVersion $assetVersion): ?AssetVersionReviewEvent
    {
        return AssetVersionReviewEvent::query()
            ->where('idCostumer', $assetVersion->idCostumer)
            ->where('idProject', $assetVersion->idProject)
            ->where('assetVersionId', $assetVersion->id)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewResponse(
        AssetVersion $assetVersion,
        ?AssetVersionReviewEvent $event
    ): array {
        return [
            'status' => $event?->toStatus ?? AssetVersionReviewEvent::STATUS_DRAFT,
            'lastEventId' => $event?->id,
            'lastComment' => $event?->comment,
            'reviewedByUserId' => $event?->actorUserId,
            'reviewedAt' => optional($event?->created_at)->toISOString(),
            'authorUserId' => $assetVersion->actorUserId,
        ];
    }

    private function assertAllowedReviewTransition(
        Request $request,
        AssetVersion $assetVersion,
        string $fromStatus,
        string $toStatus
    ): void {
        $allowed = [
            AssetVersionReviewEvent::STATUS_DRAFT => [
                AssetVersionReviewEvent::STATUS_IN_REVIEW,
            ],
            AssetVersionReviewEvent::STATUS_IN_REVIEW => [
                AssetVersionReviewEvent::STATUS_APPROVED,
                AssetVersionReviewEvent::STATUS_DEPRECATED,
            ],
            AssetVersionReviewEvent::STATUS_APPROVED => [
                AssetVersionReviewEvent::STATUS_DEPRECATED,
            ],
            AssetVersionReviewEvent::STATUS_DEPRECATED => [],
        ];

        if (! in_array($toStatus, $allowed[$fromStatus] ?? [], true)) {
            throw new HttpResponseException(response()->json([
                'message' => 'The requested review transition is not allowed.',
                'fromStatus' => $fromStatus,
                'toStatus' => $toStatus,
            ], 422));
        }

        if (
            $toStatus === AssetVersionReviewEvent::STATUS_APPROVED
            && $assetVersion->actorUserId !== null
            && (int) $assetVersion->actorUserId === (int) optional($request->user())->id
        ) {
            throw new HttpResponseException(response()->json([
                'message' => 'Asset authors cannot approve their own versions.',
            ], 422));
        }
    }

    /**
     * @param  array<string, mixed>  $config
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
