<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePluginRequest;
use App\Http\Requests\UpdatePluginRequest;
use App\Models\Plugin;
use App\Services\PluginManifestService;
use App\Services\TenantResourceService;
use App\Support\EnterpriseGridResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PluginController extends Controller
{
    public function __construct(
        private TenantResourceService $tenantResources,
        private PluginManifestService $pluginManifests
    ) {}

    public function index(Request $request, $idProject)
    {
        $this->tenantResources->project($request->user(), $idProject);

        $query = Plugin::select('id', 'name', 'description', 'code')
            ->where('idProject', $idProject)
            ->where('idCostumer', $request->user()->idCostumer);

        return app(EnterpriseGridResponse::class)->build(
            $request,
            $query,
            ['id', 'name', 'description', 'created_at', 'updated_at'],
            'created_at',
            'asc',
            ['name', 'description'],
            ['id', 'name'],
            function (Plugin $plugin) {
                return [
                    'id' => $plugin->id,
                    'name' => $plugin->name,
                    'description' => $plugin->description,
                    ...$this->pluginManifests->metadata($plugin),
                ];
            },
        );
    }

    public function store(StorePluginRequest $request)
    {
        $projectId = $request->integer('idProject');
        $this->tenantResources->project($request->user(), $projectId);

        $plugin = new Plugin;
        $plugin->name = $request->input('name');
        $plugin->code = $this->encodeManifest(
            $request->input('code'),
            $request->input('name')
        );
        $plugin->description = $request->input('description');
        $plugin->idProject = $projectId;
        $plugin->idCostumer = $request->user()->idCostumer;

        $plugin->save();

        return $this->index($request, $projectId);
    }

    public function show(Request $request, $idProject, $id)
    {
        $plugin = $this->tenantResources
            ->resource($request->user(), Plugin::class, $idProject, $id);

        $payload = $plugin->only(['id', 'name', 'description', 'code', 'idProject']);
        $payload['code'] = $this->pluginManifests->webEditorPayload($plugin);

        return $payload;
    }

    public function update(UpdatePluginRequest $request, $idProject, $id)
    {
        $plugin = $this->tenantResources->resource(
            $request->user(),
            Plugin::class,
            $idProject,
            $id
        );
        $plugin->code = $this->encodeManifest($request->input('code'), $plugin->name);
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

    private function encodeManifest(mixed $payload, string $pluginName): string
    {
        try {
            return json_encode(
                $this->pluginManifests->normalizeForStorage($payload, $pluginName),
                JSON_THROW_ON_ERROR
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'code' => [$exception->getMessage()],
            ]);
        }
    }
}
