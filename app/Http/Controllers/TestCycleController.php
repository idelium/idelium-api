<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTestCycleRequest;
use App\Http\Requests\UpdateTestCycleRequest;
use App\Models\TestCycle;
use App\Services\AssetVersionService;
use App\Services\TenantResourceService;
use App\Support\EnterpriseGridResponse;
use Illuminate\Http\Request;

class TestCycleController extends Controller
{
    public function __construct(
        private TenantResourceService $tenantResources,
        private AssetVersionService $assetVersions,
    ) {}

    public function index(Request $request, $idProject)
    {
        $this->tenantResources->project($request->user(), $idProject);

        $query = TestCycle::select('id', 'name', 'description', 'created_at', 'updated_at')
            ->where('idCostumer', $request->user()->idCostumer)
            ->where('idProject', $idProject);

        return app(EnterpriseGridResponse::class)->build(
            $request,
            $query,
            ['id', 'name', 'description', 'created_at', 'updated_at'],
            'id',
            'asc',
            ['name', 'description'],
            ['id', 'name']
        );
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
        $this->assetVersions->record($request, $testcycle, 'test_cycle', 'asset.created');

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
        $this->assetVersions->record($request, $testcycle, 'test_cycle', 'asset.updated');

        return $this->index($request, $idProject);
    }
}
