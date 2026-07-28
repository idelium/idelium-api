<?php

namespace Tests\Feature;

use App\Models\AssetVersion;
use App\Models\Costumer;
use App\Models\Environment;
use App\Models\ParallelRunSchedule;
use App\Models\Project;
use App\Models\Role;
use App\Models\Step;
use App\Models\Test;
use App\Models\TestCycle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ParallelRunScheduleApiTest extends TestCase
{
    use RefreshDatabase;

    private Costumer $firstCustomer;

    private Costumer $secondCustomer;

    private Project $firstProject;

    private Project $secondProject;

    private TestCycle $firstCycle;

    private TestCycle $secondCycle;

    protected function setUp(): void
    {
        parent::setUp();

        config(['run_tokens.require_for_claim' => false]);

        Role::forceCreate(['id' => 3, 'name' => 'user']);
        $this->firstCustomer = $this->createCustomer('first-api-key', 'First customer');
        $this->secondCustomer = $this->createCustomer('second-api-key', 'Second customer');
        $this->firstProject = $this->createProject($this->firstCustomer, 'FIRST');
        $this->secondProject = $this->createProject($this->secondCustomer, 'SECOND');
        $this->firstCycle = $this->createTestCycle(
            $this->firstCustomer,
            $this->firstProject,
            'First cycle'
        );
        $this->secondCycle = $this->createTestCycle(
            $this->secondCustomer,
            $this->secondProject,
            'Second cycle'
        );
    }

    public function test_sanctum_user_schedules_parallel_run_idempotently_for_own_project(): void
    {
        Sanctum::actingAs($this->createUser($this->firstCustomer));

        $payload = [
            'testCycleId' => $this->firstCycle->id,
            'idempotencyKey' => 'release-2026-07-27',
            'requestedConcurrency' => 2,
            'metadata' => ['source' => 'web'],
        ];

        $first = $this->postJson(
            '/api/admin/projects/'.$this->firstProject->id.'/parallel-runs',
            $payload
        )->assertCreated()
            ->assertJsonPath('status', ParallelRunSchedule::STATUS_QUEUED)
            ->assertJsonPath('requestedConcurrency', 2)
            ->assertJsonMissingPath('idCostumer');

        $second = $this->postJson(
            '/api/admin/projects/'.$this->firstProject->id.'/parallel-runs',
            $payload
        )->assertCreated();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertDatabaseCount('parallel_run_schedules', 1);
    }

    public function test_parallel_run_persists_execution_asset_version_snapshot(): void
    {
        Sanctum::actingAs($this->createUser($this->firstCustomer));

        $test = Test::forceCreate([
            'name' => 'Versioned test',
            'description' => 'Versioned test',
            'config' => json_encode([]),
            'idProject' => $this->firstProject->id,
            'idCostumer' => $this->firstCustomer->id,
        ]);
        $step = Step::forceCreate([
            'name' => 'Versioned step',
            'description' => 'Versioned step',
            'config' => json_encode([]),
            'idProject' => $this->firstProject->id,
            'idCostumer' => $this->firstCustomer->id,
            'order' => 1,
        ]);
        $environment = Environment::forceCreate([
            'code' => 'versioned',
            'description' => 'Versioned environment',
            'config' => json_encode([]),
            'idProject' => $this->firstProject->id,
            'idCostumer' => $this->firstCustomer->id,
        ]);
        $this->firstCycle->config = json_encode([
            'tests' => [$test->id],
            'steps' => [['id' => $step->id]],
            'environments' => [['assetId' => $environment->id]],
        ]);
        $this->firstCycle->save();

        $cycleVersion = $this->createAssetVersion('test_cycle', $this->firstCycle->id, 1);
        $testVersion = $this->createAssetVersion('test', $test->id, 3);
        $stepVersion = $this->createAssetVersion('step', $step->id, 2);
        $environmentVersion = $this->createAssetVersion('environment', $environment->id, 4);

        $this->postJson('/api/admin/projects/'.$this->firstProject->id.'/parallel-runs', [
            'testCycleId' => $this->firstCycle->id,
            'idempotencyKey' => 'versioned-snapshot',
        ])->assertCreated()
            ->assertJsonPath('metadata.executionSnapshot.testCycle.versionId', $cycleVersion->id)
            ->assertJsonPath('metadata.executionSnapshot.tests.0.versionId', $testVersion->id)
            ->assertJsonPath('metadata.executionSnapshot.steps.0.versionId', $stepVersion->id)
            ->assertJsonPath('metadata.executionSnapshot.environments.0.versionId', $environmentVersion->id);

        $schedule = ParallelRunSchedule::where('idempotencyKey', 'versioned-snapshot')->firstOrFail();
        $this->assertSame(
            $cycleVersion->id,
            $schedule->metadata['executionSnapshot']['testCycle']['versionId']
        );
    }

    public function test_parallel_run_normalizes_and_filters_immutable_run_metadata(): void
    {
        Sanctum::actingAs($this->createUser($this->firstCustomer));

        $this->postJson('/api/admin/projects/'.$this->firstProject->id.'/parallel-runs', [
            'testCycleId' => $this->firstCycle->id,
            'idempotencyKey' => 'metadata-contract',
            'metadata' => [
                'build' => '1042',
                'commit' => 'abc123',
                'branch' => 'main',
                'repository' => 'idelium/idelium-api',
                'initiator' => 'ci',
                'pipeline' => 'release',
                'token' => 'must-not-persist',
                'workloadIdentity' => [
                    'provider' => 'github-actions',
                    'issuer' => 'https://token.actions.githubusercontent.com',
                    'subject' => 'repo:idelium/idelium-api:ref:refs/heads/main',
                    'audience' => 'idelium',
                    'authorization' => 'Bearer secret',
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('metadata.run.build', '1042')
            ->assertJsonPath('metadata.run.commit', 'abc123')
            ->assertJsonPath('metadata.run.workloadIdentity.provider', 'github-actions')
            ->assertJsonMissingPath('metadata.token')
            ->assertJsonMissingPath('metadata.run.workloadIdentity.authorization');

        $this->postJson('/api/admin/projects/'.$this->firstProject->id.'/parallel-runs', [
            'testCycleId' => $this->firstCycle->id,
            'idempotencyKey' => 'other-metadata-contract',
            'metadata' => [
                'run' => [
                    'build' => '2048',
                    'commit' => 'def456',
                    'branch' => 'feature',
                    'repository' => 'idelium/idelium-api',
                    'initiator' => 'manual',
                    'pipeline' => 'nightly',
                ],
            ],
        ])->assertCreated();

        $this->getJson('/api/admin/projects/'.$this->firstProject->id.'/parallel-runs?commit=abc123')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.idempotencyKey', 'metadata-contract')
            ->assertJsonPath('0.metadata.run.pipeline', 'release');

        $this->assertDatabaseMissing('parallel_run_schedules', [
            'metadata' => json_encode(['token' => 'must-not-persist']),
        ]);
    }

    public function test_matrix_launch_creates_deterministic_parallel_run_schedules(): void
    {
        Sanctum::actingAs($this->createUser($this->firstCustomer));

        $payload = [
            'testCycleId' => $this->firstCycle->id,
            'idempotencyKey' => 'matrix-release',
            'requestedConcurrency' => 2,
            'matrix' => [
                'platforms' => ['linux'],
                'browsers' => ['chrome', 'firefox'],
                'environments' => ['demo', 'prod'],
            ],
            'metadata' => [
                'pipeline' => 'release',
            ],
        ];

        $first = $this->postJson(
            '/api/admin/projects/'.$this->firstProject->id.'/parallel-runs/matrix',
            $payload
        )->assertCreated()
            ->assertJsonPath('summary.requestedRuns', 4)
            ->assertJsonPath('summary.scheduledRuns', 4)
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.metadata.matrix.index', 0)
            ->assertJsonPath('data.0.metadata.matrix.total', 4)
            ->assertJsonPath('data.0.metadata.matrix.combination.platform', 'linux')
            ->assertJsonPath('data.0.metadata.run.pipeline', 'release')
            ->assertJsonPath('data.0.requestedConcurrency', 2);

        $this->assertStringContainsString(
            '/api/admin/projects/'.$this->firstProject->id.'/parallel-runs/',
            $first->json('data.0.runUrl')
        );
        $this->assertDatabaseCount('parallel_run_schedules', 4);

        $second = $this->postJson(
            '/api/admin/projects/'.$this->firstProject->id.'/parallel-runs/matrix',
            $payload
        )->assertCreated()
            ->assertJsonCount(4, 'data');

        $this->assertSame($first->json('data.0.id'), $second->json('data.0.id'));
        $this->assertDatabaseCount('parallel_run_schedules', 4);
    }

    public function test_matrix_launch_rejects_empty_or_too_large_matrices(): void
    {
        Sanctum::actingAs($this->createUser($this->firstCustomer));

        $this->postJson('/api/admin/projects/'.$this->firstProject->id.'/parallel-runs/matrix', [
            'testCycleId' => $this->firstCycle->id,
            'idempotencyKey' => 'empty-matrix',
            'matrix' => [],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'At least one matrix axis value is required.');

        $this->postJson('/api/admin/projects/'.$this->firstProject->id.'/parallel-runs/matrix', [
            'testCycleId' => $this->firstCycle->id,
            'idempotencyKey' => 'large-matrix',
            'matrix' => [
                'platforms' => range(1, 8),
                'browsers' => range(1, 9),
            ],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Matrix launch exceeds the maximum number of generated runs.')
            ->assertJsonPath('maximumRuns', 64)
            ->assertJsonPath('requestedRuns', 72);

        $this->assertDatabaseCount('parallel_run_schedules', 0);
    }

    public function test_sanctum_user_cannot_schedule_for_another_customer_project_or_cycle(): void
    {
        Sanctum::actingAs($this->createUser($this->firstCustomer));

        $this->postJson('/api/admin/projects/'.$this->secondProject->id.'/parallel-runs', [
            'testCycleId' => $this->secondCycle->id,
            'idempotencyKey' => 'cross-tenant-project',
        ])->assertNotFound();

        $this->postJson('/api/admin/projects/'.$this->firstProject->id.'/parallel-runs', [
            'testCycleId' => $this->secondCycle->id,
            'idempotencyKey' => 'cross-tenant-cycle',
        ])->assertNotFound();

        $this->assertDatabaseCount('parallel_run_schedules', 0);
    }

    public function test_sanctum_user_lists_only_own_project_parallel_runs(): void
    {
        Sanctum::actingAs($this->createUser($this->firstCustomer));

        $ownSchedule = $this->createSchedule($this->firstCustomer, $this->firstProject, $this->firstCycle);
        $this->createSchedule($this->secondCustomer, $this->secondProject, $this->secondCycle);

        $this->getJson('/api/admin/projects/'.$this->firstProject->id.'/parallel-runs')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $ownSchedule->id)
            ->assertJsonMissingPath('0.idCostumer');

        $this->getJson('/api/admin/projects/'.$this->secondProject->id.'/parallel-runs')
            ->assertNotFound();
    }

    public function test_cli_key_cannot_read_or_mutate_another_customer_parallel_run(): void
    {
        $schedule = $this->createSchedule($this->secondCustomer, $this->secondProject, $this->secondCycle);

        $this->withHeader('Idelium-Key', $this->firstCustomer->apiKey)
            ->getJson(
                '/api/ideliumcl/projects/'.$this->secondProject->id.'/parallel-runs/'.$schedule->id
            )->assertNotFound();

        $this->withHeader('Idelium-Key', $this->firstCustomer->apiKey)
            ->postJson(
                '/api/ideliumcl/projects/'.$this->secondProject->id.'/parallel-runs/'.$schedule->id.'/claim',
                ['workerId' => 'worker-a']
            )->assertNotFound();
    }

    public function test_parallel_run_enforces_concurrency_and_terminal_state_boundaries(): void
    {
        $schedule = $this->createSchedule(
            $this->firstCustomer,
            $this->firstProject,
            $this->firstCycle,
            1
        );

        $this->withHeader('Idelium-Key', $this->firstCustomer->apiKey)
            ->postJson(
                '/api/ideliumcl/projects/'.$this->firstProject->id.'/parallel-runs/'.$schedule->id.'/claim',
                ['workerId' => 'worker-a']
            )->assertOk()
            ->assertJsonPath('status', ParallelRunSchedule::STATUS_RUNNING)
            ->assertJsonPath('activeWorkers', 1);

        $this->withHeader('Idelium-Key', $this->firstCustomer->apiKey)
            ->postJson(
                '/api/ideliumcl/projects/'.$this->firstProject->id.'/parallel-runs/'.$schedule->id.'/claim',
                ['workerId' => 'worker-b']
            )->assertStatus(409)
            ->assertJsonPath('message', 'Concurrency limit reached.');

        $this->withHeader('Idelium-Key', $this->firstCustomer->apiKey)
            ->putJson(
                '/api/ideliumcl/projects/'.$this->firstProject->id.'/parallel-runs/'.$schedule->id.'/workers/worker-a',
                [
                    'status' => ParallelRunSchedule::WORKER_COMPLETED,
                    'result' => ['durationMs' => 42],
                ]
            )->assertOk()
            ->assertJsonPath('status', ParallelRunSchedule::STATUS_COMPLETED)
            ->assertJsonPath('aggregateStatus', ParallelRunSchedule::RESULT_PASSED);

        $this->withHeader('Idelium-Key', $this->firstCustomer->apiKey)
            ->postJson(
                '/api/ideliumcl/projects/'.$this->firstProject->id.'/parallel-runs/'.$schedule->id.'/claim',
                ['workerId' => 'worker-c']
            )->assertUnprocessable()
            ->assertJsonPath('message', 'Parallel run is already terminal.');
    }

    public function test_result_aggregation_is_deterministic_and_failure_wins(): void
    {
        $schedule = $this->createSchedule(
            $this->firstCustomer,
            $this->firstProject,
            $this->firstCycle,
            2
        );

        foreach (['worker-b', 'worker-a'] as $workerId) {
            $this->withHeader('Idelium-Key', $this->firstCustomer->apiKey)
                ->postJson(
                    '/api/ideliumcl/projects/'.$this->firstProject->id.'/parallel-runs/'.$schedule->id.'/claim',
                    ['workerId' => $workerId]
                )->assertOk();
        }

        $this->withHeader('Idelium-Key', $this->firstCustomer->apiKey)
            ->putJson(
                '/api/ideliumcl/projects/'.$this->firstProject->id.'/parallel-runs/'.$schedule->id.'/workers/worker-b',
                [
                    'status' => ParallelRunSchedule::WORKER_COMPLETED,
                    'result' => ['tests' => 3],
                ]
            )->assertOk();

        $this->withHeader('Idelium-Key', $this->firstCustomer->apiKey)
            ->putJson(
                '/api/ideliumcl/projects/'.$this->firstProject->id.'/parallel-runs/'.$schedule->id.'/workers/worker-a',
                [
                    'status' => ParallelRunSchedule::WORKER_FAILED,
                    'result' => ['tests' => 1, 'failed' => 1],
                ]
            )->assertOk()
            ->assertJsonPath('status', ParallelRunSchedule::STATUS_FAILED)
            ->assertJsonPath('aggregateStatus', ParallelRunSchedule::RESULT_FAILED);

        $this->withHeader('Idelium-Key', $this->firstCustomer->apiKey)
            ->getJson(
                '/api/ideliumcl/projects/'.$this->firstProject->id.'/parallel-runs/'.$schedule->id.'/results'
            )->assertOk()
            ->assertJsonPath('resultSummary.0.workerId', 'worker-a')
            ->assertJsonPath('resultSummary.1.workerId', 'worker-b')
            ->assertJsonPath('workers.0.workerId', 'worker-a')
            ->assertJsonPath('workers.1.workerId', 'worker-b')
            ->assertJsonMissingPath('idCostumer');
    }

    public function test_parallel_run_can_be_cancelled_by_tenant_owner(): void
    {
        $schedule = $this->createSchedule($this->firstCustomer, $this->firstProject, $this->firstCycle);

        $this->withHeader('Idelium-Key', $this->firstCustomer->apiKey)
            ->postJson(
                '/api/ideliumcl/projects/'.$this->firstProject->id.'/parallel-runs/'.$schedule->id.'/claim',
                ['workerId' => 'worker-a']
            )->assertOk();

        $this->withHeader('Idelium-Key', $this->firstCustomer->apiKey)
            ->postJson(
                '/api/ideliumcl/projects/'.$this->firstProject->id.'/parallel-runs/'.$schedule->id.'/cancel'
            )->assertOk()
            ->assertJsonPath('status', ParallelRunSchedule::STATUS_CANCELLED)
            ->assertJsonPath('aggregateStatus', ParallelRunSchedule::RESULT_CANCELLED)
            ->assertJsonPath('cancelledWorkers', 1);
    }

    public function test_worker_heartbeat_renews_finite_lease(): void
    {
        $schedule = $this->createSchedule($this->firstCustomer, $this->firstProject, $this->firstCycle);

        $this->withHeader('Idelium-Key', $this->firstCustomer->apiKey)
            ->postJson(
                '/api/ideliumcl/projects/'.$this->firstProject->id.'/parallel-runs/'.$schedule->id.'/claim',
                ['workerId' => 'worker-a']
            )->assertOk();

        $this->withHeader('Idelium-Key', $this->firstCustomer->apiKey)
            ->postJson(
                '/api/ideliumcl/projects/'.$this->firstProject->id.'/parallel-runs/'.$schedule->id
                .'/workers/worker-a/heartbeat',
                ['leaseSeconds' => 300]
            )->assertOk()
            ->assertJsonPath('status', ParallelRunSchedule::STATUS_RUNNING)
            ->assertJsonPath('activeWorkers', 1)
            ->assertJsonPath('lostWorkers', 0);

        $schedule->refresh();
        $worker = $schedule->workerStates['worker-a'];
        $this->assertSame(ParallelRunSchedule::WORKER_RUNNING, $worker['status']);
        $this->assertArrayHasKey('lastHeartbeatAt', $worker);
        $this->assertArrayHasKey('leaseExpiresAt', $worker);
    }

    public function test_expired_worker_lease_is_marked_lost(): void
    {
        $schedule = $this->createSchedule($this->firstCustomer, $this->firstProject, $this->firstCycle);
        $schedule->workerStates = [
            'worker-a' => [
                'workerId' => 'worker-a',
                'status' => ParallelRunSchedule::WORKER_RUNNING,
                'claimedAt' => now()->subMinutes(10)->toISOString(),
                'lastHeartbeatAt' => now()->subMinutes(10)->toISOString(),
                'leaseExpiresAt' => now()->subMinutes(5)->toISOString(),
                'updatedAt' => now()->subMinutes(10)->toISOString(),
                'result' => null,
            ],
        ];
        $schedule->status = ParallelRunSchedule::STATUS_RUNNING;
        $schedule->activeWorkers = 1;
        $schedule->totalWorkers = 1;
        $schedule->save();

        $this->withHeader('Idelium-Key', $this->firstCustomer->apiKey)
            ->postJson(
                '/api/ideliumcl/projects/'.$this->firstProject->id.'/parallel-runs/'.$schedule->id
                .'/workers/worker-a/heartbeat',
                ['leaseSeconds' => 120]
            )->assertStatus(409)
            ->assertJsonPath('message', 'Worker lease is no longer active.')
            ->assertJsonPath('workerStatus', ParallelRunSchedule::WORKER_LOST)
            ->assertJsonPath('status', ParallelRunSchedule::STATUS_LOST)
            ->assertJsonPath('aggregateStatus', ParallelRunSchedule::RESULT_LOST)
            ->assertJsonPath('activeWorkers', 0)
            ->assertJsonPath('lostWorkers', 1);

        $schedule->refresh();
        $this->assertSame(
            ParallelRunSchedule::WORKER_LOST,
            $schedule->workerStates['worker-a']['status']
        );
        $this->assertSame(ParallelRunSchedule::STATUS_LOST, $schedule->status);
    }

    public function test_cli_key_cannot_heartbeat_foreign_parallel_run(): void
    {
        $schedule = $this->createSchedule($this->secondCustomer, $this->secondProject, $this->secondCycle);

        $this->withHeader('Idelium-Key', $this->firstCustomer->apiKey)
            ->postJson(
                '/api/ideliumcl/projects/'.$this->secondProject->id.'/parallel-runs/'.$schedule->id
                .'/workers/worker-a/heartbeat',
                ['leaseSeconds' => 120]
            )->assertNotFound();
    }

    private function createCustomer(string $apiKey, string $name): Costumer
    {
        return Costumer::forceCreate([
            'costumer' => $name,
            'description' => $name,
            'logo' => json_encode([]),
            'licenseExpiration' => now()->addYear(),
            'apiKey' => $apiKey,
        ]);
    }

    private function createUser(Costumer $customer): User
    {
        return User::forceCreate([
            'name' => 'Test user',
            'role' => 3,
            'email' => 'parallel-'.$customer->id.'@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $customer->id,
        ]);
    }

    private function createProject(Costumer $customer, string $name): Project
    {
        return Project::forceCreate([
            'name' => $name,
            'description' => $name,
            'idCostumer' => $customer->id,
        ]);
    }

    private function createTestCycle(
        Costumer $customer,
        Project $project,
        string $name
    ): TestCycle {
        return TestCycle::forceCreate([
            'name' => $name,
            'description' => $name,
            'config' => json_encode([]),
            'idProject' => $project->id,
            'idCostumer' => $customer->id,
        ]);
    }

    private function createSchedule(
        Costumer $customer,
        Project $project,
        TestCycle $testCycle,
        int $concurrency = 2
    ): ParallelRunSchedule {
        return ParallelRunSchedule::forceCreate([
            'idProject' => $project->id,
            'testCycleId' => $testCycle->id,
            'idCostumer' => $customer->id,
            'idempotencyKey' => 'manual-'.$customer->id.'-'.$testCycle->id,
            'status' => ParallelRunSchedule::STATUS_QUEUED,
            'requestedConcurrency' => $concurrency,
            'workerStates' => [],
            'resultSummary' => [],
            'metadata' => [],
            'scheduledAt' => now(),
        ]);
    }

    private function createAssetVersion(string $type, int $assetId, int $version): AssetVersion
    {
        return AssetVersion::forceCreate([
            'idCostumer' => $this->firstCustomer->id,
            'idProject' => $this->firstProject->id,
            'assetType' => $type,
            'assetId' => $assetId,
            'version' => $version,
            'actorUserId' => null,
            'reason' => 'asset.updated',
            'snapshot' => [
                'id' => $assetId,
                'assetType' => $type,
            ],
        ]);
    }
}
