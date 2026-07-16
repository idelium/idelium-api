<?php

namespace Tests\Feature;

use App\Models\Costumer;
use App\Models\Project;
use App\Models\Role;
use App\Models\TestCycle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TestCycleTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Costumer $firstCustomer;

    private Costumer $secondCustomer;

    private Project $firstProject;

    private Project $secondProject;

    private TestCycle $firstTestCycle;

    private TestCycle $secondTestCycle;

    protected function setUp(): void
    {
        parent::setUp();

        Role::forceCreate(['id' => 3, 'name' => 'user']);
        $this->firstCustomer = $this->createCustomer('First customer');
        $this->secondCustomer = $this->createCustomer('Second customer');
        $this->firstProject = $this->createProject($this->firstCustomer, 'FIRST');
        $this->secondProject = $this->createProject($this->secondCustomer, 'SECOND');
        $this->firstTestCycle = $this->createTestCycle(
            $this->firstCustomer,
            $this->firstProject,
            'First cycle'
        );
        $this->secondTestCycle = $this->createTestCycle(
            $this->secondCustomer,
            $this->secondProject,
            'Second cycle'
        );

        Sanctum::actingAs($this->createUser($this->firstCustomer));
    }

    public function test_customer_cannot_list_another_customers_test_cycles(): void
    {
        $this->getJson('/api/admin/testcycles/'.$this->secondProject->id)
            ->assertNotFound();
    }

    public function test_customer_cannot_read_another_customers_test_cycle(): void
    {
        $this->getJson(
            '/api/admin/testcycles/'.$this->secondProject->id.'/'.$this->secondTestCycle->id
        )->assertNotFound();

        $this->getJson(
            '/api/admin/testcycles/'.$this->firstProject->id.'/'.$this->secondTestCycle->id
        )->assertNotFound();
    }

    public function test_customer_cannot_create_a_test_cycle_for_another_customers_project(): void
    {
        $this->postJson('/api/admin/testcycles', [
            'name' => 'Unauthorized cycle',
            'description' => 'Unauthorized cycle',
            'config' => json_encode([]),
            'idProject' => $this->secondProject->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['idProject']);

        $this->assertDatabaseMissing('test_cycles', [
            'name' => 'Unauthorized cycle',
            'idCostumer' => $this->firstCustomer->id,
        ]);
    }

    public function test_customer_cannot_update_another_customers_test_cycle(): void
    {
        $this->putJson(
            '/api/admin/testcycles/'.$this->secondProject->id.'/'.$this->secondTestCycle->id,
            [
                'description' => 'Unauthorized change',
                'config' => json_encode(['changed' => true]),
            ]
        )->assertNotFound();

        $this->assertSame(
            'Second cycle',
            $this->secondTestCycle->fresh()->description
        );
    }

    public function test_customer_can_read_and_update_an_owned_test_cycle(): void
    {
        $this->getJson(
            '/api/admin/testcycles/'.$this->firstProject->id.'/'.$this->firstTestCycle->id
        )
            ->assertOk()
            ->assertJsonPath('id', $this->firstTestCycle->id)
            ->assertJsonMissingPath('idCostumer');

        $config = json_encode(['tests' => [1, 2]]);
        $this->putJson(
            '/api/admin/testcycles/'.$this->firstProject->id.'/'.$this->firstTestCycle->id,
            [
                'description' => 'Updated cycle',
                'config' => $config,
            ]
        )->assertOk()->assertJsonFragment([
            'id' => $this->firstTestCycle->id,
            'description' => 'Updated cycle',
        ]);

        $this->assertDatabaseHas('test_cycles', [
            'id' => $this->firstTestCycle->id,
            'description' => 'Updated cycle',
            'config' => $config,
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
}
