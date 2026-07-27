<?php

namespace Tests\Feature;

use App\Models\Costumer;
use App\Models\ParallelRunSchedule;
use App\Models\Project;
use App\Models\Role;
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
}
