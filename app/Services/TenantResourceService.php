<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class TenantResourceService
{
    public function project(User $user, int $projectId, bool $lock = false): Project
    {
        $query = Project::whereKey($projectId)
            ->where('idCostumer', $user->idCostumer);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /**
     * Find a project-owned model without revealing another customer's data.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @return TModel
     */
    public function resource(
        User $user,
        string $modelClass,
        int $projectId,
        int $resourceId,
        bool $lock = false
    ): Model {
        $this->project($user, $projectId);

        $query = $modelClass::query()
            ->whereKey($resourceId)
            ->where('idProject', $projectId)
            ->where('idCostumer', $user->idCostumer);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }
}
