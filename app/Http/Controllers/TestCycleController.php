<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTestCycleRequest;
use App\Http\Requests\UpdateTestCycleRequest;
use App\Models\TestCycle;
use App\Services\TenantResourceService;
use Illuminate\Http\Request;

class TestCycleController extends Controller
{
    public function __construct(private TenantResourceService $tenantResources) {}

    public function index(Request $request, $idProject)
    {
        $this->tenantResources->project($request->user(), $idProject);

        return TestCycle::select('id', 'name', 'description')
            ->where('idCostumer', $request->user()->idCostumer)
            ->where('idProject', $idProject)->get();
    }

    public function store(StoreTestCycleRequest $request)
    {
        $this->tenantResources->project(
            $request->user(),
            $request->integer('idProject')
        );

        $testcycle = new TestCycle;
        $testcycle->name = $request->input('name');
        $testcycle->description = $request->input('description');
        $testcycle->config = $request->input('config');
        $testcycle->idProject = $request->integer('idProject');
        $testcycle->idCostumer = $request->user()->idCostumer;
        $testcycle->save();

        return $this->index($request, $request->integer('idProject'));
    }

    public function show(Request $request, $idProject, $id)
    {
        return $this->tenantResources
            ->resource($request->user(), TestCycle::class, $idProject, $id)
            ->only(['id', 'name', 'description', 'config', 'idProject']);
    }

    public function update(UpdateTestCycleRequest $request, $idProject, $id)
    {
        $testcycle = $this->tenantResources->resource(
            $request->user(),
            TestCycle::class,
            $idProject,
            $id
        );
        $testcycle->config = $request->input('config');
        $testcycle->description = $request->input('description');
        $testcycle->save();

        return $this->index($request, $idProject);
    }
}
