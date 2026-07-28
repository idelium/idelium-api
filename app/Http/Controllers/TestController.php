<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTestRequest;
use App\Http\Requests\UpdateTestRequest;
use App\Models\Test;
use App\Services\AssetVersionService;
use App\Services\TenantResourceService;
use Illuminate\Http\Request;

class TestController extends Controller
{
    public function __construct(
        private TenantResourceService $tenantResources,
        private AssetVersionService $assetVersions,
    ) {}

    public function index(Request $request, $idProject)
    {
        $this->tenantResources->project($request->user(), $idProject);

        return Test::select('id', 'name', 'description')
            ->where('idCostumer', $request->user()->idCostumer)
            ->where('idProject', $idProject)->get();
    }

    public function store(StoreTestRequest $request)
    {
        $projectId = $request->integer('idProject');
        $this->tenantResources->project($request->user(), $projectId);

        $test = new Test;
        $test->name = $request->input('name');
        $test->description = $request->input('description');
        $test->config = $request->input('config');
        $test->idProject = $projectId;
        $test->idCostumer = $request->user()->idCostumer;
        $test->save();
        $this->assetVersions->record($request, $test, 'test', 'asset.created');

        return $this->index($request, $projectId);
    }

    public function show(Request $request, $idProject, $id)
    {
        return $this->tenantResources
            ->resource($request->user(), Test::class, $idProject, $id)
            ->only(['id', 'name', 'description', 'config', 'idProject']);
    }

    public function update(UpdateTestRequest $request, $idProject, $id)
    {
        $test = $this->tenantResources->resource(
            $request->user(),
            Test::class,
            $idProject,
            $id
        );
        $test->config = $request->input('config');
        $test->save();
        $this->assetVersions->record($request, $test, 'test', 'asset.updated');

        return $this->index($request, $idProject);
    }
}
