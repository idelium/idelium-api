<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePluginRequest;
use App\Http\Requests\UpdatePluginRequest;
use App\Models\Plugin;
use App\Services\TenantResourceService;
use Illuminate\Http\Request;

class PluginController extends Controller
{
    public function __construct(private TenantResourceService $tenantResources) {}

    public function index(Request $request, $idProject)
    {
        $this->tenantResources->project($request->user(), $idProject);

        return Plugin::select('id', 'name', 'description')->where(
            'idProject',
            $idProject
        )->where(
            'idCostumer',
            $request->user()->idCostumer
        )->get();
    }

    public function store(StorePluginRequest $request)
    {
        $projectId = $request->integer('idProject');
        $this->tenantResources->project($request->user(), $projectId);

        $plugin = new Plugin;
        $plugin->name = $request->input('name');
        $plugin->code = json_encode($request->input('code'));
        $plugin->description = $request->input('description');
        $plugin->idProject = $projectId;
        $plugin->idCostumer = $request->user()->idCostumer;

        $plugin->save();

        return $this->index($request, $projectId);
    }

    public function show(Request $request, $idProject, $id)
    {

        return $this->tenantResources
            ->resource($request->user(), Plugin::class, $idProject, $id)
            ->only(['id', 'name', 'description', 'code', 'idProject']);
    }

    public function update(UpdatePluginRequest $request, $idProject, $id)
    {
        $plugin = $this->tenantResources->resource(
            $request->user(),
            Plugin::class,
            $idProject,
            $id
        );
        $plugin->code = $request->input('code');
        $plugin->save();

        return $this->index($request, $idProject);
    }

    public function destroy(Request $request, $idProject, $id)
    {
        $plugin = $this->tenantResources->resource(
            $request->user(),
            Plugin::class,
            $idProject,
            $id
        );
        $plugin->delete();

        return $this->index($request, $idProject);
    }
}
