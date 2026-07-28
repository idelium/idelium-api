<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEnvironmentRequest;
use App\Http\Requests\UpdateEnvironmentRequest;
use App\Models\Environment;
use App\Services\AssetVersionService;
use App\Services\EnvironmentSecretPolicy;
use App\Services\TenantResourceService;
use Illuminate\Http\Request;

class EnvironmentController extends Controller
{
    public function __construct(
        private TenantResourceService $tenantResources,
        private EnvironmentSecretPolicy $environmentSecrets,
        private AssetVersionService $assetVersions,
    ) {}

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
        $this->environmentSecrets->assertNoInlineSecrets($request->input('config'));

        $environment = new Environment;
        $environment->code = $request->input('code');
        $environment->description = $request->input('description');
        $environment->config = $request->input('config');
        $environment->idProject = $projectId;
        $environment->idCostumer = $request->user()->idCostumer;
        $environment->save();
        $this->assetVersions->record($request, $environment, 'environment', 'asset.created');

        return $this->index($request, $projectId);
    }

    public function show(Request $request, $idProject, $id)
    {
        $environment = $this->tenantResources
            ->resource($request->user(), Environment::class, $idProject, $id);

        return [
            'id' => $environment->id,
            'code' => $environment->code,
            'description' => $environment->description,
            'config' => $this->environmentSecrets->redactConfig($environment->config),
            'idProject' => $environment->idProject,
        ];
    }

    public function update(UpdateEnvironmentRequest $request, $idProject, $id)
    {
        $environment = $this->tenantResources->resource(
            $request->user(),
            Environment::class,
            $idProject,
            $id
        );
        $this->environmentSecrets->assertNoInlineSecrets($request->input('config'));
        $environment->config = $request->input('config');
        $environment->save();
        $this->assetVersions->record($request, $environment, 'environment', 'asset.updated');

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
