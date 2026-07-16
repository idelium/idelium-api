<?php

namespace Tests\Feature;

use App\Models\Costumer;
use App\Models\Environment;
use App\Models\Plugin;
use App\Models\Project;
use App\Models\Role;
use App\Models\Step;
use App\Models\Test;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectResourceTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Costumer $firstCustomer;

    private Costumer $secondCustomer;

    private Project $firstProject;

    private Project $secondProject;

    private Test $secondTest;

    private Step $secondStep;

    private Plugin $secondPlugin;

    private Environment $secondEnvironment;

    protected function setUp(): void
    {
        parent::setUp();

        Role::forceCreate(['id' => 3, 'name' => 'user']);
        $this->firstCustomer = $this->createCustomer('First customer');
        $this->secondCustomer = $this->createCustomer('Second customer');
        $this->firstProject = $this->createProject($this->firstCustomer, 'FIRST');
        $this->secondProject = $this->createProject($this->secondCustomer, 'SECOND');
        $this->secondTest = Test::forceCreate([
            'name' => 'Protected test',
            'description' => 'Protected test',
            'config' => json_encode([]),
            'idProject' => $this->secondProject->id,
            'idCostumer' => $this->secondCustomer->id,
        ]);
        $this->secondStep = Step::forceCreate([
            'name' => 'Protected step',
            'description' => 'Protected step',
            'config' => json_encode([]),
            'idProject' => $this->secondProject->id,
            'idCostumer' => $this->secondCustomer->id,
            'order' => 1,
        ]);
        $this->secondPlugin = Plugin::forceCreate([
            'name' => 'Protected plugin',
            'description' => 'Protected plugin',
            'code' => json_encode([]),
            'idProject' => $this->secondProject->id,
            'idCostumer' => $this->secondCustomer->id,
        ]);
        $this->secondEnvironment = Environment::forceCreate([
            'code' => 'protected',
            'description' => 'Protected environment',
            'config' => json_encode([]),
            'idProject' => $this->secondProject->id,
            'idCostumer' => $this->secondCustomer->id,
        ]);

        Sanctum::actingAs($this->createUser($this->firstCustomer));
    }

    public function test_customer_cannot_access_another_customers_project(): void
    {
        $this->getJson('/api/admin/projects/'.$this->secondProject->id)
            ->assertNotFound();
        $this->putJson('/api/admin/projects/'.$this->secondProject->id, [
            'name' => 'CHANGED',
            'description' => 'Changed',
        ])->assertNotFound();
        $this->deleteJson('/api/admin/projects/'.$this->secondProject->id)
            ->assertNotFound();

        $this->assertDatabaseHas('projects', [
            'id' => $this->secondProject->id,
            'name' => 'SECOND',
            'idCostumer' => $this->secondCustomer->id,
        ]);
    }

    public function test_customer_cannot_list_another_customers_project_resources(): void
    {
        foreach (['tests', 'steps', 'plugins', 'environments'] as $resource) {
            $this->getJson('/api/admin/'.$resource.'/'.$this->secondProject->id)
                ->assertNotFound();
        }
    }

    public function test_customer_cannot_create_resources_for_another_customers_project(): void
    {
        $requests = [
            ['tests', [
                'name' => 'Unauthorized test',
                'description' => 'Unauthorized test',
                'config' => json_encode([]),
            ]],
            ['steps', [
                'name' => 'Unauthorized step',
                'description' => 'Unauthorized step',
                'config' => json_encode([]),
            ]],
            ['plugins', [
                'name' => 'Unauthorized plugin',
                'description' => 'Unauthorized plugin',
                'code' => [],
            ]],
            ['environments', [
                'code' => 'unauthorized',
                'description' => 'Unauthorized environment',
                'config' => json_encode([]),
            ]],
        ];

        foreach ($requests as [$resource, $payload]) {
            $this->postJson('/api/admin/'.$resource, [
                ...$payload,
                'idProject' => $this->secondProject->id,
            ])->assertUnprocessable()->assertJsonValidationErrors(['idProject']);
        }

        $this->assertDatabaseCount('tests', 1);
        $this->assertDatabaseCount('steps', 1);
        $this->assertDatabaseCount('plugins', 1);
        $this->assertDatabaseCount('environments', 1);
    }

    public function test_customer_cannot_read_or_update_another_customers_resources(): void
    {
        $resources = [
            ['tests', $this->secondTest->id, ['config' => json_encode(['changed' => true])]],
            ['steps', $this->secondStep->id, [
                'name' => 'Changed',
                'description' => 'Changed',
                'config' => json_encode(['changed' => true]),
            ]],
            ['plugins', $this->secondPlugin->id, ['code' => 'changed']],
            ['environments', $this->secondEnvironment->id, [
                'config' => json_encode(['changed' => true]),
            ]],
        ];

        foreach ($resources as [$resource, $id, $payload]) {
            $this->getJson(
                '/api/admin/'.$resource.'/'.$this->secondProject->id.'/'.$id
            )->assertNotFound();
            $this->putJson(
                '/api/admin/'.$resource.'/'.$this->secondProject->id.'/'.$id,
                $payload
            )->assertNotFound();
        }
    }

    public function test_customer_cannot_delete_another_customers_resources(): void
    {
        foreach ([
            ['steps', $this->secondStep],
            ['plugins', $this->secondPlugin],
            ['environments', $this->secondEnvironment],
        ] as [$resource, $model]) {
            $this->deleteJson(
                '/api/admin/'.$resource.'/'.$this->secondProject->id.'/'.$model->id
            )->assertNotFound();
            $this->assertDatabaseHas($model->getTable(), [
                'id' => $model->id,
                'idCostumer' => $this->secondCustomer->id,
            ]);
        }
    }

    public function test_customer_can_manage_owned_project_resources(): void
    {
        $this->postJson('/api/admin/tests', [
            'name' => 'Owned test',
            'description' => 'Owned test',
            'config' => json_encode([]),
            'idProject' => $this->firstProject->id,
        ])->assertOk()->assertJsonFragment(['name' => 'Owned test']);

        $test = Test::where('name', 'Owned test')->firstOrFail();
        $this->getJson(
            '/api/admin/tests/'.$this->firstProject->id.'/'.$test->id
        )->assertOk()->assertJsonMissingPath('idCostumer');
        $this->putJson(
            '/api/admin/tests/'.$this->firstProject->id.'/'.$test->id,
            ['config' => json_encode(['updated' => true])]
        )->assertOk();

        $this->assertDatabaseHas('tests', [
            'id' => $test->id,
            'config' => json_encode(['updated' => true]),
            'idCostumer' => $this->firstCustomer->id,
        ]);
    }

    private function createCustomer(string $name): Costumer
    {
        return Costumer::forceCreate([
            'costumer' => $name,
            'description' => $name,
            'logo' => json_encode([]),
            'licenseExpiration' => now()->addYear(),
            'apiKey' => (string) str($name)->slug().'-api-key',
        ]);
    }

    private function createUser(Costumer $customer): User
    {
        return User::forceCreate([
            'name' => 'Test user',
            'role' => 3,
            'email' => 'user-'.$customer->id.'@example.test',
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
}
