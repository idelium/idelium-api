<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGridBulkJobRequest;
use App\Http\Requests\StoreGridQuerySnapshotRequest;
use App\Models\GridBulkOperationJob;
use App\Models\GridQuerySnapshot;
use App\Models\Project;
use App\Services\AuditEventService;
use App\Services\CapabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GridBulkOperationController extends Controller
{
    private const MAX_SNAPSHOT_ROWS = 1000;

    public function __construct(
        private readonly CapabilityService $capabilities,
        private readonly AuditEventService $audit,
    ) {}

    public function storeSnapshot(StoreGridQuerySnapshotRequest $request)
    {
        $this->requireRead($request);
        $validated = $request->validated();
        $queryState = $validated['query'] ?? [];
        $query = $this->projectsQuery($request, $queryState);
        $entityIds = $query->limit(self::MAX_SNAPSHOT_ROWS + 1)->pluck('id');

        if ($entityIds->count() > self::MAX_SNAPSHOT_ROWS) {
            return response()->json([
                'error' => [
                    'code' => 'GRID_SNAPSHOT_TOO_LARGE',
                    'message' => 'The matching result exceeds the bulk operation limit.',
                    'details' => ['limit' => self::MAX_SNAPSHOT_ROWS],
                ],
            ], 422);
        }

        $snapshot = GridQuerySnapshot::create([
            'id' => (string) Str::uuid(),
            'idCostumer' => $request->user()->idCostumer,
            'actorUserId' => $request->user()->id,
            'resourceType' => $validated['resourceType'],
            'query' => $queryState,
            'entityIds' => $entityIds->values()->all(),
            'total' => $entityIds->count(),
            'expiresAt' => now()->addMinutes(15),
        ]);

        return response()->json(['data' => $this->snapshotData($snapshot)], 201);
    }

    public function storeJob(StoreGridBulkJobRequest $request)
    {
        $validated = $request->validated();
        $snapshot = $this->snapshotForActor(
            $request,
            $validated['querySnapshotId'],
        );
        $this->requireAction($request, $validated['action']);

        $job = DB::transaction(function () use ($request, $validated, $snapshot) {
            $job = GridBulkOperationJob::create([
                'id' => (string) Str::uuid(),
                'querySnapshotId' => $snapshot->id,
                'idCostumer' => $request->user()->idCostumer,
                'actorUserId' => $request->user()->id,
                'resourceType' => $snapshot->resourceType,
                'action' => $validated['action'],
                'status' => 'running',
                'payload' => $validated['payload'] ?? null,
                'requestedCount' => $snapshot->total,
            ]);

            $projects = Project::query()
                ->where('idCostumer', $request->user()->idCostumer)
                ->whereIn('id', $snapshot->entityIds)
                ->get();
            $processedIds = $projects->pluck('id')->map(fn ($id) => (int) $id);
            $missingIds = collect($snapshot->entityIds)
                ->map(fn ($id) => (int) $id)
                ->diff($processedIds)
                ->values();

            if ($validated['action'] === 'archive') {
                Project::query()
                    ->where('idCostumer', $request->user()->idCostumer)
                    ->whereIn('id', $processedIds)
                    ->update(['archivedAt' => now()]);
            } elseif ($validated['action'] === 'tag') {
                $tags = array_values(array_unique($validated['payload']['tags']));
                foreach ($projects as $project) {
                    $project->tags = array_values(array_unique([
                        ...($project->tags ?? []),
                        ...$tags,
                    ]));
                    $project->save();
                }
            }

            $job->processedCount = $processedIds->count();
            $job->failedCount = $missingIds->count();
            $job->status = $missingIds->isEmpty() ? 'completed' : 'partial';
            $job->result = [
                'failedEntityIds' => $missingIds->all(),
                'exportAvailable' => $validated['action'] === 'export',
            ];
            $job->save();

            $this->audit->record(
                $request,
                'grid.bulk.'.$validated['action'],
                $snapshot->resourceType,
                $job->id,
                projectId: null,
                metadata: [
                    'requestedCount' => $job->requestedCount,
                    'processedCount' => $job->processedCount,
                    'failedCount' => $job->failedCount,
                    'status' => $job->status,
                ],
            );

            return $job;
        });

        return response()->json(['data' => $this->jobData($job)], 202);
    }

    public function showJob(Request $request, string $jobId)
    {
        $job = $this->jobForActor($request, $jobId);

        return response()->json(['data' => $this->jobData($job)]);
    }

    public function exportJob(Request $request, string $jobId): StreamedResponse
    {
        $job = $this->jobForActor($request, $jobId);
        abort_unless(
            $job->action === 'export' && $job->status === 'completed',
            409,
            'The export is not available.',
        );
        $snapshot = $this->snapshotForActor($request, $job->querySnapshotId);
        $projects = Project::query()
            ->select('id', 'name', 'description', 'archivedAt', 'tags')
            ->where('idCostumer', $request->user()->idCostumer)
            ->whereIn('id', $snapshot->entityIds)
            ->orderBy('id')
            ->get();

        return response()->streamDownload(function () use ($projects) {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['id', 'name', 'description', 'archivedAt', 'tags']);
            foreach ($projects as $project) {
                fputcsv($stream, [
                    $project->id,
                    $this->safeCsvValue($project->name),
                    $this->safeCsvValue($project->description),
                    optional($project->archivedAt)->toIso8601String(),
                    implode(',', $project->tags ?? []),
                ]);
            }
            fclose($stream);
        }, "idelium-projects-{$job->id}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function projectsQuery(Request $request, array $queryState)
    {
        $query = Project::query()
            ->select('id')
            ->where('idCostumer', $request->user()->idCostumer)
            ->whereNull('archivedAt');
        $search = trim((string) ($queryState['q'] ?? $queryState['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }
        $filters = $queryState['f'] ?? [];
        if (isset($filters['id'])) {
            $query->where('id', (int) $filters['id']);
        }
        if (isset($filters['name'])) {
            $query->where('name', $filters['name']);
        }
        $sort = $queryState['sort'] ?? 'id';
        $direction = ($queryState['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        return $query->orderBy($sort, $direction)->orderBy('id');
    }

    private function snapshotForActor(Request $request, string $snapshotId): GridQuerySnapshot
    {
        return GridQuerySnapshot::query()
            ->where('id', $snapshotId)
            ->where('idCostumer', $request->user()->idCostumer)
            ->where('actorUserId', $request->user()->id)
            ->where('expiresAt', '>', now())
            ->firstOrFail();
    }

    private function jobForActor(Request $request, string $jobId): GridBulkOperationJob
    {
        return GridBulkOperationJob::query()
            ->where('id', $jobId)
            ->where('idCostumer', $request->user()->idCostumer)
            ->where('actorUserId', $request->user()->id)
            ->firstOrFail();
    }

    private function requireRead(Request $request): void
    {
        if (
            ! $this->capabilities->has($request->user(), 'projects.read')
            && ! $this->capabilities->has($request->user(), 'projects.manage')
        ) {
            $this->capabilities->require($request->user(), 'projects.read');
        }
    }

    private function requireAction(Request $request, string $action): void
    {
        if ($action === 'export') {
            $this->requireRead($request);

            return;
        }
        $this->capabilities->require($request->user(), 'projects.manage');
    }

    private function snapshotData(GridQuerySnapshot $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'resourceType' => $snapshot->resourceType,
            'total' => $snapshot->total,
            'expiresAt' => $snapshot->expiresAt->toIso8601String(),
        ];
    }

    private function jobData(GridBulkOperationJob $job): array
    {
        return [
            'id' => $job->id,
            'resourceType' => $job->resourceType,
            'action' => $job->action,
            'status' => $job->status,
            'requestedCount' => $job->requestedCount,
            'processedCount' => $job->processedCount,
            'failedCount' => $job->failedCount,
            'result' => $job->result,
        ];
    }

    private function safeCsvValue(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
