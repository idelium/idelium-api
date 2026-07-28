<?php

namespace App\Http\Controllers;

use App\Services\AssetImpactService;
use App\Services\CapabilityService;
use App\Services\TenantResourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssetImpactController extends Controller
{
    public function __construct(
        private readonly AssetImpactService $assetImpact,
        private readonly CapabilityService $capabilities,
        private readonly TenantResourceService $tenantResources,
    ) {}

    public function show(
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
            'assetType' => ['required', 'string', Rule::in(AssetImpactService::ALLOWED_ASSET_TYPES)],
            'assetId' => ['required', 'integer', 'min:1'],
        ])->validate();
        $project = $this->tenantResources->project($request->user(), $idProject);

        return response()->json([
            'data' => $this->assetImpact->impact(
                (int) $project->idCostumer,
                (int) $project->id,
                $validated['assetType'],
                (int) $validated['assetId']
            ),
        ]);
    }
}
