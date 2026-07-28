<?php

namespace App\Services;

use App\Models\AssetVersion;
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
    private function snapshot(Model $asset): array
    {
        return collect($asset->getAttributes())
            ->except(['created_at', 'updated_at'])
            ->all();
    }
}
