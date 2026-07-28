<?php

namespace App\Http\Controllers;

use App\Models\Costumer;
use App\Models\ParallelRunSchedule;
use App\Models\Project;
use App\Models\TestCycle;
use App\Services\AuditEventService;
use App\Services\AssetVersionService;
use App\Services\RunTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ParallelRunScheduleController extends Controller
{
    private const MAX_CONCURRENCY = 32;

    private const DEFAULT_WORKER_LEASE_SECONDS = 120;

    private const TERMINAL_STATUSES = [
        ParallelRunSchedule::STATUS_CANCELLED,
        ParallelRunSchedule::STATUS_COMPLETED,
        ParallelRunSchedule::STATUS_FAILED,
        ParallelRunSchedule::STATUS_LOST,
    ];

    public function __construct(
        private readonly RunTokenService $runTokens,
        private readonly AuditEventService $auditEvents,
        private readonly AssetVersionService $assetVersions,
    ) {}

    public function index(Request $request, int $idProject): JsonResponse
    {
        $customer = $this->customerFromRequest($request);
        $this->ownedProject($customer, $idProject);

        $schedules = ParallelRunSchedule::where('idProject', $idProject)
            ->where('idCostumer', $customer->id)
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get()
            ->map(fn (ParallelRunSchedule $schedule) => $this->scheduleResponse($schedule))
            ->values();

        return response()->json($schedules);
    }

    public function store(Request $request, int $idProject): JsonResponse
    {
        $customer = $this->customerFromRequest($request);

        $validated = $request->validate([
            'testCycleId' => ['required', 'integer'],
            'idempotencyKey' => ['required', 'string', 'max:128'],
            'requestedConcurrency' => [
                'sometimes',
                'integer',
                'min:1',
                'max:'.self::MAX_CONCURRENCY,
            ],
            'metadata' => ['sometimes', 'array'],
        ]);

        $schedule = DB::transaction(function () use ($customer, $idProject, $validated) {
            $this->ownedProject($customer, $idProject, true);
            $testCycle = $this->ownedTestCycle(
                $customer,
                $idProject,
                (int) $validated['testCycleId'],
                true
            );
            $metadata = $validated['metadata'] ?? [];
            $metadata['executionSnapshot'] = $this->assetVersions
                ->executionSnapshotForTestCycle($testCycle);

            return ParallelRunSchedule::firstOrCreate([
                'idCostumer' => $customer->id,
                'idProject' => $idProject,
                'idempotencyKey' => $validated['idempotencyKey'],
            ], [
                'testCycleId' => (int) $validated['testCycleId'],
                'requestedConcurrency' => (int) ($validated['requestedConcurrency'] ?? 1),
                'status' => ParallelRunSchedule::STATUS_QUEUED,
                'workerStates' => [],
                'resultSummary' => [],
                'metadata' => $metadata,
                'scheduledAt' => now(),
            ]);
        });

        return response()->json($this->scheduleResponse($schedule), 201);
    }

    public function show(Request $request, int $idProject, int $parallelRun): JsonResponse
    {
        $schedule = $this->ownedSchedule(
            $this->customerFromRequest($request),
            $idProject,
            $parallelRun
        );

        return response()->json($this->scheduleResponse($schedule));
    }

    public function claimWorker(
        Request $request,
        int $idProject,
        int $parallelRun
    ): JsonResponse {
        $customer = $this->customerFromRequest($request);

        $validated = $request->validate([
            'workerId' => ['required', 'string', 'max:128'],
            'capabilities' => ['sometimes', 'array'],
        ]);
        $this->consumeRunTokenIfPresent(
            $request,
            $customer->id,
            $idProject,
            $parallelRun,
            $validated['workerId']
        );

        $schedule = DB::transaction(function () use (
            $customer,
            $idProject,
            $parallelRun,
            $validated
        ) {
            $schedule = $this->ownedSchedule($customer, $idProject, $parallelRun, true);

            if (in_array($schedule->status, self::TERMINAL_STATUSES, true)) {
                abort(response()->json([
                    'message' => 'Parallel run is already terminal.',
                ], 422));
            }

            if ($schedule->status === ParallelRunSchedule::STATUS_CANCELLING) {
                abort(response()->json([
                    'message' => 'Parallel run is cancelling.',
                ], 409));
            }

            $workers = $schedule->workerStates ?? [];
            $workerId = $validated['workerId'];
            $existing = $workers[$workerId] ?? null;

            if ($existing === null && $schedule->activeWorkers >= $schedule->requestedConcurrency) {
                abort(response()->json([
                    'message' => 'Concurrency limit reached.',
                ], 409));
            }

            $now = now();
            $workers[$workerId] = [
                'workerId' => $workerId,
                'status' => ParallelRunSchedule::WORKER_RUNNING,
                'capabilities' => $validated['capabilities'] ?? ($existing['capabilities'] ?? []),
                'claimedAt' => $existing['claimedAt'] ?? $now->toISOString(),
                'lastHeartbeatAt' => $now->toISOString(),
                'leaseExpiresAt' => $now->copy()->addSeconds(self::DEFAULT_WORKER_LEASE_SECONDS)->toISOString(),
                'updatedAt' => $now->toISOString(),
                'result' => $existing['result'] ?? null,
            ];

            $schedule->workerStates = $workers;
            $schedule->status = ParallelRunSchedule::STATUS_RUNNING;
            $schedule->startedAt ??= $now;
            $this->recalculateWorkers($schedule);
            $schedule->save();

            return $schedule;
        });

        return response()->json($this->scheduleResponse($schedule));
    }

    public function heartbeatWorker(
        Request $request,
        int $idProject,
        int $parallelRun,
        string $workerId
    ): JsonResponse {
        $customer = $this->customerFromRequest($request);
        $validated = $request->validate([
            'leaseSeconds' => ['sometimes', 'integer', 'min:15', 'max:3600'],
        ]);

        $heartbeat = DB::transaction(function () use (
            $customer,
            $idProject,
            $parallelRun,
            $workerId,
            $validated
        ) {
            $schedule = $this->ownedSchedule($customer, $idProject, $parallelRun, true);
            $this->markExpiredWorkerLeases($schedule);

            if (in_array($schedule->status, self::TERMINAL_STATUSES, true)) {
                abort(response()->json([
                    'message' => 'Parallel run is already terminal.',
                ], 422));
            }

            $workers = $schedule->workerStates ?? [];

            if (! array_key_exists($workerId, $workers)) {
                abort(response()->json([
                    'message' => 'Worker has not claimed this run.',
                ], 404));
            }

            if (($workers[$workerId]['status'] ?? null) !== ParallelRunSchedule::WORKER_RUNNING) {
                $schedule->workerStates = $workers;
                $this->recalculateWorkers($schedule);
                $schedule->save();

                return [
                    'schedule' => $schedule,
                    'status' => 409,
                    'message' => 'Worker lease is no longer active.',
                    'workerStatus' => $workers[$workerId]['status'] ?? null,
                ];
            }

            $now = now();
            $workers[$workerId]['lastHeartbeatAt'] = $now->toISOString();
            $workers[$workerId]['leaseExpiresAt'] = $now
                ->copy()
                ->addSeconds((int) ($validated['leaseSeconds'] ?? self::DEFAULT_WORKER_LEASE_SECONDS))
                ->toISOString();
            $workers[$workerId]['updatedAt'] = $now->toISOString();
            $schedule->workerStates = $workers;
            $this->recalculateWorkers($schedule);
            $schedule->save();

            return [
                'schedule' => $schedule,
                'status' => 200,
            ];
        });

        $payload = $this->scheduleResponse($heartbeat['schedule']);
        if (($heartbeat['status'] ?? 200) !== 200) {
            $payload['message'] = $heartbeat['message'];
            $payload['workerStatus'] = $heartbeat['workerStatus'];
        }

        return response()->json($payload, $heartbeat['status'] ?? 200);
    }

    public function issueRunToken(
        Request $request,
        int $idProject,
        int $parallelRun
    ): JsonResponse {
        $customer = $this->customerFromRequest($request);
        $validated = $request->validate([
            'agentId' => ['required', 'string', 'max:128'],
        ]);
        $schedule = $this->ownedSchedule($customer, $idProject, $parallelRun);
        $issued = $this->runTokens->issue($schedule, $validated['agentId']);
        $this->auditEvents->record(
            $request,
            'run_token.issue',
            'parallel_run_schedule',
            (string) $schedule->id,
            afterValues: [
                'agentId' => $issued['runToken']->agentId,
                'tokenId' => $issued['runToken']->tokenId,
                'token' => $issued['token'],
                'expiresAt' => $issued['runToken']->expiresAt->toISOString(),
            ],
            projectId: $schedule->idProject,
        );

        return response()->json([
            'token' => $issued['token'],
            'expiresAt' => $issued['runToken']->expiresAt->toISOString(),
            'agentId' => $issued['runToken']->agentId,
        ], 201);
    }

    public function updateWorker(
        Request $request,
        int $idProject,
        int $parallelRun,
        string $workerId
    ): JsonResponse {
        $customer = $this->customerFromRequest($request);

        $validated = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::in([
                    ParallelRunSchedule::WORKER_RUNNING,
                    ParallelRunSchedule::WORKER_COMPLETED,
                    ParallelRunSchedule::WORKER_FAILED,
                    ParallelRunSchedule::WORKER_CANCELLED,
                    ParallelRunSchedule::WORKER_LOST,
                ]),
            ],
            'result' => ['sometimes', 'array'],
        ]);

        $schedule = DB::transaction(function () use (
            $customer,
            $idProject,
            $parallelRun,
            $workerId,
            $validated
        ) {
            $schedule = $this->ownedSchedule($customer, $idProject, $parallelRun, true);

            if (in_array($schedule->status, self::TERMINAL_STATUSES, true)) {
                abort(response()->json([
                    'message' => 'Parallel run is already terminal.',
                ], 422));
            }

            if (
                $schedule->status === ParallelRunSchedule::STATUS_CANCELLING
                && $validated['status'] === ParallelRunSchedule::WORKER_RUNNING
            ) {
                abort(response()->json([
                    'message' => 'Parallel run is cancelling.',
                ], 409));
            }

            $workers = $schedule->workerStates ?? [];

            if (! array_key_exists($workerId, $workers)) {
                abort(response()->json([
                    'message' => 'Worker has not claimed this run.',
                ], 404));
            }

            $workers[$workerId]['status'] = $validated['status'];
            $workers[$workerId]['updatedAt'] = now()->toISOString();
            $workers[$workerId]['result'] = $validated['result'] ?? $workers[$workerId]['result'] ?? null;
            $schedule->workerStates = $workers;

            $this->recalculateWorkers($schedule);
            $schedule->save();

            return $schedule;
        });

        return response()->json($this->scheduleResponse($schedule));
    }

    public function cancel(Request $request, int $idProject, int $parallelRun): JsonResponse
    {
        $customer = $this->customerFromRequest($request);

        $schedule = DB::transaction(function () use ($customer, $idProject, $parallelRun) {
            $schedule = $this->ownedSchedule($customer, $idProject, $parallelRun, true);

            if (in_array($schedule->status, self::TERMINAL_STATUSES, true)) {
                abort(response()->json([
                    'message' => 'Parallel run is already terminal.',
                ], 422));
            }

            $workers = $schedule->workerStates ?? [];
            foreach ($workers as $workerId => $worker) {
                if (($worker['status'] ?? null) === ParallelRunSchedule::WORKER_RUNNING) {
                    $workers[$workerId]['status'] = ParallelRunSchedule::WORKER_CANCELLED;
                    $workers[$workerId]['updatedAt'] = now()->toISOString();
                }
            }

            $schedule->workerStates = $workers;
            $schedule->status = ParallelRunSchedule::STATUS_CANCELLED;
            $schedule->cancelledAt = now();
            $schedule->completedAt = now();
            $schedule->aggregateStatus = ParallelRunSchedule::RESULT_CANCELLED;
            $this->recalculateWorkers($schedule);
            $schedule->save();

            return $schedule;
        });

        return response()->json($this->scheduleResponse($schedule));
    }

    public function results(Request $request, int $idProject, int $parallelRun): JsonResponse
    {
        $schedule = $this->ownedSchedule(
            $this->customerFromRequest($request),
            $idProject,
            $parallelRun
        );

        return response()->json([
            'id' => $schedule->id,
            'idProject' => $schedule->idProject,
            'testCycleId' => $schedule->testCycleId,
            'status' => $schedule->status,
            'aggregateStatus' => $schedule->aggregateStatus,
            'resultSummary' => $schedule->resultSummary ?? [],
            'workers' => $this->orderedWorkers($schedule),
        ]);
    }

    private function customerFromRequest(Request $request): Costumer
    {
        if ($request->attributes->has('ideliumCustomer')) {
            return $this->ideliumCustomer($request);
        }

        return Costumer::findOrFail(Auth::user()->idCostumer);
    }

    private function consumeRunTokenIfPresent(
        Request $request,
        int $tenantId,
        int $projectId,
        int $parallelRun,
        string $workerId
    ): void {
        $token = $request->header('Idelium-Run-Token');
        if (! is_string($token) || $token === '') {
            return;
        }

        try {
            $runToken = $this->runTokens->consume(
                $token,
                $tenantId,
                $projectId,
                $parallelRun,
                $workerId
            );
            $this->auditEvents->record(
                $request,
                'run_token.consume',
                'parallel_run_schedule',
                (string) $parallelRun,
                afterValues: [
                    'agentId' => $workerId,
                    'tokenId' => $runToken->tokenId,
                    'token' => $token,
                ],
                projectId: $projectId,
            );
        } catch (\Throwable $exception) {
            $this->auditEvents->record(
                $request,
                'run_token.reject',
                'parallel_run_schedule',
                (string) $parallelRun,
                result: \App\Models\AuditEvent::RESULT_FAILURE,
                afterValues: [
                    'agentId' => $workerId,
                    'token' => $token,
                    'reason' => $exception->getMessage(),
                ],
                projectId: $projectId,
            );

            throw $exception;
        }
    }

    private function ownedProject(
        Costumer $customer,
        int $idProject,
        bool $lock = false
    ): Project {
        $query = Project::whereKey($idProject)
            ->where('idCostumer', $customer->id);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function ownedTestCycle(
        Costumer $customer,
        int $idProject,
        int $testCycleId,
        bool $lock = false
    ): TestCycle {
        $query = TestCycle::whereKey($testCycleId)
            ->where('idProject', $idProject)
            ->where('idCostumer', $customer->id);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function ownedSchedule(
        Costumer $customer,
        int $idProject,
        int $parallelRun,
        bool $lock = false
    ): ParallelRunSchedule {
        $this->ownedProject($customer, $idProject);

        $query = ParallelRunSchedule::whereKey($parallelRun)
            ->where('idProject', $idProject)
            ->where('idCostumer', $customer->id);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function recalculateWorkers(ParallelRunSchedule $schedule): void
    {
        $workers = $schedule->workerStates ?? [];
        $this->markExpiredWorkerLeases($schedule);
        $workers = $schedule->workerStates ?? [];
        ksort($workers);

        $active = 0;
        $completed = 0;
        $failed = 0;
        $cancelled = 0;
        $lost = 0;
        $summary = [];

        foreach ($workers as $workerId => $worker) {
            $status = $worker['status'] ?? ParallelRunSchedule::WORKER_RUNNING;
            if ($status === ParallelRunSchedule::WORKER_RUNNING) {
                $active++;
            } elseif ($status === ParallelRunSchedule::WORKER_COMPLETED) {
                $completed++;
            } elseif ($status === ParallelRunSchedule::WORKER_FAILED) {
                $failed++;
            } elseif ($status === ParallelRunSchedule::WORKER_CANCELLED) {
                $cancelled++;
            } elseif ($status === ParallelRunSchedule::WORKER_LOST) {
                $lost++;
            }

            $summary[] = [
                'workerId' => $workerId,
                'status' => $status,
                'result' => $worker['result'] ?? null,
            ];
        }

        $schedule->workerStates = $workers;
        $schedule->activeWorkers = $active;
        $schedule->totalWorkers = count($workers);
        $schedule->completedWorkers = $completed;
        $schedule->failedWorkers = $failed;
        $schedule->cancelledWorkers = $cancelled;
        $schedule->resultSummary = $summary;

        if (count($workers) === 0 || $active > 0) {
            return;
        }

        $schedule->completedAt ??= now();

        if ($failed > 0) {
            $schedule->status = ParallelRunSchedule::STATUS_FAILED;
            $schedule->aggregateStatus = ParallelRunSchedule::RESULT_FAILED;

            return;
        }

        if ($lost > 0) {
            $schedule->status = ParallelRunSchedule::STATUS_LOST;
            $schedule->aggregateStatus = ParallelRunSchedule::RESULT_LOST;

            return;
        }

        if ($cancelled > 0 && $completed === 0) {
            $schedule->status = ParallelRunSchedule::STATUS_CANCELLED;
            $schedule->aggregateStatus = ParallelRunSchedule::RESULT_CANCELLED;

            return;
        }

        if ($cancelled > 0) {
            $schedule->status = ParallelRunSchedule::STATUS_FAILED;
            $schedule->aggregateStatus = ParallelRunSchedule::RESULT_CANCELLED;

            return;
        }

        $schedule->status = ParallelRunSchedule::STATUS_COMPLETED;
        $schedule->aggregateStatus = ParallelRunSchedule::RESULT_PASSED;
    }

    private function scheduleResponse(ParallelRunSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'idProject' => $schedule->idProject,
            'testCycleId' => $schedule->testCycleId,
            'performedTestCycleId' => $schedule->performedTestCycleId,
            'status' => $schedule->status,
            'requestedConcurrency' => $schedule->requestedConcurrency,
            'activeWorkers' => $schedule->activeWorkers,
            'totalWorkers' => $schedule->totalWorkers,
            'completedWorkers' => $schedule->completedWorkers,
            'failedWorkers' => $schedule->failedWorkers,
            'cancelledWorkers' => $schedule->cancelledWorkers,
            'lostWorkers' => $this->countWorkersByStatus(
                $schedule,
                ParallelRunSchedule::WORKER_LOST
            ),
            'aggregateStatus' => $schedule->aggregateStatus,
            'metadata' => $schedule->metadata ?? [],
            'resultSummary' => $schedule->resultSummary ?? [],
            'scheduledAt' => optional($schedule->scheduledAt)->toISOString(),
            'startedAt' => optional($schedule->startedAt)->toISOString(),
            'completedAt' => optional($schedule->completedAt)->toISOString(),
            'cancelledAt' => optional($schedule->cancelledAt)->toISOString(),
        ];
    }

    private function orderedWorkers(ParallelRunSchedule $schedule): array
    {
        $workers = $schedule->workerStates ?? [];
        ksort($workers);

        return array_values($workers);
    }

    private function markExpiredWorkerLeases(ParallelRunSchedule $schedule): void
    {
        $workers = $schedule->workerStates ?? [];
        $changed = false;
        $now = now();

        foreach ($workers as $workerId => $worker) {
            if (($worker['status'] ?? null) !== ParallelRunSchedule::WORKER_RUNNING) {
                continue;
            }

            $leaseExpiresAt = $worker['leaseExpiresAt'] ?? null;
            if (! is_string($leaseExpiresAt) || $leaseExpiresAt === '') {
                continue;
            }

            if ($now->greaterThan(\Carbon\Carbon::parse($leaseExpiresAt))) {
                $workers[$workerId]['status'] = ParallelRunSchedule::WORKER_LOST;
                $workers[$workerId]['lostAt'] = $now->toISOString();
                $workers[$workerId]['updatedAt'] = $now->toISOString();
                $changed = true;
            }
        }

        if ($changed) {
            $schedule->workerStates = $workers;
        }
    }

    private function countWorkersByStatus(ParallelRunSchedule $schedule, string $status): int
    {
        return collect($schedule->workerStates ?? [])
            ->filter(fn (array $worker) => ($worker['status'] ?? null) === $status)
            ->count();
    }
}
