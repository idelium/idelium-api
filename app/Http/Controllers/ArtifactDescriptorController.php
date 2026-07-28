<?php

namespace App\Http\Controllers;

use App\Models\ArtifactDescriptor;
use App\Services\ArtifactLifecycleService;
use App\Services\AuditEventService;
use App\Services\CapabilityService;
use Illuminate\Http\Request;

class ArtifactDescriptorController extends Controller
{
    public function __construct(
        private readonly CapabilityService $capabilities,
        private readonly ArtifactLifecycleService $artifactLifecycle,
        private readonly AuditEventService $auditEvents,
    ) {}

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

    public function impact(
        Request $request,
        int $idProject,
        int $performedTestCycleId,
        ArtifactDescriptor $artifactDescriptor
    ) {
        $this->capabilities->require($request->user(), 'artifacts.read');
        $this->assertOwnedArtifact($request, $idProject, $performedTestCycleId, $artifactDescriptor);

        return response()->json([
            'data' => $this->artifactLifecycle->impactSummary($artifactDescriptor),
        ]);
    }

    public function legalHold(
        Request $request,
        int $idProject,
        int $performedTestCycleId,
        ArtifactDescriptor $artifactDescriptor
    ) {
        $this->capabilities->require($request->user(), 'artifacts.manage');
        $this->assertOwnedArtifact($request, $idProject, $performedTestCycleId, $artifactDescriptor);
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $before = $artifactDescriptor->metadata ?? [];
        $artifact = $this->artifactLifecycle->setLegalHold(
            $artifactDescriptor,
            (bool) $validated['enabled'],
            $validated['reason'] ?? null
        );

        $this->auditEvents->record(
            $request,
            'artifact.legal_hold',
            'artifact_descriptor',
            (string) $artifact->id,
            beforeValues: $before,
            afterValues: $artifact->metadata ?? [],
            projectId: $artifact->idProject,
        );

        return response()->json([
            'data' => $artifact,
        ]);
    }

    public function markDeleted(
        Request $request,
        int $idProject,
        int $performedTestCycleId,
        ArtifactDescriptor $artifactDescriptor
    ) {
        $this->capabilities->require($request->user(), 'artifacts.manage');
        $this->assertOwnedArtifact($request, $idProject, $performedTestCycleId, $artifactDescriptor);
        $before = ['state' => $artifactDescriptor->state];
        $artifact = $this->artifactLifecycle->markDeleted($artifactDescriptor);

        $this->auditEvents->record(
            $request,
            'artifact.mark_deleted',
            'artifact_descriptor',
            (string) $artifact->id,
            beforeValues: $before,
            afterValues: ['state' => $artifact->state],
            projectId: $artifact->idProject,
        );

        return response()->json([
            'data' => $artifact,
        ]);
    }

    public function archive(
        Request $request,
        int $idProject,
        int $performedTestCycleId,
        ArtifactDescriptor $artifactDescriptor
    ) {
        $this->capabilities->require($request->user(), 'artifacts.manage');
        $this->assertOwnedArtifact($request, $idProject, $performedTestCycleId, $artifactDescriptor);
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
            'restoreBy' => ['nullable', 'date'],
        ]);
        $before = [
            'state' => $artifactDescriptor->state,
            'metadata' => $artifactDescriptor->metadata,
        ];
        $artifact = $this->artifactLifecycle->archive(
            $artifactDescriptor,
            $validated['reason'] ?? null,
            $validated['restoreBy'] ?? null
        );

        $this->auditEvents->record(
            $request,
            'artifact.archive',
            'artifact_descriptor',
            (string) $artifact->id,
            beforeValues: $before,
            afterValues: [
                'state' => $artifact->state,
                'metadata' => $artifact->metadata,
            ],
            projectId: $artifact->idProject,
        );

        return response()->json([
            'data' => $artifact,
        ]);
    }

    public function restore(
        Request $request,
        int $idProject,
        int $performedTestCycleId,
        ArtifactDescriptor $artifactDescriptor
    ) {
        $this->capabilities->require($request->user(), 'artifacts.manage');
        $this->assertOwnedArtifact($request, $idProject, $performedTestCycleId, $artifactDescriptor);
        $before = [
            'state' => $artifactDescriptor->state,
            'metadata' => $artifactDescriptor->metadata,
        ];
        $artifact = $this->artifactLifecycle->restore($artifactDescriptor);

        $this->auditEvents->record(
            $request,
            'artifact.restore',
            'artifact_descriptor',
            (string) $artifact->id,
            beforeValues: $before,
            afterValues: [
                'state' => $artifact->state,
                'metadata' => $artifact->metadata,
            ],
            projectId: $artifact->idProject,
        );

        return response()->json([
            'data' => $artifact,
        ]);
    }

    private function assertOwnedArtifact(
        Request $request,
        int $idProject,
        int $performedTestCycleId,
        ArtifactDescriptor $artifactDescriptor
    ): void {
        $context = $this->tenantContext($request);

        abort_unless(
            (int) $artifactDescriptor->idCostumer === $context->activeTenantId
            && (int) $artifactDescriptor->idProject === $idProject
            && (int) $artifactDescriptor->performedTestCycleId === $performedTestCycleId,
            404
        );
    }
}
