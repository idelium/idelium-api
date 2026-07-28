<?php

namespace App\Http\Controllers;

use App\Models\ArtifactDescriptor;
use App\Services\CapabilityService;
use Illuminate\Http\Request;

class ArtifactDescriptorController extends Controller
{
    public function __construct(private readonly CapabilityService $capabilities) {}

    public function index(Request $request, int $idProject, int $performedTestCycleId)
    {
        $this->capabilities->require($request->user(), 'artifacts.read');
        $context = $this->tenantContext($request);

        return response()->json([
            'data' => ArtifactDescriptor::query()
                ->where('idCostumer', $context->activeTenantId)
                ->where('idProject', $idProject)
                ->where('performedTestCycleId', $performedTestCycleId)
                ->orderBy('artifactType')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function show(
        Request $request,
        int $idProject,
        int $performedTestCycleId,
        ArtifactDescriptor $artifactDescriptor
    ) {
        $this->capabilities->require($request->user(), 'artifacts.read');
        $context = $this->tenantContext($request);

        abort_unless(
            (int) $artifactDescriptor->idCostumer === $context->activeTenantId
            && (int) $artifactDescriptor->idProject === $idProject
            && (int) $artifactDescriptor->performedTestCycleId === $performedTestCycleId,
            404
        );

        return response()->json([
            'data' => $artifactDescriptor,
        ]);
    }
}
