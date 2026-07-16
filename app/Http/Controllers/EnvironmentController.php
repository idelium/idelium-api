<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEnvironmentRequest;
use App\Http\Requests\UpdateEnvironmentRequest;
use App\Models\Environment;
use App\Services\TenantResourceService;
use Illuminate\Http\Request;

class EnvironmentController extends Controller
{
    public function __construct(private TenantResourceService $tenantResources) {}

    public function index(Request $request, $idProject)
    {
        $this->tenantResources->project($request->user(), $idProject);

        return Environment::select('id', 'code', 'description')
            ->where('idProject', $idProject)
            ->where('idCostumer', $request->user()->idCostumer)
            ->get();
    }

    public function store(StoreEnvironmentRequest $request)
    {
        $projectId = $request->integer('idProject');
        $this->tenantResources->project($request->user(), $projectId);

        $environment = new Environment;
        $environment->code = $request->input('code');
        $environment->description = $request->input('description');
        $environment->config = $request->input('config');
        $environment->idProject = $projectId;
        $environment->idCostumer = $request->user()->idCostumer;
        $environment->save();

        return $this->index($request, $projectId);
    }

    public function show(Request $request, $idProject, $id)
    {
        return $this->tenantResources
            ->resource($request->user(), Environment::class, $idProject, $id)
            ->only(['id', 'code', 'description', 'config', 'idProject']);
    }

    public function update(UpdateEnvironmentRequest $request, $idProject, $id)
    {
        $environment = $this->tenantResources->resource(
            $request->user(),
            Environment::class,
            $idProject,
            $id
        );
        $environment->config = $request->input('config');
        $environment->save();

        return $this->index($request, $idProject);
    }

    public function destroy(Request $request, $idProject, $id)
    {
        $environment = $this->tenantResources->resource(
            $request->user(),
            Environment::class,
            $idProject,
            $id
        );
        $environment->delete();

        return $this->index($request, $idProject);
    }
}
