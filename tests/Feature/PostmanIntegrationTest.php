<?php

namespace Tests\Feature;

use App\Models\Costumer;
use App\Models\PerformedTest;
use App\Models\PerformedTestCycle;
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

class PostmanIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cli_postman_result_is_available_to_its_tenant_only(): void
    {
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [$firstCustomer, $firstUser] = $this->createTenant('first');
        [$secondCustomer, $secondUser] = $this->createTenant('second');
        $firstHierarchy = $this->createResultParents($firstCustomer);
        $secondHierarchy = $this->createResultParents($secondCustomer);

        $results = [[
            'name' => 'Echo request',
            'method' => 'POST',
            'url' => 'https://example.test/echo',
            'status' => '200',
            'time' => 0.12,
            'response' => '{"message":"ok"}',
            'passed' => true,
            'assertions' => [
                ['name' => 'status', 'passed' => true, 'message' => 'Status matched.'],
                ['name' => 'body', 'passed' => true, 'message' => 'Body matched.'],
            ],
        ]];

        $response = $this->withHeader('Idelium-Key', $firstCustomer->apiKey)
            ->postJson('/api/ideliumcl/step', [
                'testCycleId' => $firstHierarchy['performedCycle']->id,
                'testId' => $firstHierarchy['performedTest']->id,
                'stepId' => $firstHierarchy['step']->id,
                'name' => 'Postman collection',
                'status' => 1,
                'screenshots' => json_encode([]),
                'data' => json_encode($results),
                'type' => 'postman',
            ])->assertOk();

        Sanctum::actingAs($firstUser);
        $performedStep = $this->getJson(
            '/api/admin/stepsperfomed/'.$firstHierarchy['performedTest']->id
        )->assertOk()->assertJsonCount(1)->json('0');
        $this->assertSame('postman', $performedStep['type']);
        $this->assertSame($results, json_decode($performedStep['data'], true));
        $this->assertArrayNotHasKey('idCostumer', $performedStep);

        $this->getJson(
            '/api/admin/testsperfomed/'.$firstHierarchy['performedCycle']->id
        )->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $firstHierarchy['performedTest']->id)
            ->assertJsonPath('0.testCycleDoneId', $firstHierarchy['performedCycle']->id);

        Sanctum::actingAs($secondUser);
        $this->getJson(
            '/api/admin/stepsperfomed/'.$firstHierarchy['performedTest']->id
        )->assertOk()->assertExactJson([]);
        $this->getJson(
            '/api/admin/testsperfomed/'.$firstHierarchy['performedCycle']->id
        )->assertOk()->assertExactJson([]);
        $this->getJson(
            '/api/admin/testcyclesperfomed/'.$firstHierarchy['testCycle']->id
        )->assertOk()->assertExactJson([]);

        $this->assertNotSame(
            $firstHierarchy['performedTest']->id,
            $secondHierarchy['performedTest']->id
        );
    }

    public function test_performed_tests_are_loaded_by_selected_performed_cycle(): void
    {
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [$customer, $user] = $this->createTenant('first');
        $hierarchy = $this->createResultParents($customer);
        $secondRun = PerformedTestCycle::forceCreate([
            'testCycleId' => $hierarchy['testCycle']->id,
            'date' => now()->addMinute(),
            'status' => 0,
            'idCostumer' => $customer->id,
        ]);
        $secondRunTest = PerformedTest::forceCreate([
            'testCycleDoneId' => $secondRun->id,
            'testId' => $hierarchy['test']->id,
            'status' => 1,
            'name' => 'API test second run',
            'idCostumer' => $customer->id,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/admin/testsperfomed/'.$hierarchy['performedCycle']->id)
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $hierarchy['performedTest']->id)
            ->assertJsonPath('0.testCycleDoneId', $hierarchy['performedCycle']->id);
        $this->getJson('/api/admin/testsperfomed/'.$secondRun->id)
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $secondRunTest->id)
            ->assertJsonPath('0.testCycleDoneId', $secondRun->id);
    }

    public function test_cli_postman_data_is_returned_with_performed_test_results(): void
    {
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [$customer, $user] = $this->createTenant('first');
        [, $secondUser] = $this->createTenant('second');
        $hierarchy = $this->createResultParents($customer);

        $postmanData = [[
            'name' => 'Create payload',
            'method' => 'POST',
            'url' => 'https://postman-echo.com/post?api_key=secret',
            'status' => 200,
            'time' => 140,
            'response' => '{"message":"ok"}',
            'passed' => true,
            'assertions' => [
                ['name' => 'status', 'passed' => true, 'message' => 'Status matched.'],
            ],
        ]];

        $this->withHeader('Idelium-Key', $customer->apiKey)
            ->putJson('/api/ideliumcl/test', [
                'testId' => $hierarchy['performedTest']->id,
                'status' => 1,
                'postmanData' => $postmanData,
            ])->assertOk();

        Sanctum::actingAs($user);
        $encodedPostmanData = $this->getJson(
            '/api/admin/testsperfomed/'.$hierarchy['performedCycle']->id
        )->assertOk()
            ->assertJsonCount(1)
            ->json('0.postmanData');

        $decodedPostmanData = json_decode($encodedPostmanData, true);
        $this->assertSame('Create payload', $decodedPostmanData[0]['name']);
        $this->assertSame('POST', $decodedPostmanData[0]['method']);
        $this->assertStringContainsString(
            'api_key=%5BREDACTED%5D',
            $decodedPostmanData[0]['url']
        );
        $this->assertSame('{"message":"ok"}', $decodedPostmanData[0]['response']);

        Sanctum::actingAs($secondUser);
        $this->getJson('/api/admin/testsperfomed/'.$hierarchy['performedCycle']->id)
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_performed_test_cycles_support_tenant_scoped_pagination(): void
    {
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [$customer, $user] = $this->createTenant('first');
        [, $secondUser] = $this->createTenant('second');
        $hierarchy = $this->createResultParents($customer);

        $firstRun = $hierarchy['performedCycle'];
        $firstRun->forceFill([
            'date' => now()->subMinutes(2),
            'status' => 0,
        ])->save();

        $secondRun = PerformedTestCycle::forceCreate([
            'testCycleId' => $hierarchy['testCycle']->id,
            'date' => now()->subMinute(),
            'status' => 1,
            'idCostumer' => $customer->id,
        ]);
        $thirdRun = PerformedTestCycle::forceCreate([
            'testCycleId' => $hierarchy['testCycle']->id,
            'date' => now(),
            'status' => 1,
            'idCostumer' => $customer->id,
        ]);

        Sanctum::actingAs($user);

        $this->getJson(
            '/api/admin/testcyclesperfomed/'.$hierarchy['testCycle']->id.'?page=1&perPage=1&status=1&sort=date&direction=desc'
        )->assertOk()
            ->assertJsonPath('data.0.id', $thirdRun->id)
            ->assertJsonPath('meta.pagination.page', 1)
            ->assertJsonPath('meta.pagination.perPage', 1)
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.pagination.sort', 'date')
            ->assertJsonPath('meta.pagination.direction', 'desc');

        $this->getJson(
            '/api/admin/testcyclesperfomed/'.$hierarchy['testCycle']->id.'?page=2&perPage=1&status=1&sort=date&direction=desc'
        )->assertOk()
            ->assertJsonPath('data.0.id', $secondRun->id)
            ->assertJsonPath('meta.pagination.page', 2);

        Sanctum::actingAs($secondUser);
        $this->getJson(
            '/api/admin/testcyclesperfomed/'.$hierarchy['testCycle']->id.'?page=1&perPage=10'
        )->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_performed_tests_support_filtered_server_side_pagination(): void
    {
        Role::forceCreate(['id' => 3, 'name' => 'user']);
        [$customer, $user] = $this->createTenant('first');
        $hierarchy = $this->createResultParents($customer);

        $hierarchy['performedTest']->forceFill([
            'status' => 0,
            'name' => 'Browser smoke',
        ])->save();

        $apiTest = PerformedTest::forceCreate([
            'testCycleDoneId' => $hierarchy['performedCycle']->id,
            'testId' => $hierarchy['test']->id,
            'status' => 1,
            'name' => 'API regression',
            'idCostumer' => $customer->id,
        ]);
        $mobileTest = PerformedTest::forceCreate([
            'testCycleDoneId' => $hierarchy['performedCycle']->id,
            'testId' => $hierarchy['test']->id,
            'status' => 1,
            'name' => 'Mobile regression',
            'idCostumer' => $customer->id,
        ]);

        Sanctum::actingAs($user);

        $this->getJson(
            '/api/admin/testsperfomed/'.$hierarchy['performedCycle']->id.'?page=1&perPage=1&status=1&sort=name&direction=asc'
        )->assertOk()
            ->assertJsonPath('data.0.id', $apiTest->id)
            ->assertJsonPath('data.0.name', 'API regression')
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.pagination.sort', 'name')
            ->assertJsonPath('meta.pagination.direction', 'asc');

        $this->getJson(
            '/api/admin/testsperfomed/'.$hierarchy['performedCycle']->id.'?page=2&perPage=1&status=1&sort=name&direction=asc'
        )->assertOk()
            ->assertJsonPath('data.0.id', $mobileTest->id)
            ->assertJsonPath('meta.pagination.page', 2);
    }

    public function test_cli_rejects_invalid_postman_result_payloads(): void
    {
        [$customer] = $this->createTenant('first');
        $hierarchy = $this->createResultParents($customer);

        $this->withHeader('Idelium-Key', $customer->apiKey)
            ->postJson('/api/ideliumcl/step', [
                'testCycleId' => $hierarchy['performedCycle']->id,
                'testId' => $hierarchy['performedTest']->id,
                'stepId' => $hierarchy['step']->id,
                'name' => 'Postman collection',
                'status' => 1,
                'screenshots' => 'not-json',
                'data' => 'not-json',
                'type' => 'unknown',
            ])->assertUnprocessable()->assertJsonValidationErrors([
                'screenshots',
                'data',
                'type',
            ]);
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

        return [$customer, $user];
    }

    private function createResultParents(Costumer $customer): array
    {
        $project = Project::forceCreate([
            'name' => 'PROJECT '.$customer->id,
            'description' => 'Project',
            'idCostumer' => $customer->id,
        ]);
        $step = Step::forceCreate([
            'name' => 'Postman collection',
            'description' => 'Postman collection',
            'config' => json_encode([]),
            'idProject' => $project->id,
            'order' => 1,
            'idCostumer' => $customer->id,
        ]);
        $test = Test::forceCreate([
            'name' => 'API test',
            'description' => 'API test',
            'config' => json_encode([]),
            'idProject' => $project->id,
            'idCostumer' => $customer->id,
        ]);
        $testCycle = TestCycle::forceCreate([
            'name' => 'API cycle',
            'description' => 'API cycle',
            'config' => json_encode([]),
            'idProject' => $project->id,
            'idCostumer' => $customer->id,
        ]);
        $performedCycle = PerformedTestCycle::forceCreate([
            'testCycleId' => $testCycle->id,
            'date' => now(),
            'status' => 0,
            'idCostumer' => $customer->id,
        ]);
        $performedTest = PerformedTest::forceCreate([
            'testCycleDoneId' => $performedCycle->id,
            'testId' => $test->id,
            'status' => 0,
            'name' => 'API test',
            'idCostumer' => $customer->id,
        ]);

        return compact(
            'step',
            'test',
            'testCycle',
            'performedCycle',
            'performedTest'
        );
    }
}
