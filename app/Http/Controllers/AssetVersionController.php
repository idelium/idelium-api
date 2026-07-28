<?php

namespace App\Http\Controllers;

use App\Models\AssetVersion;
use App\Models\AssetVersionReviewEvent;
use App\Services\AssetVersionService;
use App\Services\AuditEventService;
use App\Services\CapabilityService;
use App\Services\TenantResourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssetVersionController extends Controller
{
    public function __construct(
        private readonly AssetVersionService $assetVersions,
        private readonly CapabilityService $capabilities,
        private readonly TenantResourceService $tenantResources,
        private readonly AuditEventService $auditEvents,
    ) {}

    public function index(
        Request $request,
        int $idProject,
        string $assetType,
        int $assetId
    ): JsonResponse {
        $this->capabilities->require($request->user(), 'resources.read');
        $validated = validator([
            'assetType' => $assetType,
            'assetId' => $assetId,
        ], [
            'assetType' => ['required', 'string', Rule::in(AssetVersionService::ALLOWED_ASSET_TYPES)],
            'assetId' => ['required', 'integer', 'min:1'],
        ])->validate();
        $project = $this->tenantResources->project($request->user(), $idProject);

        $versions = AssetVersion::query()
            ->where('idCostumer', $project->idCostumer)
            ->where('idProject', $project->id)
            ->where('assetType', $validated['assetType'])
            ->where('assetId', $validated['assetId'])
            ->orderByDesc('version')
            ->get()
            ->map(fn (AssetVersion $version) => $this->assetVersions->response($version, false))
            ->values();

        return response()->json([
            'data' => $versions,
        ]);
    }

    public function show(
        Request $request,
        int $idProject,
        AssetVersion $assetVersion
    ): JsonResponse {
        $this->capabilities->require($request->user(), 'resources.read');
        $this->assertOwnedVersion($request, $idProject, $assetVersion);

        return response()->json([
            'data' => $this->assetVersions->response($assetVersion),
        ]);
    }

    public function diff(
        Request $request,
        int $idProject,
        AssetVersion $fromVersion,
        AssetVersion $toVersion
    ): JsonResponse {
        $this->capabilities->require($request->user(), 'resources.read');
        $this->assertOwnedVersion($request, $idProject, $fromVersion);
        $this->assertOwnedVersion($request, $idProject, $toVersion);

        abort_unless(
            $fromVersion->assetType === $toVersion->assetType
            && (int) $fromVersion->assetId === (int) $toVersion->assetId,
            422,
            'Asset versions must belong to the same asset before they can be compared.'
        );

        return response()->json([
            'data' => $this->assetVersions->diff($fromVersion, $toVersion),
        ]);
    }

    public function transitionReview(
        Request $request,
        int $idProject,
        AssetVersion $assetVersion
    ): JsonResponse {
        $this->capabilities->require($request->user(), 'resources.manage');
        $this->assertOwnedVersion($request, $idProject, $assetVersion);
        $validated = $request->validate([
            'toStatus' => [
                'required',
                'string',
                Rule::in([
                    AssetVersionReviewEvent::STATUS_IN_REVIEW,
                    AssetVersionReviewEvent::STATUS_APPROVED,
                    AssetVersionReviewEvent::STATUS_DEPRECATED,
                ]),
            ],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $fromStatus = $this->assetVersions->currentReviewStatus($assetVersion);
        $event = $this->assetVersions->transitionReview(
            $request,
            $assetVersion,
            $validated['toStatus'],
            $validated['comment'] ?? null
        );

        $this->auditEvents->record(
            $request,
            'asset_version.review_transitioned',
            'asset_version',
            (string) $assetVersion->id,
            projectId: (int) $assetVersion->idProject,
            beforeValues: ['status' => $fromStatus],
            afterValues: ['status' => $event->toStatus],
            metadata: [
                'assetType' => $assetVersion->assetType,
                'assetId' => $assetVersion->assetId,
                'version' => $assetVersion->version,
                'reviewEventId' => $event->id,
            ]
        );

        return response()->json([
            'data' => $this->assetVersions->reviewEventResponse($event),
        ], 201);
    }

    private function assertOwnedVersion(
        Request $request,
        int $idProject,
        AssetVersion $assetVersion
    ): void {
        $project = $this->tenantResources->project($request->user(), $idProject);

        abort_unless(
            (int) $assetVersion->idCostumer === (int) $project->idCostumer
            && (int) $assetVersion->idProject === (int) $project->id,
            404
        );
    }
}
