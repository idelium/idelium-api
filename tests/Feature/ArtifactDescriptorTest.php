<?php

namespace Tests\Feature;

use App\Jobs\PurgeArtifactDescriptorJob;
use App\Models\ArtifactDescriptor;
use App\Models\AuditEvent;
use App\Models\Costumer;
use App\Models\PerformedTestCycle;
use App\Models\Project;
use App\Models\Role;
use App\Models\TestCycle;
use App\Models\User;
use App\Services\ArtifactDescriptorService;
use App\Services\ArtifactLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ArtifactDescriptorTest extends TestCase
{
    use RefreshDatabase;

    private Costumer $firstCustomer;

    private Costumer $secondCustomer;

    private Project $firstProject;

    private Project $secondProject;

    private PerformedTestCycle $firstRun;

    private PerformedTestCycle $secondRun;

    protected function setUp(): void
    {
        parent::setUp();

        Role::forceCreate(['id' => 1, 'name' => 'superadmin']);
        Role::forceCreate(['id' => 2, 'name' => 'admin']);
        Role::forceCreate(['id' => 3, 'name' => 'user']);

        $this->firstCustomer = $this->createCustomer('first');
        $this->secondCustomer = $this->createCustomer('second');
        $this->firstProject = $this->createProject($this->firstCustomer, 'First project');
        $this->secondProject = $this->createProject($this->secondCustomer, 'Second project');
        $this->firstRun = $this->createRun($this->firstCustomer, $this->firstProject);
        $this->secondRun = $this->createRun($this->secondCustomer, $this->secondProject);
    }

    public function test_descriptor_registration_validates_integrity_and_size(): void
    {
        config([
            'artifacts.max_size_bytes' => 10,
            'artifacts.allowed_content_types' => ['application/json'],
        ]);

        $this->expectException(ValidationException::class);

        app(ArtifactDescriptorService::class)->register([
            'idCostumer' => $this->firstCustomer->id,
            'idProject' => $this->firstProject->id,
            'performedTestCycleId' => $this->firstRun->id,
            'artifactType' => ArtifactDescriptor::TYPE_JSON,
            'name' => 'result.json',
            'contentType' => 'application/json',
            'sizeBytes' => 11,
            'checksumSha256' => 'invalid',
            'storageKey' => 'tenant/run/result.json',
        ]);
    }

    public function test_tenant_scoped_artifact_listing_excludes_cross_tenant_descriptors(): void
    {
        $admin = $this->createUser($this->firstCustomer, 2);
        $firstDescriptor = $this->createDescriptor(
            $this->firstCustomer,
            $this->firstProject,
            $this->firstRun,
            'first.json'
        );
        $this->createDescriptor(
            $this->secondCustomer,
            $this->secondProject,
            $this->secondRun,
            'second.json'
        );

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->getJson(
                '/api/admin/projects/'.$this->firstProject->id
                .'/performed-test-cycles/'.$this->firstRun->id.'/artifacts'
            )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $firstDescriptor->id)
            ->assertJsonPath('data.0.name', 'first.json');
    }

    public function test_cross_tenant_artifact_detail_is_not_found(): void
    {
        $admin = $this->createUser($this->firstCustomer, 2);
        $foreignDescriptor = $this->createDescriptor(
            $this->secondCustomer,
            $this->secondProject,
            $this->secondRun,
            'second.json'
        );

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->getJson(
                '/api/admin/projects/'.$this->firstProject->id
                .'/performed-test-cycles/'.$this->firstRun->id
                .'/artifacts/'.$foreignDescriptor->id
            )
            ->assertNotFound();
    }

    public function test_artifact_legal_hold_blocks_delete_marker_until_released(): void
    {
        $admin = $this->createUser($this->firstCustomer, 2);
        $descriptor = $this->createDescriptor(
            $this->firstCustomer,
            $this->firstProject,
            $this->firstRun,
            'held.json'
        );
        $basePath = '/api/admin/projects/'.$this->firstProject->id
            .'/performed-test-cycles/'.$this->firstRun->id
            .'/artifacts/'.$descriptor->id;

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->putJson($basePath.'/legal-hold', [
                'enabled' => true,
                'reason' => 'Investigation hold',
            ])
            ->assertOk()
            ->assertJsonPath('data.metadata.legalHold.enabled', true)
            ->assertJsonPath('data.metadata.legalHold.reason', 'Investigation hold');

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->postJson($basePath.'/delete-marker')
            ->assertUnprocessable()
            ->assertJsonPath('errors.artifact.0', 'Artifact is under legal hold and cannot be deleted.');

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->putJson($basePath.'/legal-hold', [
                'enabled' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.metadata.legalHold.enabled', false)
            ->assertJsonPath('data.metadata.legalHold.reason', null);

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->postJson($basePath.'/delete-marker')
            ->assertOk()
            ->assertJsonPath('data.state', ArtifactDescriptor::STATE_DELETED);

        $this->assertSame(2, AuditEvent::where('action', 'artifact.legal_hold')->count());
        $this->assertSame(1, AuditEvent::where('action', 'artifact.mark_deleted')->count());
    }

    public function test_user_without_artifact_manage_cannot_change_legal_hold(): void
    {
        $user = $this->createUser($this->firstCustomer, 3);
        $descriptor = $this->createDescriptor(
            $this->firstCustomer,
            $this->firstProject,
            $this->firstRun,
            'readonly.json'
        );

        $this->actingAs($user)
            ->withHeader('Origin', 'https://localhost')
            ->putJson(
                '/api/admin/projects/'.$this->firstProject->id
                .'/performed-test-cycles/'.$this->firstRun->id
                .'/artifacts/'.$descriptor->id.'/legal-hold',
                ['enabled' => true]
            )
            ->assertForbidden();
    }

    public function test_cross_tenant_legal_hold_is_not_found(): void
    {
        $admin = $this->createUser($this->firstCustomer, 2);
        $foreignDescriptor = $this->createDescriptor(
            $this->secondCustomer,
            $this->secondProject,
            $this->secondRun,
            'foreign.json'
        );

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->putJson(
                '/api/admin/projects/'.$this->firstProject->id
                .'/performed-test-cycles/'.$this->firstRun->id
                .'/artifacts/'.$foreignDescriptor->id.'/legal-hold',
                ['enabled' => true]
            )
            ->assertNotFound();
    }

    public function test_artifact_archive_and_restore_are_reversible_and_audited(): void
    {
        $admin = $this->createUser($this->firstCustomer, 2);
        $descriptor = $this->createDescriptor(
            $this->firstCustomer,
            $this->firstProject,
            $this->firstRun,
            'archive.json'
        );
        $basePath = '/api/admin/projects/'.$this->firstProject->id
            .'/performed-test-cycles/'.$this->firstRun->id
            .'/artifacts/'.$descriptor->id;

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->postJson($basePath.'/archive', [
                'reason' => 'Retention grace period',
                'restoreBy' => '2026-08-31T00:00:00Z',
            ])
            ->assertOk()
            ->assertJsonPath('data.state', ArtifactDescriptor::STATE_ARCHIVED)
            ->assertJsonPath('data.metadata.archive.reason', 'Retention grace period')
            ->assertJsonPath('data.metadata.archive.restoreBy', '2026-08-31T00:00:00Z');

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->postJson($basePath.'/restore')
            ->assertOk()
            ->assertJsonPath('data.state', ArtifactDescriptor::STATE_AVAILABLE);

        $this->assertSame(1, AuditEvent::where('action', 'artifact.archive')->count());
        $this->assertSame(1, AuditEvent::where('action', 'artifact.restore')->count());
    }

    public function test_legal_hold_blocks_archive(): void
    {
        $admin = $this->createUser($this->firstCustomer, 2);
        $descriptor = $this->createDescriptor(
            $this->firstCustomer,
            $this->firstProject,
            $this->firstRun,
            'held-archive.json'
        );
        $basePath = '/api/admin/projects/'.$this->firstProject->id
            .'/performed-test-cycles/'.$this->firstRun->id
            .'/artifacts/'.$descriptor->id;

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->putJson($basePath.'/legal-hold', [
                'enabled' => true,
                'reason' => 'Investigation hold',
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->postJson($basePath.'/archive')
            ->assertUnprocessable()
            ->assertJsonPath('errors.artifact.0', 'Artifact is under legal hold and cannot be archived.');
    }

    public function test_artifact_impact_summary_is_tenant_scoped_and_reports_lifecycle_blockers(): void
    {
        $admin = $this->createUser($this->firstCustomer, 2);
        $descriptor = $this->createDescriptor(
            $this->firstCustomer,
            $this->firstProject,
            $this->firstRun,
            'impact.json'
        );
        $descriptor->forceFill([
            'state' => ArtifactDescriptor::STATE_ARCHIVED,
            'retentionUntil' => now()->subDay(),
        ])->save();

        $this->actingAs($admin)
            ->withHeader('Origin', 'https://localhost')
            ->getJson(
                '/api/admin/projects/'.$this->firstProject->id
                .'/performed-test-cycles/'.$this->firstRun->id
                .'/artifacts/'.$descriptor->id.'/impact'
            )
            ->assertOk()
            ->assertJsonPath('data.artifact.id', $descriptor->id)
            ->assertJsonPath('data.summary.hardDeleteEligible', true)
            ->assertJsonPath('data.summary.storageBytes', 2)
            ->assertJsonPath('data.actions.hardDeleteAllowed', true);
    }

    public function test_hard_delete_job_removes_eligible_artifact_and_is_idempotent(): void
    {
        $descriptor = $this->createDescriptor(
            $this->firstCustomer,
            $this->firstProject,
            $this->firstRun,
            'purge.json'
        );
        $descriptor->forceFill([
            'state' => ArtifactDescriptor::STATE_ARCHIVED,
            'retentionUntil' => now()->subDay(),
        ])->save();
        $job = new PurgeArtifactDescriptorJob($descriptor->id);

        $job->handle(app(ArtifactLifecycleService::class));

        $this->assertDatabaseMissing('artifact_descriptors', ['id' => $descriptor->id]);
        $this->assertSame(1, AuditEvent::where('action', 'artifact.hard_delete')
            ->where('result', AuditEvent::RESULT_SUCCESS)
            ->count());

        $job->handle(app(ArtifactLifecycleService::class));

        $this->assertSame(1, AuditEvent::where('action', 'artifact.hard_delete')
            ->where('result', AuditEvent::RESULT_SUCCESS)
            ->count());
    }

    public function test_hard_delete_job_respects_legal_hold(): void
    {
        $descriptor = $this->createDescriptor(
            $this->firstCustomer,
            $this->firstProject,
            $this->firstRun,
            'held-purge.json'
        );
        $descriptor->forceFill([
            'state' => ArtifactDescriptor::STATE_ARCHIVED,
            'retentionUntil' => now()->subDay(),
            'metadata' => [
                'legalHold' => [
                    'enabled' => true,
                    'reason' => 'Investigation hold',
                    'changedAt' => now()->toISOString(),
                ],
            ],
        ])->save();

        (new PurgeArtifactDescriptorJob($descriptor->id))->handle(app(ArtifactLifecycleService::class));

        $this->assertDatabaseHas('artifact_descriptors', ['id' => $descriptor->id]);
        $this->assertSame(1, AuditEvent::where('action', 'artifact.hard_delete')
            ->where('result', AuditEvent::RESULT_FAILURE)
            ->count());
    }

    public function test_purge_expired_artifacts_command_queues_only_eligible_descriptors(): void
    {
        Queue::fake();
        $eligible = $this->createDescriptor(
            $this->firstCustomer,
            $this->firstProject,
            $this->firstRun,
            'eligible.json'
        );
        $eligible->forceFill([
            'state' => ArtifactDescriptor::STATE_ARCHIVED,
            'retentionUntil' => now()->subDay(),
        ])->save();
        $activeRetention = $this->createDescriptor(
            $this->firstCustomer,
            $this->firstProject,
            $this->firstRun,
            'active-retention.json'
        );
        $activeRetention->forceFill([
            'state' => ArtifactDescriptor::STATE_ARCHIVED,
            'retentionUntil' => now()->addDay(),
        ])->save();

        $this->artisan('artifacts:purge-expired --limit=10')
            ->expectsOutput('Queued 1 artifact purge jobs.')
            ->assertExitCode(0);

        Queue::assertPushed(
            PurgeArtifactDescriptorJob::class,
            fn (PurgeArtifactDescriptorJob $job): bool => $job->artifactDescriptorId() === $eligible->id
        );
        Queue::assertNotPushed(
            PurgeArtifactDescriptorJob::class,
            fn (PurgeArtifactDescriptorJob $job): bool => $job->artifactDescriptorId() === $activeRetention->id
        );
    }

    private function createDescriptor(
        Costumer $customer,
        Project $project,
        PerformedTestCycle $run,
        string $name
    ): ArtifactDescriptor {
        return app(ArtifactDescriptorService::class)->register([
            'idCostumer' => $customer->id,
            'idProject' => $project->id,
            'performedTestCycleId' => $run->id,
            'artifactType' => ArtifactDescriptor::TYPE_JSON,
            'name' => $name,
            'contentType' => 'application/json',
            'sizeBytes' => 2,
            'checksumSha256' => hash('sha256', '{}'),
            'storageKey' => $customer->id.'/'.$run->id.'/'.$name,
        ]);
    }

    private function createRun(Costumer $customer, Project $project): PerformedTestCycle
    {
        $testCycle = TestCycle::forceCreate([
            'name' => $project->name.' cycle',
            'description' => $project->name.' cycle',
            'config' => json_encode([]),
            'idProject' => $project->id,
            'idCostumer' => $customer->id,
        ]);

        return PerformedTestCycle::forceCreate([
            'testCycleId' => $testCycle->id,
            'date' => now(),
            'status' => 1,
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

    private function createCustomer(string $prefix): Costumer
    {
        return Costumer::forceCreate([
            'costumer' => ucfirst($prefix).' customer',
            'description' => ucfirst($prefix).' customer',
            'logo' => json_encode([]),
            'licenseExpiration' => now()->addYear(),
            'apiKey' => $prefix.'-api-key',
        ]);
    }

    private function createUser(Costumer $customer, int $role): User
    {
        return User::forceCreate([
            'name' => 'Test user',
            'role' => $role,
            'email' => uniqid('artifact-', true).'@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $customer->id,
        ]);
    }
}
