<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReorderStepsRequest;
use App\Http\Requests\StoreStepRequest;
use App\Http\Requests\UpdateStepRequest;
use App\Models\Step;
use App\Services\TenantResourceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StepController extends Controller
{
    public function __construct(private TenantResourceService $tenantResources) {}

    public function index(Request $request, $idProject)
    {
        $this->tenantResources->project($request->user(), $idProject);

        return Step::select('id', 'name', 'description')
            ->orderBy('order', 'asc')
            ->where('idProject', $idProject)
            ->where('idCostumer', $request->user()->idCostumer)
            ->get();
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

        return $this->index($request, $idProject);
    }

    public function updateorder(ReorderStepsRequest $request, $idProject)
    {
        $this->tenantResources->project($request->user(), $idProject);

        DB::transaction(function () use ($request, $idProject) {
            foreach ($request->input('order') as $position => $stepObject) {
                $step = $this->tenantResources->resource(
                    $request->user(),
                    Step::class,
                    $idProject,
                    $stepObject['id'],
                    true
                );
                $step->order = $position;
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
