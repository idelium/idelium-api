<?php

namespace Tests\Feature;

use App\Models\ArtifactDescriptor;
use App\Models\Costumer;
use App\Models\PerformedTestCycle;
use App\Models\Project;
use App\Models\Role;
use App\Models\TestCycle;
use App\Models\User;
use App\Services\ArtifactDescriptorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

        $this->expectException(\Illuminate\Validation\ValidationException::class);

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
