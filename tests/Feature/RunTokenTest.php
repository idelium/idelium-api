<?php

namespace Tests\Feature;

use App\Models\Costumer;
use App\Models\AgentRegistration;
use App\Models\AuditEvent;
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

    public function test_run_token_issuance_is_audited_without_token_value(): void
    {
        $response = $this->withHeader('Idelium-Key', $this->customer->apiKey)
            ->postJson(
                '/api/ideliumcl/projects/'.$this->project->id
                .'/parallel-runs/'.$this->schedule->id.'/tokens',
                ['agentId' => 'agent-1']
            )
            ->assertCreated();

        $event = AuditEvent::where('action', 'run_token.issue')->firstOrFail();

        $this->assertSame('[REDACTED]', $event->afterValues['token']);
        $this->assertSame('agent-1', $event->afterValues['agentId']);
        $this->assertStringNotContainsString($response->json('token'), json_encode($event->toArray()));
    }

    public function test_worker_claim_requires_short_lived_run_token(): void
    {
        $this->withHeader('Idelium-Key', $this->customer->apiKey)
            ->postJson(
                '/api/ideliumcl/projects/'.$this->project->id
                .'/parallel-runs/'.$this->schedule->id.'/claim',
                ['workerId' => 'agent-1']
            )
            ->assertUnauthorized()
            ->assertJsonPath('message', 'A short-lived run token is required to claim a worker slot.');
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
        $this->assertSame(
            '[REDACTED]',
            AuditEvent::where('action', 'run_token.consume')->firstOrFail()->afterValues['token']
        );

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
        $this->assertSame(
            '[REDACTED]',
            AuditEvent::where('action', 'run_token.reject')->firstOrFail()->afterValues['token']
        );
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

    public function test_run_token_revocation_is_audited_and_blocks_use(): void
    {
        $issued = app(RunTokenService::class)->issue($this->schedule, 'agent-1');
        $tokenId = $issued['runToken']->tokenId;

        $this->withHeader('Idelium-Key', $this->customer->apiKey)
            ->postJson(
                '/api/ideliumcl/projects/'.$this->project->id
                .'/parallel-runs/'.$this->schedule->id.'/tokens/'.$tokenId.'/revoke'
            )
            ->assertOk()
            ->assertJsonPath('tokenId', $tokenId);

        $event = AuditEvent::where('action', 'run_token.revoke')->firstOrFail();

        $this->assertSame('[REDACTED]', $event->afterValues['tokenId']);
        $this->assertStringNotContainsString($issued['token'], json_encode($event->toArray()));

        $this->withHeader('Idelium-Key', $this->customer->apiKey)
            ->withHeader('Idelium-Run-Token', $issued['token'])
            ->postJson(
                '/api/ideliumcl/projects/'.$this->project->id
                .'/parallel-runs/'.$this->schedule->id.'/claim',
                ['workerId' => 'agent-1']
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('runToken');
    }

    public function test_agent_identity_proof_mismatch_is_rejected(): void
    {
        AgentRegistration::forceCreate([
            'idCostumer' => $this->customer->id,
            'agentId' => 'agent-1',
            'status' => AgentRegistration::STATUS_APPROVED,
            'version' => '1.0.14',
            'runtimes' => ['selenium'],
            'capabilities' => [],
            'identityProof' => [
                'certificateSha256' => str_repeat('a', 64),
            ],
            'maxConcurrency' => 1,
            'health' => AgentRegistration::HEALTH_HEALTHY,
            'lastSeenAt' => now(),
        ]);
        $issued = app(RunTokenService::class)->issue($this->schedule, 'agent-1');

        $this->withHeader('Idelium-Key', $this->customer->apiKey)
            ->withHeader('Idelium-Run-Token', $issued['token'])
            ->withHeader('Idelium-Agent-Cert-Sha256', str_repeat('b', 64))
            ->postJson(
                '/api/ideliumcl/projects/'.$this->project->id
                .'/parallel-runs/'.$this->schedule->id.'/claim',
                ['workerId' => 'agent-1']
            )
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Agent identity proof is invalid for this run ownership request.');
    }

    public function test_agent_identity_proof_match_allows_claim(): void
    {
        $thumbprint = str_repeat('c', 64);
        AgentRegistration::forceCreate([
            'idCostumer' => $this->customer->id,
            'agentId' => 'agent-1',
            'status' => AgentRegistration::STATUS_APPROVED,
            'version' => '1.0.14',
            'runtimes' => ['selenium'],
            'capabilities' => [],
            'identityProof' => [
                'certificateSha256' => $thumbprint,
            ],
            'maxConcurrency' => 1,
            'health' => AgentRegistration::HEALTH_HEALTHY,
            'lastSeenAt' => now(),
        ]);
        $issued = app(RunTokenService::class)->issue($this->schedule, 'agent-1');

        $this->withHeader('Idelium-Key', $this->customer->apiKey)
            ->withHeader('Idelium-Run-Token', $issued['token'])
            ->withHeader('Idelium-Agent-Cert-Sha256', strtoupper($thumbprint))
            ->postJson(
                '/api/ideliumcl/projects/'.$this->project->id
                .'/parallel-runs/'.$this->schedule->id.'/claim',
                ['workerId' => 'agent-1']
            )
            ->assertOk()
            ->assertJsonPath('activeWorkers', 1);
    }

    public function test_token_only_runner_routes_do_not_require_customer_api_key(): void
    {
        $issued = app(RunTokenService::class)->issue($this->schedule, 'agent-1');

        $claim = $this->withHeader('Idelium-Run-Token', $issued['token'])
            ->postJson('/api/ideliumrunner/claim', [
                'idProject' => $this->project->id,
                'parallelRun' => $this->schedule->id,
                'workerId' => 'agent-1',
                'capabilities' => ['selenium'],
            ])
            ->assertOk()
            ->assertJsonPath('activeWorkers', 1)
            ->assertJsonStructure(['workerToken', 'workerTokenExpiresAt']);
        $workerToken = $claim->json('workerToken');

        $event = AuditEvent::where('action', 'run_token.consume')->firstOrFail();
        $this->assertSame('[REDACTED]', $event->afterValues['token']);
        $this->assertSame('[REDACTED]', $event->afterValues['tokenId']);
        $this->assertStringNotContainsString($issued['token'], json_encode($event->toArray()));

        $this->withHeader('Idelium-Worker-Token', $workerToken)
            ->postJson('/api/ideliumrunner/heartbeat', [
                'idProject' => $this->project->id,
                'parallelRun' => $this->schedule->id,
                'workerId' => 'agent-1',
                'leaseSeconds' => 300,
            ])
            ->assertOk()
            ->assertJsonPath('activeWorkers', 1);

        $this->withHeader('Idelium-Worker-Token', $workerToken)
            ->putJson('/api/ideliumrunner/worker', [
                'idProject' => $this->project->id,
                'parallelRun' => $this->schedule->id,
                'workerId' => 'agent-1',
                'status' => ParallelRunSchedule::WORKER_COMPLETED,
                'result' => ['tests' => 1],
            ])
            ->assertOk()
            ->assertJsonPath('status', ParallelRunSchedule::STATUS_COMPLETED);

        $this->withHeader('Idelium-Key', $this->customer->apiKey)
            ->getJson(
                '/api/ideliumcl/projects/'.$this->project->id
                .'/parallel-runs/'.$this->schedule->id.'/results'
            )
            ->assertOk()
            ->assertJsonMissingPath('workers.0.workerTokenHash')
            ->assertJsonMissingPath('workers.0.workerTokenExpiresAt');
    }

    public function test_token_only_runner_heartbeat_rejects_invalid_worker_token(): void
    {
        $issued = app(RunTokenService::class)->issue($this->schedule, 'agent-1');

        $this->withHeader('Idelium-Run-Token', $issued['token'])
            ->postJson('/api/ideliumrunner/claim', [
                'idProject' => $this->project->id,
                'parallelRun' => $this->schedule->id,
                'workerId' => 'agent-1',
            ])
            ->assertOk();

        $this->withHeader('Idelium-Worker-Token', 'wrong-token')
            ->postJson('/api/ideliumrunner/heartbeat', [
                'idProject' => $this->project->id,
                'parallelRun' => $this->schedule->id,
                'workerId' => 'agent-1',
            ])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Worker token is invalid, expired, or not bound to this worker.');
    }
}
