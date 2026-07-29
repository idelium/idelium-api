<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReorderStepsRequest;
use App\Http\Requests\StoreStepRequest;
use App\Http\Requests\UpdateStepRequest;
use App\Models\Step;
use App\Services\AssetVersionService;
use App\Services\TenantResourceService;
use App\Support\EnterpriseGridResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StepController extends Controller
{
    public function __construct(
        private TenantResourceService $tenantResources,
        private AssetVersionService $assetVersions,
    ) {}

    public function index(Request $request, $idProject)
    {
        $this->tenantResources->project($request->user(), $idProject);

        $query = Step::select('id', 'name', 'description', 'order')
            ->where('idProject', $idProject)
            ->where('idCostumer', $request->user()->idCostumer);

        return app(EnterpriseGridResponse::class)->build(
            $request,
            $query,
            ['id', 'name', 'description', 'order', 'created_at', 'updated_at'],
            'order',
            'asc',
            ['name', 'description'],
            ['id', 'name']
        );
    }

    public function store(StoreStepRequest $request)
    {
        $projectId = $request->integer('idProject');
        $this->tenantResources->project($request->user(), $projectId);

        $step = new Step;
        $step->name = $request->input('name');
        $step->description = $request->input('description');
        $step->config = $request->input('config');
        $step->idProject = $projectId;
        $step->idCostumer = $request->user()->idCostumer;
        $step->order = 9999999;
        $step->save();
        $this->assetVersions->record($request, $step, 'step', 'asset.created');

        return $this->index($request, $projectId);
    }

    public function show(Request $request, $idProject, $id)
    {

        return $this->tenantResources
            ->resource($request->user(), Step::class, $idProject, $id)
            ->only(['id', 'name', 'description', 'config', 'idProject', 'order']);
    }

    public function update(UpdateStepRequest $request, $idProject, $id)
    {
        $step = $this->tenantResources->resource(
            $request->user(),
            Step::class,
            $idProject,
            $id
        );
        $step->name = $request->input('name');
        $step->description = $request->input('description');
        $step->config = $request->input('config');
        $step->save();
        $this->assetVersions->record($request, $step, 'step', 'asset.updated');

        return $this->index($request, $idProject);
    }

    public function updateorder(ReorderStepsRequest $request, $idProject)
    {
        $this->tenantResources->project($request->user(), $idProject);

        DB::transaction(function () use ($request, $idProject) {
            $offset = $request->integer('offset');
            foreach ($request->input('order') as $position => $stepObject) {
                $step = $this->tenantResources->resource(
                    $request->user(),
                    Step::class,
                    $idProject,
                    $stepObject['id'],
                    true
                );
                $step->order = $offset + $position;
                $step->save();
            }
        });

        return $this->index($request, $idProject);
    }

    public function destroy(Request $request, $idProject, $id)
    {
        $step = $this->tenantResources->resource(
            $request->user(),
            Step::class,
            $idProject,
            $id
        );
        $step->delete();

        return $this->index($request, $idProject);
    }
}
