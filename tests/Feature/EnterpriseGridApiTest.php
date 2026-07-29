<?php

namespace Tests\Feature;

use App\Models\Costumer;
use App\Models\Environment;
use App\Models\Plugin;
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

class EnterpriseGridApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_customers_grid_is_bounded_and_never_exposes_api_keys(): void
    {
        Role::forceCreate(['id' => 1, 'name' => 'superadmin']);
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [$firstCustomer, $superadmin] = $this->createTenant('first', 1);
        $secondCustomer = $this->createTenant('second')[0];

        Sanctum::actingAs($superadmin);

        $this->getJson(
            '/api/admin/costumers?page=1&pageSize=1&search=second&sort=costumer&direction=asc'
        )->assertOk()
            ->assertJsonPath('data.0.id', $secondCustomer->id)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissingPath('data.0.apiKey');

        $this->getJson('/api/admin/costumers')
            ->assertOk()
            ->assertJsonMissingPath('0.apiKey')
            ->assertJsonFragment(['id' => $firstCustomer->id]);
    }

    public function test_accounts_grid_is_bounded_and_preserves_tenant_scope(): void
    {
        Role::forceCreate(['id' => 2, 'name' => 'admin']);
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [$firstCustomer, $admin] = $this->createTenant('first', 2);
        [, $otherUser] = $this->createTenant('second');
        User::forceCreate([
            'name' => 'Matching user',
            'role' => 3,
            'email' => 'matching@example.test',
            'password' => Hash::make('SensitivePassword123!'),
            'idCostumer' => $firstCustomer->id,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson(
            '/api/admin/accounts?page=1&pageSize=10&search=matching&sort=email&direction=asc'
        )->assertOk()
            ->assertJsonPath('data.0.email', 'matching@example.test')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.sort', 'email')
            ->assertJsonMissing(['email' => $otherUser->email])
            ->assertJsonMissingPath('data.0.password');
    }

    public function test_projects_grid_preserves_legacy_array_response_without_grid_parameters(): void
    {
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [, $user] = $this->createTenant('first');
        $this->createProjectFor($user->idCostumer, 'Checkout', 'Checkout project');
        $this->createProjectFor($user->idCostumer, 'Login', 'Login project');

        Sanctum::actingAs($user);

        $this->getJson('/api/admin/projects')
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonPath('0.name', 'FIRST')
            ->assertJsonMissingPath('meta');
    }

    public function test_environment_grid_is_bounded_and_tenant_scoped(): void
    {
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [$customer, $user, $project] = $this->createTenant('first');
        Environment::forceCreate([
            'code' => 'production',
            'description' => 'Production browser',
            'config' => json_encode([]),
            'idProject' => $project->id,
            'idCostumer' => $customer->id,
        ]);
        [, , $otherProject] = $this->createTenant('second');

        Sanctum::actingAs($user);

        $this->getJson(
            '/api/admin/environments/'.$project->id.'?page=1&pageSize=10&search=production&sort=code&direction=asc'
        )->assertOk()
            ->assertJsonPath('data.0.code', 'production')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissingPath('data.0.config');
        $this->getJson(
            '/api/admin/environments/'.$otherProject->id.'?page=1&pageSize=10'
        )->assertNotFound();
    }

    public function test_plugin_grid_is_bounded_without_exposing_source_code(): void
    {
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [$customer, $user, $project] = $this->createTenant('first');
        Plugin::forceCreate([
            'name' => 'checkout_plugin',
            'description' => 'Checkout helper',
            'code' => json_encode(['print("safe")']),
            'idProject' => $project->id,
            'idCostumer' => $customer->id,
        ]);

        Sanctum::actingAs($user);

        $this->getJson(
            '/api/admin/plugins/'.$project->id.'?page=1&pageSize=10&search=checkout&sort=name&direction=asc'
        )->assertOk()
            ->assertJsonPath('data.0.name', 'checkout_plugin')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissingPath('data.0.code');
    }

    public function test_projects_grid_supports_server_side_search_filter_sort_and_pagination(): void
    {
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [, $user] = $this->createTenant('first');
        $checkout = $this->createProjectFor(
            $user->idCostumer,
            'Checkout',
            'Browser checkout'
        );
        $this->createProjectFor($user->idCostumer, 'Login', 'Browser login');
        $this->createProjectFor($user->idCostumer, 'Postman', 'API regression');

        Sanctum::actingAs($user);

        $this->getJson(
            '/api/admin/projects?page=1&pageSize=1&search=browser&sort=name&direction=asc&filter[id]='.$checkout->id
        )->assertOk()
            ->assertJsonPath('data.0.id', $checkout->id)
            ->assertJsonPath('data.0.name', 'Checkout')
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.pageSize', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.sort', 'name')
            ->assertJsonPath('meta.direction', 'asc')
            ->assertJsonPath('meta.hasPreviousPage', false)
            ->assertJsonPath('meta.hasNextPage', false);
    }

    public function test_projects_grid_remains_tenant_scoped_when_requested_as_grid(): void
    {
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [, $firstUser] = $this->createTenant('first');
        [$secondCustomer] = $this->createTenant('second');
        $this->createProjectFor($secondCustomer->id, 'Protected', 'Protected project');

        Sanctum::actingAs($firstUser);

        $this->getJson('/api/admin/projects?page=1&pageSize=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'FIRST')
            ->assertJsonPath('meta.total', 1);
    }

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

    public function test_test_cycles_grid_preserves_legacy_array_response_without_grid_parameters(): void
    {
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [$customer, $user, $project] = $this->createTenant('first');
        $this->createTestCycle($customer, $project, 'Nightly cycle');
        $this->createTestCycle($customer, $project, 'Smoke cycle');

        Sanctum::actingAs($user);

        $this->getJson('/api/admin/testcycles/'.$project->id)
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.name', 'Nightly cycle')
            ->assertJsonMissingPath('meta');
    }

    public function test_test_cycles_grid_supports_server_side_search_filter_sort_and_pagination(): void
    {
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [$customer, $user, $project] = $this->createTenant('first');
        $nightly = $this->createTestCycle($customer, $project, 'Nightly cycle', 'Browser nightly');
        $this->createTestCycle($customer, $project, 'Smoke cycle', 'Browser smoke');
        $this->createTestCycle($customer, $project, 'Postman cycle', 'API regression');

        Sanctum::actingAs($user);

        $this->getJson(
            '/api/admin/testcycles/'.$project->id.'?page=1&pageSize=1&search=browser&sort=name&direction=asc&filter[id]='.$nightly->id
        )->assertOk()
            ->assertJsonPath('data.0.id', $nightly->id)
            ->assertJsonPath('data.0.name', 'Nightly cycle')
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.pageSize', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.sort', 'name')
            ->assertJsonPath('meta.direction', 'asc');
    }

    public function test_test_cycles_grid_remains_tenant_scoped_when_requested_as_grid(): void
    {
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [, $firstUser, $firstProject] = $this->createTenant('first');
        [$secondCustomer, , $secondProject] = $this->createTenant('second');
        $this->createTestCycle($secondCustomer, $secondProject, 'Protected cycle');

        Sanctum::actingAs($firstUser);

        $this->getJson('/api/admin/testcycles/'.$secondProject->id.'?page=1&pageSize=10')
            ->assertNotFound();
        $this->getJson('/api/admin/testcycles/'.$firstProject->id.'?page=1&pageSize=10')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    private function createTenant(string $prefix, int $role = 3): array
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
            'role' => $role,
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

    private function createTestCycle(
        Costumer $customer,
        Project $project,
        string $name,
        string $description = 'Test cycle'
    ): TestCycle {
        return TestCycle::forceCreate([
            'name' => $name,
            'description' => $description,
            'config' => json_encode([]),
            'idProject' => $project->id,
            'idCostumer' => $customer->id,
        ]);
    }

    private function createProjectFor(
        int $customerId,
        string $name,
        string $description
    ): Project {
        return Project::forceCreate([
            'name' => $name,
            'description' => $description,
            'idCostumer' => $customerId,
        ]);
    }
}
