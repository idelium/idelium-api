<?php

namespace Tests\Feature;

use App\Models\Costumer;
use App\Models\Project;
use App\Models\Role;
use App\Models\Step;
use App\Models\Test;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnterpriseGridApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_steps_grid_preserves_legacy_array_response_without_grid_parameters(): void
    {
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [$customer, $user, $project] = $this->createTenant('first');
        $this->createStep($customer, $project, 'Second step', 2);
        $this->createStep($customer, $project, 'First step', 1);

        Sanctum::actingAs($user);

        $this->getJson('/api/admin/steps/'.$project->id)
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.name', 'First step')
            ->assertJsonMissingPath('meta');
    }

    public function test_steps_grid_supports_server_side_search_filter_sort_and_pagination(): void
    {
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [$customer, $user, $project] = $this->createTenant('first');
        $this->createStep($customer, $project, 'Open browser', 1, 'Selenium start');
        $click = $this->createStep($customer, $project, 'Click login', 2, 'Selenium action');
        $this->createStep($customer, $project, 'Postman echo', 3, 'API action');

        Sanctum::actingAs($user);

        $this->getJson(
            '/api/admin/steps/'.$project->id.'?page=1&pageSize=1&search=selenium&sort=name&direction=desc&filter[id]='.$click->id
        )->assertOk()
            ->assertJsonPath('data.0.id', $click->id)
            ->assertJsonPath('data.0.name', 'Click login')
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.pageSize', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.sort', 'name')
            ->assertJsonPath('meta.direction', 'desc')
            ->assertJsonPath('meta.hasPreviousPage', false)
            ->assertJsonPath('meta.hasNextPage', false)
            ->assertJsonPath('meta.stale', false)
            ->assertJsonPath('meta.partial', false);
    }

    public function test_steps_grid_remains_tenant_scoped_when_requested_as_grid(): void
    {
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [, $firstUser, $firstProject] = $this->createTenant('first');
        [$secondCustomer, , $secondProject] = $this->createTenant('second');
        $this->createStep($secondCustomer, $secondProject, 'Protected step', 1);

        Sanctum::actingAs($firstUser);

        $this->getJson('/api/admin/steps/'.$secondProject->id.'?page=1&pageSize=10')
            ->assertNotFound();
        $this->getJson('/api/admin/steps/'.$firstProject->id.'?page=1&pageSize=10')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_tests_grid_preserves_legacy_array_response_without_grid_parameters(): void
    {
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [$customer, $user, $project] = $this->createTenant('first');
        $this->createTest($customer, $project, 'Checkout flow');
        $this->createTest($customer, $project, 'Login flow');

        Sanctum::actingAs($user);

        $this->getJson('/api/admin/tests/'.$project->id)
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.name', 'Checkout flow')
            ->assertJsonMissingPath('meta');
    }

    public function test_tests_grid_supports_server_side_search_filter_sort_and_pagination(): void
    {
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [$customer, $user, $project] = $this->createTenant('first');
        $checkout = $this->createTest($customer, $project, 'Checkout flow', 'Browser checkout');
        $this->createTest($customer, $project, 'Login flow', 'Browser login');
        $this->createTest($customer, $project, 'Postman echo', 'API regression');

        Sanctum::actingAs($user);

        $this->getJson(
            '/api/admin/tests/'.$project->id.'?page=1&pageSize=1&search=browser&sort=name&direction=asc&filter[id]='.$checkout->id
        )->assertOk()
            ->assertJsonPath('data.0.id', $checkout->id)
            ->assertJsonPath('data.0.name', 'Checkout flow')
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.pageSize', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.sort', 'name')
            ->assertJsonPath('meta.direction', 'asc')
            ->assertJsonPath('meta.hasPreviousPage', false)
            ->assertJsonPath('meta.hasNextPage', false);
    }

    public function test_tests_grid_remains_tenant_scoped_when_requested_as_grid(): void
    {
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [, $firstUser, $firstProject] = $this->createTenant('first');
        [$secondCustomer, , $secondProject] = $this->createTenant('second');
        $this->createTest($secondCustomer, $secondProject, 'Protected test');

        Sanctum::actingAs($firstUser);

        $this->getJson('/api/admin/tests/'.$secondProject->id.'?page=1&pageSize=10')
            ->assertNotFound();
        $this->getJson('/api/admin/tests/'.$firstProject->id.'?page=1&pageSize=10')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    private function createTenant(string $prefix): array
    {
        $customer = Costumer::forceCreate([
            'costumer' => ucfirst($prefix).' customer',
            'description' => ucfirst($prefix).' customer',
            'logo' => json_encode([]),
            'licenseExpiration' => now()->addYear(),
            'apiKey' => $prefix.'-api-key',
        ]);
        $user = User::forceCreate([
            'name' => ucfirst($prefix).' user',
            'role' => 3,
            'email' => $prefix.'@example.test',
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $customer->id,
        ]);
        $project = Project::forceCreate([
            'name' => strtoupper($prefix),
            'description' => ucfirst($prefix).' project',
            'idCostumer' => $customer->id,
        ]);

        return [$customer, $user, $project];
    }

    private function createStep(
        Costumer $customer,
        Project $project,
        string $name,
        int $order,
        string $description = 'Step'
    ): Step {
        return Step::forceCreate([
            'name' => $name,
            'description' => $description,
            'config' => json_encode([]),
            'idProject' => $project->id,
            'order' => $order,
            'idCostumer' => $customer->id,
        ]);
    }

    private function createTest(
        Costumer $customer,
        Project $project,
        string $name,
        string $description = 'Test'
    ): Test {
        return Test::forceCreate([
            'name' => $name,
            'description' => $description,
            'config' => json_encode([]),
            'idProject' => $project->id,
            'idCostumer' => $customer->id,
        ]);
    }
}
