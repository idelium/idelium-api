<?php

namespace App\Http\Controllers;

use App\Models\AgentRegistration;
use App\Models\AuditEvent;
use App\Models\Costumer;
use App\Models\ParallelRunSchedule;
use App\Models\Project;
use App\Models\RunToken;
use App\Models\TestCycle;
use App\Services\AssetVersionService;
use App\Services\AuditEventService;
use App\Services\RunMetadataService;
use App\Services\RunTokenService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ParallelRunScheduleController extends Controller
{
    private const MAX_CONCURRENCY = 32;

    private const MAX_MATRIX_RUNS = 64;

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
        private readonly RunMetadataService $runMetadata,
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
            ->filter(fn (ParallelRunSchedule $schedule) => $this->runMetadata->matchesFilters(
                $schedule->metadata ?? [],
                $this->runMetadataFilters($request)
            ))
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
            $metadata = $this->runMetadata->normalize($validated['metadata'] ?? []);
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

    public function storeMatrix(Request $request, int $idProject): JsonResponse
    {
        $customer = $this->customerFromRequest($request);

        $validated = $request->validate([
            'testCycleId' => ['required', 'integer'],
            'idempotencyKey' => ['required', 'string', 'max:96'],
            'requestedConcurrency' => [
                'sometimes',
                'integer',
                'min:1',
                'max:'.self::MAX_CONCURRENCY,
            ],
            'metadata' => ['sometimes', 'array'],
            'matrix' => ['present', 'array'],
            'matrix.platforms' => ['sometimes', 'array', 'max:16'],
            'matrix.browsers' => ['sometimes', 'array', 'max:16'],
            'matrix.devices' => ['sometimes', 'array', 'max:16'],
            'matrix.environments' => ['sometimes', 'array', 'max:16'],
        ]);
        $matrix = $request->input('matrix', []);
        $combinations = $this->matrixCombinations(is_array($matrix) ? $matrix : []);

        if ($combinations === []) {
            abort(response()->json([
                'message' => 'At least one matrix axis value is required.',
            ], 422));
        }

        if (count($combinations) > self::MAX_MATRIX_RUNS) {
            abort(response()->json([
                'message' => 'Matrix launch exceeds the maximum number of generated runs.',
                'maximumRuns' => self::MAX_MATRIX_RUNS,
                'requestedRuns' => count($combinations),
            ], 422));
        }

        $schedules = DB::transaction(function () use (
            $customer,
            $idProject,
            $validated,
            $combinations
        ) {
            $this->ownedProject($customer, $idProject, true);
            $testCycle = $this->ownedTestCycle(
                $customer,
                $idProject,
                (int) $validated['testCycleId'],
                true
            );
            $totalCombinations = count($combinations);

            return collect($combinations)
                ->map(function (array $combination, int $index) use (
                    $customer,
                    $idProject,
                    $validated,
                    $testCycle,
                    $totalCombinations
                ) {
                    $metadata = $this->runMetadata->normalize($validated['metadata'] ?? []);
                    $metadata['matrix'] = [
                        'index' => $index,
                        'total' => $totalCombinations,
                        'combination' => $combination,
                    ];
                    $metadata['executionSnapshot'] = $this->assetVersions
                        ->executionSnapshotForTestCycle($testCycle);

                    return ParallelRunSchedule::firstOrCreate([
                        'idCostumer' => $customer->id,
                        'idProject' => $idProject,
                        'idempotencyKey' => $this->matrixIdempotencyKey(
                            $validated['idempotencyKey'],
                            $combination
                        ),
                    ], [
                        'testCycleId' => (int) $validated['testCycleId'],
                        'requestedConcurrency' => (int) ($validated['requestedConcurrency'] ?? 1),
                        'status' => ParallelRunSchedule::STATUS_QUEUED,
                        'workerStates' => [],
                        'resultSummary' => [],
                        'metadata' => $metadata,
                        'scheduledAt' => now(),
                    ]);
                })
                ->values();
        });

        return response()->json([
            'data' => $schedules
                ->map(fn (ParallelRunSchedule $schedule) => $this->scheduleResponse($schedule))
                ->values(),
            'summary' => [
                'requestedRuns' => count($combinations),
                'scheduledRuns' => $schedules->count(),
            ],
        ], 201);
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
        $this->consumeRunToken(
            $request,
            $customer->id,
            $idProject,
            $parallelRun,
            $validated['workerId']
        );

        $claim = $this->claimWorkerForCustomer(
            $request,
            $customer,
            $idProject,
            $parallelRun,
            $validated,
            false
        );

        return response()->json($claim);
    }

    public function claimWorkerWithRunToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idProject' => ['required', 'integer'],
            'parallelRun' => ['required', 'integer'],
            'workerId' => ['required', 'string', 'max:128'],
            'capabilities' => ['sometimes', 'array'],
        ]);
        $runToken = $this->consumeRunTokenForProjectRun(
            $request,
            (int) $validated['idProject'],
            (int) $validated['parallelRun'],
            $validated['workerId']
        );
        $customer = Costumer::findOrFail($runToken->idCostumer);

        $claim = $this->claimWorkerForCustomer(
            $request,
            $customer,
            (int) $validated['idProject'],
            (int) $validated['parallelRun'],
            $validated,
            true
        );

        return response()->json($claim);
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

    public function heartbeatWorkerWithToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idProject' => ['required', 'integer'],
            'parallelRun' => ['required', 'integer'],
            'workerId' => ['required', 'string', 'max:128'],
            'leaseSeconds' => ['sometimes', 'integer', 'min:15', 'max:3600'],
        ]);

        return $this->heartbeatWorkerForSchedule(
            $request,
            $this->workerTokenSchedule(
                (int) $validated['idProject'],
                (int) $validated['parallelRun'],
                $validated['workerId'],
                true
            ),
            $validated['workerId'],
            $validated
        );
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

    public function revokeRunToken(
        Request $request,
        int $idProject,
        int $parallelRun,
        string $tokenId
    ): JsonResponse {
        $customer = $this->customerFromRequest($request);
        $schedule = $this->ownedSchedule($customer, $idProject, $parallelRun);
        $runToken = RunToken::query()
            ->where('idCostumer', $customer->id)
            ->where('idProject', $idProject)
            ->where('parallelRunScheduleId', $schedule->id)
            ->where('tokenId', $tokenId)
            ->firstOrFail();

        $before = [
            'tokenId' => $runToken->tokenId,
            'revokedAt' => optional($runToken->revokedAt)->toISOString(),
        ];
        $runToken = $this->runTokens->revoke($runToken);
        $this->auditEvents->record(
            $request,
            'run_token.revoke',
            'parallel_run_schedule',
            (string) $schedule->id,
            beforeValues: $before,
            afterValues: [
                'agentId' => $runToken->agentId,
                'tokenId' => $runToken->tokenId,
                'revokedAt' => $runToken->revokedAt->toISOString(),
            ],
            projectId: $schedule->idProject,
        );

        return response()->json([
            'tokenId' => $runToken->tokenId,
            'revokedAt' => $runToken->revokedAt->toISOString(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function claimWorkerForCustomer(
        Request $request,
        Costumer $customer,
        int $idProject,
        int $parallelRun,
        array $validated,
        bool $issueWorkerToken
    ): array {
        $workerToken = $issueWorkerToken ? Str::random(64) : null;
        $schedule = DB::transaction(function () use (
            $request,
            $customer,
            $idProject,
            $parallelRun,
            $validated,
            $workerToken
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
            $this->assertAgentCanClaim($customer->id, $workerId, $request);

            if ($existing === null && $schedule->activeWorkers >= $schedule->requestedConcurrency) {
                abort(response()->json([
                    'message' => 'Concurrency limit reached.',
                ], 409));
            }

            $now = now();
            $leaseExpiresAt = $now->copy()->addSeconds(self::DEFAULT_WORKER_LEASE_SECONDS);
            $workers[$workerId] = [
                'workerId' => $workerId,
                'status' => ParallelRunSchedule::WORKER_RUNNING,
                'capabilities' => $validated['capabilities'] ?? ($existing['capabilities'] ?? []),
                'claimedAt' => $existing['claimedAt'] ?? $now->toISOString(),
                'lastHeartbeatAt' => $now->toISOString(),
                'leaseExpiresAt' => $leaseExpiresAt->toISOString(),
                'updatedAt' => $now->toISOString(),
                'result' => $existing['result'] ?? null,
            ];

            if ($workerToken !== null) {
                $workers[$workerId]['workerTokenHash'] = Hash::make($workerToken);
                $workers[$workerId]['workerTokenExpiresAt'] = $leaseExpiresAt->toISOString();
            } elseif (isset($existing['workerTokenHash'])) {
                $workers[$workerId]['workerTokenHash'] = $existing['workerTokenHash'];
                $workers[$workerId]['workerTokenExpiresAt'] = $existing['workerTokenExpiresAt'] ?? null;
            }

            $schedule->workerStates = $workers;
            $schedule->status = ParallelRunSchedule::STATUS_RUNNING;
            $schedule->startedAt ??= $now;
            $this->recalculateWorkers($schedule);
            $schedule->save();

            return $schedule;
        });

        $response = $this->scheduleResponse($schedule);
        if ($workerToken !== null) {
            $response['workerToken'] = $workerToken;
            $response['workerTokenExpiresAt'] = $this->workerState($schedule, $validated['workerId'])['workerTokenExpiresAt'] ?? null;
        }

        return $response;
    }

    private function consumeRunTokenForProjectRun(
        Request $request,
        int $idProject,
        int $parallelRun,
        string $workerId
    ): RunToken {
        $token = $request->header('Idelium-Run-Token');
        if (! is_string($token) || $token === '') {
            abort(response()->json([
                'message' => 'A short-lived run token is required to claim a worker slot.',
            ], 401));
        }

        try {
            $runToken = $this->runTokens->consumeForProjectRun($token, $idProject, $parallelRun, $workerId);
            $this->auditRunTokenEvent($request, $runToken, 'run_token.consume', AuditEvent::RESULT_SUCCESS, [
                'agentId' => $workerId,
                'tokenId' => '[REDACTED]',
                'token' => '[REDACTED]',
            ]);

            return $runToken;
        } catch (\Throwable $exception) {
            $this->auditRunTokenRejection($request, $idProject, $parallelRun, $workerId, $exception->getMessage());

            throw $exception;
        }
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

    /**
     * @param  array<string, mixed>  $validated
     */
    private function heartbeatWorkerForSchedule(
        Request $request,
        ParallelRunSchedule $schedule,
        string $workerId,
        array $validated
    ): JsonResponse {
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
            $payload = $this->scheduleResponse($schedule);
            $payload['message'] = 'Worker lease is no longer active.';
            $payload['workerStatus'] = $workers[$workerId]['status'] ?? null;

            return response()->json($payload, 409);
        }

        $now = now();
        $leaseExpiresAt = $now
            ->copy()
            ->addSeconds((int) ($validated['leaseSeconds'] ?? self::DEFAULT_WORKER_LEASE_SECONDS));
        $workers[$workerId]['lastHeartbeatAt'] = $now->toISOString();
        $workers[$workerId]['leaseExpiresAt'] = $leaseExpiresAt->toISOString();
        $workers[$workerId]['workerTokenExpiresAt'] = $leaseExpiresAt->toISOString();
        $workers[$workerId]['updatedAt'] = $now->toISOString();
        $schedule->workerStates = $workers;
        $this->recalculateWorkers($schedule);
        $schedule->save();

        return response()->json($this->scheduleResponse($schedule));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function updateWorkerForSchedule(
        ParallelRunSchedule $schedule,
        string $workerId,
        array $validated
    ): JsonResponse {
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

        return response()->json($this->scheduleResponse($schedule));
    }

    public function updateWorkerWithToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'idProject' => ['required', 'integer'],
            'parallelRun' => ['required', 'integer'],
            'workerId' => ['required', 'string', 'max:128'],
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

        return $this->updateWorkerForSchedule(
            $this->workerTokenSchedule(
                (int) $validated['idProject'],
                (int) $validated['parallelRun'],
                $validated['workerId'],
                true
            ),
            $validated['workerId'],
            $validated
        );
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

    /**
     * @return array<string, string|null>
     */
    private function runMetadataFilters(Request $request): array
    {
        $filters = [];

        foreach ($this->runMetadata->filterFields() as $field) {
            $value = $request->query($field);
            $filters[$field] = is_scalar($value) && $value !== ''
                ? (string) $value
                : null;
        }

        return $filters;
    }

    private function consumeRunToken(
        Request $request,
        int $tenantId,
        int $projectId,
        int $parallelRun,
        string $workerId
    ): void {
        $token = $request->header('Idelium-Run-Token');
        if (! is_string($token) || $token === '') {
            if (! (bool) config('run_tokens.require_for_claim', true)) {
                return;
            }

            abort(response()->json([
                'message' => 'A short-lived run token is required to claim a worker slot.',
            ], 401));
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
                result: AuditEvent::RESULT_FAILURE,
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

    private function assertAgentCanClaim(int $tenantId, string $workerId, Request $request): void
    {
        $agent = AgentRegistration::query()
            ->where('idCostumer', $tenantId)
            ->where('agentId', $workerId)
            ->first();

        if ($agent === null) {
            return;
        }

        $this->assertAgentIdentityProof($agent, $request);

        if (
            $agent->status !== AgentRegistration::STATUS_APPROVED
            || $agent->health === AgentRegistration::HEALTH_UNHEALTHY
        ) {
            abort(response()->json([
                'message' => 'Agent is not approved and healthy for new run ownership.',
                'agentStatus' => $agent->status,
                'agentHealth' => $agent->health,
            ], 409));
        }
    }

    private function assertAgentIdentityProof(AgentRegistration $agent, Request $request): void
    {
        $expected = $agent->identityProof['certificateSha256'] ?? null;
        if ($expected === null) {
            return;
        }

        $presented = $request->header('Idelium-Agent-Cert-Sha256');
        if (! is_string($presented) || ! hash_equals(strtolower($expected), strtolower($presented))) {
            abort(response()->json([
                'message' => 'Agent identity proof is invalid for this run ownership request.',
            ], 401));
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

    private function workerTokenSchedule(
        int $idProject,
        int $parallelRun,
        string $workerId,
        bool $lock = false
    ): ParallelRunSchedule {
        $query = ParallelRunSchedule::whereKey($parallelRun)
            ->where('idProject', $idProject);

        if ($lock) {
            $query->lockForUpdate();
        }

        $schedule = $query->firstOrFail();
        $worker = $this->workerState($schedule, $workerId);
        $token = request()->header('Idelium-Worker-Token');

        if (! is_string($token)
            || $token === ''
            || ! isset($worker['workerTokenHash'])
            || ! Hash::check($token, $worker['workerTokenHash'])
            || ! isset($worker['workerTokenExpiresAt'])
            || now()->gte($worker['workerTokenExpiresAt'])) {
            abort(response()->json([
                'message' => 'Worker token is invalid, expired, or not bound to this worker.',
            ], 401));
        }

        return $schedule;
    }

    /**
     * @return array<string, mixed>
     */
    private function workerState(ParallelRunSchedule $schedule, string $workerId): array
    {
        $workers = $schedule->workerStates ?? [];
        $worker = $workers[$workerId] ?? null;
        if (! is_array($worker)) {
            abort(response()->json([
                'message' => 'Worker has not claimed this run.',
            ], 404));
        }

        return $worker;
    }

    /**
     * @param  array<string, mixed>  $afterValues
     */
    private function auditRunTokenEvent(
        Request $request,
        RunToken $runToken,
        string $action,
        string $result,
        array $afterValues
    ): void {
        AuditEvent::create([
            'actorUserId' => null,
            'actorTenantId' => $runToken->idCostumer,
            'activeTenantId' => $runToken->idCostumer,
            'idProject' => $runToken->idProject,
            'action' => $action,
            'targetType' => 'parallel_run_schedule',
            'targetId' => (string) $runToken->parallelRunScheduleId,
            'beforeValues' => null,
            'afterValues' => $afterValues,
            'result' => $result,
            'sourceIp' => $request->ip(),
            'correlationId' => (string) Str::uuid(),
            'metadata' => null,
        ]);
    }

    private function auditRunTokenRejection(
        Request $request,
        int $idProject,
        int $parallelRun,
        string $workerId,
        string $reason
    ): void {
        $schedule = ParallelRunSchedule::query()
            ->whereKey($parallelRun)
            ->where('idProject', $idProject)
            ->first();

        if (! $schedule instanceof ParallelRunSchedule) {
            return;
        }

        AuditEvent::create([
            'actorUserId' => null,
            'actorTenantId' => $schedule->idCostumer,
            'activeTenantId' => $schedule->idCostumer,
            'idProject' => $schedule->idProject,
            'action' => 'run_token.reject',
            'targetType' => 'parallel_run_schedule',
            'targetId' => (string) $schedule->id,
            'beforeValues' => null,
            'afterValues' => [
                'agentId' => $workerId,
                'token' => '[REDACTED]',
                'reason' => $reason,
            ],
            'result' => AuditEvent::RESULT_FAILURE,
            'sourceIp' => $request->ip(),
            'correlationId' => (string) Str::uuid(),
            'metadata' => null,
        ]);
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
            'runUrl' => '/api/admin/projects/'.$schedule->idProject.'/parallel-runs/'.$schedule->id,
            'idProject' => $schedule->idProject,
            'testCycleId' => $schedule->testCycleId,
            'performedTestCycleId' => $schedule->performedTestCycleId,
            'idempotencyKey' => $schedule->idempotencyKey,
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

        return collect($workers)
            ->map(function (array $worker): array {
                unset($worker['workerTokenHash'], $worker['workerTokenExpiresAt']);

                return $worker;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $matrix
     * @return array<int, array<string, string>>
     */
    private function matrixCombinations(array $matrix): array
    {
        $axes = [];
        foreach ([
            'platforms' => 'platform',
            'browsers' => 'browser',
            'devices' => 'device',
            'environments' => 'environment',
        ] as $inputKey => $outputKey) {
            $values = collect($matrix[$inputKey] ?? [])
                ->filter(fn ($value) => is_scalar($value) && (string) $value !== '')
                ->map(fn ($value) => (string) $value)
                ->unique()
                ->values()
                ->all();

            if ($values !== []) {
                $axes[$outputKey] = $values;
            }
        }

        if ($axes === []) {
            return [];
        }

        $combinations = [[]];
        foreach ($axes as $axis => $values) {
            $next = [];
            foreach ($combinations as $combination) {
                foreach ($values as $value) {
                    $next[] = array_merge($combination, [$axis => $value]);
                }
            }
            $combinations = $next;
        }

        return $combinations;
    }

    /**
     * @param  array<string, string>  $combination
     */
    private function matrixIdempotencyKey(string $baseKey, array $combination): string
    {
        ksort($combination);

        return $baseKey.'-'.substr(hash('sha256', json_encode($combination)), 0, 16);
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

            if ($now->greaterThan(Carbon::parse($leaseExpiresAt))) {
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
