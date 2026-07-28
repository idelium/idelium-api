<?php

namespace Tests\Feature;

use App\Models\AgentRegistration;
use App\Models\AuditEvent;
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

class AgentRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Costumer $customer;

    private Costumer $otherCustomer;

    private Project $project;

    private TestCycle $testCycle;

    protected function setUp(): void
    {
        parent::setUp();

        config(['run_tokens.require_for_claim' => false]);

        Role::forceCreate(['id' => 2, 'name' => 'admin']);
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        $this->customer = $this->customer('agent-api-key', 'Agent customer');
        $this->otherCustomer = $this->customer('other-agent-api-key', 'Other customer');
        $this->project = Project::forceCreate([
            'name' => 'Agent project',
            'description' => 'Agent project',
            'idCostumer' => $this->customer->id,
        ]);
        $this->testCycle = TestCycle::forceCreate([
            'name' => 'Agent cycle',
            'description' => 'Agent cycle',
            'config' => json_encode([]),
            'idProject' => $this->project->id,
            'idCostumer' => $this->customer->id,
        ]);
    }

    public function test_cli_agent_registration_creates_pending_inventory_record(): void
    {
        $this->withHeader('Idelium-Key', $this->customer->apiKey)
            ->postJson('/api/ideliumcl/agents/register', [
                'agentId' => 'runner-01',
                'version' => '1.0.14',
                'runtimes' => ['selenium', 'postman'],
                'capabilities' => ['browsers' => ['chrome']],
                'maxConcurrency' => 2,
                'health' => AgentRegistration::HEALTH_HEALTHY,
            ])->assertCreated()
            ->assertJsonPath('data.agentId', 'runner-01')
            ->assertJsonPath('data.status', AgentRegistration::STATUS_PENDING)
            ->assertJsonPath('data.version', '1.0.14')
            ->assertJsonPath('data.runtimes.0', 'selenium')
            ->assertJsonMissingPath('data.idCostumer');

        $this->assertDatabaseHas('agent_registrations', [
            'idCostumer' => $this->customer->id,
            'agentId' => 'runner-01',
            'status' => AgentRegistration::STATUS_PENDING,
        ]);
        $this->assertSame(1, AuditEvent::where('action', 'agent.register')->count());
    }

    public function test_registered_pending_agent_cannot_claim_until_approved(): void
    {
        $schedule = $this->schedule();
        $this->withHeader('Idelium-Key', $this->customer->apiKey)
            ->postJson('/api/ideliumcl/agents/register', [
                'agentId' => 'runner-01',
                'health' => AgentRegistration::HEALTH_HEALTHY,
            ])->assertCreated();

        $this->withHeader('Idelium-Key', $this->customer->apiKey)
            ->postJson(
                '/api/ideliumcl/projects/'.$this->project->id.'/parallel-runs/'.$schedule->id.'/claim',
                ['workerId' => 'runner-01']
            )->assertStatus(409)
            ->assertJsonPath('message', 'Agent is not approved and healthy for new run ownership.')
            ->assertJsonPath('agentStatus', AgentRegistration::STATUS_PENDING);

        Sanctum::actingAs($this->user(2));
        $agent = AgentRegistration::where('agentId', 'runner-01')->firstOrFail();
        $this->putJson('/api/admin/agents/'.$agent->id.'/status', [
            'status' => AgentRegistration::STATUS_APPROVED,
        ])->assertOk()
            ->assertJsonPath('data.status', AgentRegistration::STATUS_APPROVED);

        $this->withHeader('Idelium-Key', $this->customer->apiKey)
            ->postJson(
                '/api/ideliumcl/projects/'.$this->project->id.'/parallel-runs/'.$schedule->id.'/claim',
                ['workerId' => 'runner-01']
            )->assertOk()
            ->assertJsonPath('status', ParallelRunSchedule::STATUS_RUNNING);
    }

    public function test_unhealthy_approved_agent_cannot_claim_new_run(): void
    {
        $schedule = $this->schedule();
        AgentRegistration::forceCreate([
            'idCostumer' => $this->customer->id,
            'agentId' => 'runner-unhealthy',
            'status' => AgentRegistration::STATUS_APPROVED,
            'version' => '1.0.14',
            'runtimes' => ['selenium'],
            'capabilities' => [],
            'maxConcurrency' => 1,
            'health' => AgentRegistration::HEALTH_UNHEALTHY,
            'lastSeenAt' => now(),
        ]);

        $this->withHeader('Idelium-Key', $this->customer->apiKey)
            ->postJson(
                '/api/ideliumcl/projects/'.$this->project->id.'/parallel-runs/'.$schedule->id.'/claim',
                ['workerId' => 'runner-unhealthy']
            )->assertStatus(409)
            ->assertJsonPath('agentHealth', AgentRegistration::HEALTH_UNHEALTHY);
    }

    public function test_agent_inventory_is_tenant_scoped(): void
    {
        AgentRegistration::forceCreate([
            'idCostumer' => $this->otherCustomer->id,
            'agentId' => 'foreign-runner',
            'status' => AgentRegistration::STATUS_APPROVED,
            'version' => '1.0.14',
            'runtimes' => ['postman'],
            'capabilities' => [],
            'maxConcurrency' => 1,
            'health' => AgentRegistration::HEALTH_HEALTHY,
            'lastSeenAt' => now(),
        ]);

        Sanctum::actingAs($this->user(3));
        $this->getJson('/api/admin/agents')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonMissing(['agentId' => 'foreign-runner']);
    }

    private function customer(string $apiKey, string $name): Costumer
    {
        return Costumer::forceCreate([
            'costumer' => $name,
            'description' => $name,
            'logo' => json_encode([]),
            'licenseExpiration' => now()->addYear(),
            'apiKey' => $apiKey,
        ]);
    }

    private function user(int $role): User
    {
        return User::forceCreate([
            'name' => 'Agent user',
            'role' => $role,
            'email' => 'agent-user-'.$role.'-'.uniqid().'@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $this->customer->id,
        ]);
    }

    private function schedule(): ParallelRunSchedule
    {
        return ParallelRunSchedule::forceCreate([
            'idProject' => $this->project->id,
            'testCycleId' => $this->testCycle->id,
            'idCostumer' => $this->customer->id,
            'idempotencyKey' => 'agent-schedule-'.uniqid(),
            'status' => ParallelRunSchedule::STATUS_QUEUED,
            'requestedConcurrency' => 1,
            'workerStates' => [],
            'resultSummary' => [],
            'metadata' => [],
            'scheduledAt' => now(),
        ]);
    }
}
