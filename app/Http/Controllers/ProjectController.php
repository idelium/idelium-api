<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Environment;
use App\Models\PerformedStep;
use App\Models\PerformedTest;
use App\Models\PerformedTestCycle;
use App\Models\Plugin;
use App\Models\Project;
use App\Models\Step;
use App\Models\Test;
use App\Models\TestCycle;
use App\Services\TenantResourceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    public function __construct(private TenantResourceService $tenantResources) {}

    public function index(Request $request)
    {
        return Project::select('id', 'name', 'description')
            ->where('idCostumer', $request->user()->idCostumer)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    public function store(StoreProjectRequest $request)
    {
        $project = new Project;
        $project->name = strtoupper($request->input('name'));
        $project->description = $request->input('description');
        $project->idCostumer = $request->user()->idCostumer;
        $project->save();

        return $this->index($request);
    }

    public function show(Request $request, $id)
    {
        return $this->tenantResources->project($request->user(), $id)
            ->only(['id', 'name', 'description']);
    }

    public function update(UpdateProjectRequest $request, $id)
    {
        $project = $this->tenantResources->project($request->user(), $id);
        $project->name = $request->input('name');
        $project->description = $request->input('description');
        $project->save();

        return $this->index($request);
    }

    public function destroy(Request $request, $id)
    {
        $customerId = $request->user()->idCostumer;

        DB::transaction(function () use ($request, $id, $customerId) {
            $project = $this->tenantResources->project(
                $request->user(),
                $id,
                true
            );

            $testCycleIds = TestCycle::where('idCostumer', $customerId)
                ->where('idProject', $id)
                ->pluck('id');
            $performedCycleIds = PerformedTestCycle::whereIn('testCycleId', $testCycleIds)
                ->where('idCostumer', $customerId)
                ->pluck('id');

            PerformedStep::whereIn('testCycleDoneId', $performedCycleIds)
                ->where('idCostumer', $customerId)->delete();
            PerformedTest::whereIn('testCycleDoneId', $performedCycleIds)
                ->where('idCostumer', $customerId)->delete();
            PerformedTestCycle::whereIn('id', $performedCycleIds)
                ->where('idCostumer', $customerId)->delete();
            TestCycle::whereIn('id', $testCycleIds)
                ->where('idCostumer', $customerId)->delete();
            Environment::where('idProject', $id)
                ->where('idCostumer', $customerId)->delete();
            Plugin::where('idProject', $id)
                ->where('idCostumer', $customerId)->delete();
            Step::where('idProject', $id)
                ->where('idCostumer', $customerId)->delete();
            Test::where('idProject', $id)
                ->where('idCostumer', $customerId)->delete();
            $project->delete();
        });

        return $this->index($request);
    }
}
