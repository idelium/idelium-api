<?php

namespace Tests\Feature;

use App\Models\Costumer;
use App\Models\ParallelRunSchedule;
use App\Models\Project;
use App\Models\Role;
use App\Models\TestCycle;
use App\Services\RunTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RunTokenTest extends TestCase
{
    use RefreshDatabase;

    private Costumer $customer;

    private Project $project;

    private ParallelRunSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        Role::forceCreate(['id' => 1, 'name' => 'superadmin']);
        Role::forceCreate(['id' => 2, 'name' => 'admin']);
        Role::forceCreate(['id' => 3, 'name' => 'user']);

        $this->customer = Costumer::forceCreate([
            'costumer' => 'Demo customer',
            'description' => 'Demo customer',
            'logo' => json_encode([]),
            'licenseExpiration' => now()->addYear(),
            'apiKey' => 'legacy-api-key',
        ]);
        $this->project = Project::forceCreate([
            'name' => 'Demo project',
            'description' => 'Demo project',
            'idCostumer' => $this->customer->id,
        ]);
        $testCycle = TestCycle::forceCreate([
            'name' => 'Demo cycle',
            'description' => 'Demo cycle',
            'config' => json_encode([]),
            'idProject' => $this->project->id,
            'idCostumer' => $this->customer->id,
        ]);
        $this->schedule = ParallelRunSchedule::forceCreate([
            'idCostumer' => $this->customer->id,
            'idProject' => $this->project->id,
            'testCycleId' => $testCycle->id,
            'idempotencyKey' => 'run-token-test',
            'requestedConcurrency' => 1,
            'status' => ParallelRunSchedule::STATUS_QUEUED,
            'workerStates' => [],
            'resultSummary' => [],
            'metadata' => [],
            'scheduledAt' => now(),
        ]);
    }

    public function test_run_token_is_revealed_once_and_stored_as_hash(): void
    {
        $issued = app(RunTokenService::class)->issue($this->schedule, 'agent-1');
        $token = $issued['token'];
        [, $plainSecret] = explode('.', $token, 2);
        $runToken = $issued['runToken']->fresh();

        $this->assertStringStartsWith('idrt_', $token);
        $this->assertArrayNotHasKey('tokenHash', $runToken->toArray());
        $this->assertTrue(Hash::check($plainSecret, $runToken->tokenHash));
        $this->assertStringNotContainsString($plainSecret, $runToken->tokenHash);
    }

    public function test_runner_claim_consumes_short_lived_token_once(): void
    {
        $issued = app(RunTokenService::class)->issue($this->schedule, 'agent-1');

        $this->withHeader('Idelium-Key', $this->customer->apiKey)
            ->withHeader('Idelium-Run-Token', $issued['token'])
            ->postJson(
                '/api/ideliumcl/projects/'.$this->project->id
                .'/parallel-runs/'.$this->schedule->id.'/claim',
                [
                    'workerId' => 'agent-1',
                    'capabilities' => ['selenium'],
                ]
            )
            ->assertOk()
            ->assertJsonPath('activeWorkers', 1);

        $this->withHeader('Idelium-Key', $this->customer->apiKey)
            ->withHeader('Idelium-Run-Token', $issued['token'])
            ->postJson(
                '/api/ideliumcl/projects/'.$this->project->id
                .'/parallel-runs/'.$this->schedule->id.'/claim',
                [
                    'workerId' => 'agent-1',
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('runToken');
    }

    public function test_wrong_agent_token_is_rejected(): void
    {
        $issued = app(RunTokenService::class)->issue($this->schedule, 'agent-1');

        $this->withHeader('Idelium-Key', $this->customer->apiKey)
            ->withHeader('Idelium-Run-Token', $issued['token'])
            ->postJson(
                '/api/ideliumcl/projects/'.$this->project->id
                .'/parallel-runs/'.$this->schedule->id.'/claim',
                [
                    'workerId' => 'agent-2',
                ]
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('runToken');
    }
}
